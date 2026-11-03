<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'is_category_top')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_category_top')->default(0)->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', 'is_category_top')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex(['is_category_top']);
                $table->dropColumn('is_category_top');
            });
        }
    }
};

