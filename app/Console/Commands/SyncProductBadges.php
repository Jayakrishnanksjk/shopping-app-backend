<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\TrendingProduct;
use App\Models\BestsellingProduct;
use Carbon\Carbon;
use App\Enums\PaymentStatus;

class SyncProductBadges extends Command
{
    protected $signature = 'products:sync-badges {--limit=10 : Top N per badge} {--trending-window=7 : Days for trending} {--dry-run : Preview without writing}';
    protected $description = 'Auto-populate trending (7d velocity) and bestselling (lifetime) badges from completed orders - safe for production (transactional, idempotent)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $window = (int) $this->option('trending-window');
        $dryRun = (bool) $this->option('dry-run');

        $since = Carbon::now()->subDays($window);

        // Bestselling: lifetime completed orders
        $bestSelling = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.payment_status', PaymentStatus::COMPLETED)
            ->whereNull('orders.deleted_at')
            ->whereNull('order_products.deleted_at')
            ->select('order_products.product_id', DB::raw('SUM(order_products.quantity) as total_qty'))
            ->groupBy('order_products.product_id')
            ->having('total_qty', '>', 0)
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        // Trending: last 7d completed orders
        $trending = DB::table('order_products')
            ->join('orders', 'orders.id', '=', 'order_products.order_id')
            ->where('orders.payment_status', PaymentStatus::COMPLETED)
            ->where('orders.created_at', '>=', $since)
            ->whereNull('orders.deleted_at')
            ->whereNull('order_products.deleted_at')
            ->select('order_products.product_id', DB::raw('SUM(order_products.quantity) as recent_qty'))
            ->groupBy('order_products.product_id')
            ->having('recent_qty', '>', 0)
            ->orderByDesc('recent_qty')
            ->limit($limit)
            ->get();

        $this->info("Bestselling candidates: ".$bestSelling->count());
        $this->info("Trending candidates (last {$window}d): ".$trending->count());

        if ($dryRun) {
            $this->table(['badge','product_id','qty'], collect($bestSelling)->map(fn($r)=>['bestselling',$r->product_id,$r->total_qty])->merge(collect($trending)->map(fn($r)=>['trending',$r->product_id,$r->recent_qty])->toArray()));
            return self::SUCCESS;
        }

        DB::transaction(function () use ($bestSelling, $trending) {
            // Use truncate+insert inside transaction - no partial state if fails
            // Keep auto-increment stable by delete instead of truncate if FK strict
            BestsellingProduct::query()->delete();
            TrendingProduct::query()->delete();

            foreach ($bestSelling as $i => $row) {
                BestsellingProduct::create(['product_id' => $row->product_id, 'order' => $i + 1]);
            }
            foreach ($trending as $i => $row) {
                TrendingProduct::create(['product_id' => $row->product_id, 'order' => $i + 1]);
            }
        });

        $this->info('Synced. Product::is_trending / bestselling accessors will now reflect automated badges.');
        return self::SUCCESS;
    }
}
