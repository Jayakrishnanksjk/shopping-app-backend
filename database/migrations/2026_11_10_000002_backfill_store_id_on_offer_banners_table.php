<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backfill store_id for offer banners that already existed before the
     * store scoping column was introduced.
     */
    public function up(): void
    {
        if (! Schema::hasTable('offer_banners') || ! Schema::hasColumn('offer_banners', 'store_id')) {
            return;
        }

        try {
            $banners = DB::table('offer_banners')->whereNull('store_id')->get();

            foreach ($banners as $banner) {
                $storeId = null;

                if ($banner->redirect_type === 'product' && $banner->redirect_id && Schema::hasTable('products')) {
                    $storeId = DB::table('products')->where('id', $banner->redirect_id)->value('store_id');
                }

                if (! $storeId && $banner->redirect_type === 'category' && $banner->redirect_id && Schema::hasTable('categories')) {
                    $storeId = DB::table('categories')->where('id', $banner->redirect_id)->value('store_id');
                }

                if ($storeId) {
                    DB::table('offer_banners')->where('id', $banner->id)->update([
                        'store_id' => $storeId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Offer banner store_id backfill warning: '.$e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfilled data is intentionally left untouched.
    }
};
