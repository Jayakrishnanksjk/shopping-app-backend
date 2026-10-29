<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;

return new class extends Migration
{
    /**
     * Track when (and whether) an order's inventory was decremented.
     *
     * Previously stock was decremented on EVERY payment-status update, so
     * payment-status polling (verifyPayment), duplicate webhooks and COD
     * status changes silently drained variant stock to zero even though
     * the admin still saw stock available. This column is claimed
     * atomically so each order can decrement stock at most once, and
     * cancellation/return only restocks orders that were actually
     * decremented.
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_decremented_at')->nullable()->after('payment_status');
        });

        // Backfill: orders that already reserved stock (paid online orders
        // and COD orders) are treated as decremented so future
        // cancellations restock them exactly once.
        DB::table('orders')
            ->whereNull('stock_decremented_at')
            ->where(function ($query) {
                $query->where('payment_status', PaymentStatus::COMPLETED)
                    ->orWhere('payment_method', PaymentMethod::COD);
            })
            ->update(['stock_decremented_at' => DB::raw('created_at')]);
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_decremented_at');
        });
    }
};
