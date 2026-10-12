<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('cancellations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->enum('scope', ['full', 'partial'])->nullable();
            $table->json('items')->nullable();
            $table->text('reason')->nullable();
            $table->enum('status', ['none', 'requested', 'cancelled'])->default('none');
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('cancellations');
    }
};