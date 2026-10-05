<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staging table for product updates received from the Pearl XP integration.
     * Rows are created with status = pending, applied to `products` only after
     * an admin approves them, then kept as an audit trail.
     */
    public function up(): void
    {
        Schema::create('pearl_xp_product_updates', function (Blueprint $table) {
            $table->id();

            // What Pearl XP sent
            $table->string('barcode')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();

            // Snapshot of products.* at the moment the update arrived
            $table->decimal('old_mrp', 8, 2)->nullable();
            $table->decimal('old_price', 8, 2)->nullable();
            $table->integer('old_stock')->nullable();

            // Proposed values (only the fields Pearl XP actually sent)
            $table->decimal('new_mrp', 8, 2)->nullable();
            $table->decimal('new_price', 8, 2)->nullable();
            $table->integer('new_stock')->nullable();

            $table->string('status')->default('pending')->index();
            $table->text('error')->nullable();
            $table->json('payload')->nullable();

            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pearl_xp_product_updates');
    }
};
