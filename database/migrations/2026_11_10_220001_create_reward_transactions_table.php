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
        Schema::create('reward_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('wallet_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('transaction_type', 32); // 'EARN' or 'REVERSAL'
            $table->bigInteger('points');           // Signed points change (+5 or -2)
            $table->decimal('eligible_amount', 10, 2)->default(0.00);
            $table->text('description')->nullable();
            $table->string('reference_id', 128)->unique(); // Unique event key (e.g. EARN_ORDER_1001, REVERSAL_REFUND_5)
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('wallet_id')->references('id')->on('customer_reward_wallets')->onDelete('cascade');
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('set null');

            $table->index(['customer_id', 'created_at']);
            $table->index(['order_id', 'transaction_type']);
            $table->index('transaction_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reward_transactions');
    }
};

