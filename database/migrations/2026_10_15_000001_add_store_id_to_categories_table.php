<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('categories', 'store_id')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('store_id')->nullable()->after('parent_id');
                $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            });
        }

        // Backfill existing categories safely without losing any data
        try {
            $stores = DB::table('stores')->get();
            $defaultStoreId = $stores->first()?->id;

            $categories = DB::table('categories')->whereNull('store_id')->get();
            foreach ($categories as $cat) {
                $storeId = null;

                // 1. Check if category already has products linked with a store_id
                if (Schema::hasTable('product_categories') && Schema::hasTable('products')) {
                    $storeId = DB::table('product_categories')
                        ->join('products', 'product_categories.product_id', '=', 'products.id')
                        ->where('product_categories.category_id', $cat->id)
                        ->whereNotNull('products.store_id')
                        ->value('products.store_id');
                }

                // 2. Check if category name or slug contains store name or slug
                if (!$storeId && !empty($stores)) {
                    foreach ($stores as $s) {
                        if (($s->slug && str_contains($cat->slug, $s->slug)) ||
                            ($s->store_name && stripos($cat->name, $s->store_name) !== false)) {
                            $storeId = $s->id;
                            break;
                        }
                    }
                }

                // 3. Fallback to default store if still unassigned
                if (!$storeId && $defaultStoreId) {
                    $storeId = $defaultStoreId;
                }

                if ($storeId) {
                    DB::table('categories')->where('id', $cat->id)->update([
                        'store_id' => $storeId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Log backfill exception without failing the schema migration
            \Illuminate\Support\Facades\Log::warning('Category store_id backfill warning: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('categories', 'store_id')) {
            Schema::table('categories', function (Blueprint $table) {
                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign(['store_id']);
                }
                $table->dropColumn('store_id');
            });
        }
    }
};
