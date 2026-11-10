<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('coupons', 'store_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->nullable()->after('created_by_id');
                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            });
        }

        // Backfill existing coupons without losing any data. Only assign a
        // store when we can infer it reliably; otherwise leave it as a
        // deliberate global (NULL) coupon.
        try {
            $coupons = DB::table('coupons')->whereNull('store_id')->get();

            foreach ($coupons as $coupon) {
                $storeId = null;

                // 1. Coupon created by a vendor -> that vendor's store.
                if ($coupon->created_by_id) {
                    $storeId = DB::table('stores')
                        ->where('vendor_id', $coupon->created_by_id)
                        ->value('id');
                }

                // 2. Coupon restricted to products -> use their store when
                //    all linked products belong to the same store.
                if (! $storeId && Schema::hasTable('product_coupons') && Schema::hasTable('products')) {
                    $storeIds = DB::table('product_coupons')
                        ->join('products', 'product_coupons.product_id', '=', 'products.id')
                        ->where('product_coupons.coupon_id', $coupon->id)
                        ->whereNotNull('products.store_id')
                        ->distinct()
                        ->pluck('products.store_id');

                    if ($storeIds->count() === 1) {
                        $storeId = $storeIds->first();
                    }
                }

                // 3. Excluded-product coupons -> same inference as above.
                if (! $storeId && Schema::hasTable('exclude_product_coupons') && Schema::hasTable('products')) {
                    $storeIds = DB::table('exclude_product_coupons')
                        ->join('products', 'exclude_product_coupons.product_id', '=', 'products.id')
                        ->where('exclude_product_coupons.coupon_id', $coupon->id)
                        ->whereNotNull('products.store_id')
                        ->distinct()
                        ->pluck('products.store_id');

                    if ($storeIds->count() === 1) {
                        $storeId = $storeIds->first();
                    }
                }

                if ($storeId) {
                    DB::table('coupons')->where('id', $coupon->id)->update([
                        'store_id' => $storeId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Coupon store_id backfill warning: '.$e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('coupons', 'store_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['store_id']);
                }
                $table->dropColumn('store_id');
            });
        }
    }
};
