<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\Cancellation;
use App\Enums\OrderEnum;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Helpers\Helpers;
use App\Events\UpdateOrderStatusEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CancelUnpaidOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cancel-unpaid {--minutes=15 : Minutes after which uncompleted online checkouts are cancelled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically cancel abandoned online orders whose payment was never completed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $cutoff = Carbon::now()->subMinutes($minutes);

        $pendingStatusId = Helpers::getOrderStatusIdByName(OrderEnum::PENDING);
        $cancelledStatusId = Helpers::getOrderStatusIdByName(OrderEnum::CANCELLED);

        if (!$pendingStatusId || !$cancelledStatusId) {
            $this->error('Order statuses pending or cancelled not found in database.');
            return 1;
        }

        $unpaidOrders = Order::whereNull('parent_id')
            ->whereRaw('LOWER(payment_method) != ?', [strtolower(PaymentMethod::COD)])
            ->where(function ($q) {
                $q->whereNull('payment_status')
                  ->orWhere('payment_status', PaymentStatus::PENDING);
            })
            ->where('order_status_id', $pendingStatusId)
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($unpaidOrders->isEmpty()) {
            $this->info("No abandoned online orders older than {$minutes} minutes found.");
            return 0;
        }

        $count = 0;
        foreach ($unpaidOrders as $order) {
            $reason = "Payment timeout: abandoned online checkout after {$minutes} minutes";

            // Record cancellation audit entry
            Cancellation::firstOrCreate(
                ['order_id' => $order->id],
                [
                    'scope' => 'full',
                    'reason' => $reason,
                    'status' => 'cancelled',
                ]
            );

            // Update status to cancelled
            $order->order_status_id = $cancelledStatusId;
            $order->save();

            // Cancel any sub-orders
            Order::where('parent_id', $order->id)->update([
                'order_status_id' => $cancelledStatusId,
            ]);

            // Restock if stock was decremented (Helpers guards this with stock_decremented_at)
            Helpers::restockProductStock($order);

            event(new UpdateOrderStatusEvent($order));

            $count++;
        }

        $this->info("Successfully cancelled {$count} abandoned online orders.");
        Log::info("orders:cancel-unpaid: Cancelled {$count} abandoned online orders older than {$minutes} minutes.");

        return 0;
    }
}
