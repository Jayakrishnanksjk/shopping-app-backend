<?php

namespace App\Services\Rewards;

use App\Models\CustomerRewardWallet;
use App\Models\Order;
use App\Models\RewardTransaction;

class RewardTransactionService
{
    /**
     * Check if an order has already received an EARN transaction
     */
    public function hasBeenRewarded(int $orderId): bool
    {
        return RewardTransaction::where('order_id', $orderId)
            ->where('transaction_type', RewardTransaction::TYPE_EARN)
            ->exists();
    }

    /**
     * Retrieve the earning transaction for an order
     */
    public function getEarnTransaction(int $orderId): ?RewardTransaction
    {
        return RewardTransaction::where('order_id', $orderId)
            ->where('transaction_type', RewardTransaction::TYPE_EARN)
            ->first();
    }

    /**
     * Retrieve all reversal transactions for an order
     */
    public function getReversalTransactions(int $orderId)
    {
        return RewardTransaction::where('order_id', $orderId)
            ->where('transaction_type', RewardTransaction::TYPE_REVERSAL)
            ->get();
    }

    /**
     * Sum of points already reversed for an order
     */
    public function getTotalReversedPoints(int $orderId): int
    {
        // Points on reversals are stored as negative numbers e.g. -2, so abs() sum
        $sum = RewardTransaction::where('order_id', $orderId)
            ->where('transaction_type', RewardTransaction::TYPE_REVERSAL)
            ->sum('points');

        return abs((int) $sum);
    }

    /**
     * Create an EARN reward transaction
     */
    public function createEarnTransaction(
        CustomerRewardWallet $wallet,
        Order $order,
        int $points,
        float $eligibleAmount,
        string $description,
        string $referenceId
    ): RewardTransaction {
        return RewardTransaction::create([
            'customer_id' => $wallet->customer_id,
            'wallet_id' => $wallet->id,
            'order_id' => $order->id,
            'transaction_type' => RewardTransaction::TYPE_EARN,
            'points' => $points, // positive
            'eligible_amount' => $eligibleAmount,
            'description' => $description,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * Create a REVERSAL reward transaction
     */
    public function createReversalTransaction(
        CustomerRewardWallet $wallet,
        Order $order,
        int $pointsToReverse,
        float $refundedAmount,
        string $description,
        string $referenceId
    ): RewardTransaction {
        return RewardTransaction::create([
            'customer_id' => $wallet->customer_id,
            'wallet_id' => $wallet->id,
            'order_id' => $order->id,
            'transaction_type' => RewardTransaction::TYPE_REVERSAL,
            'points' => -abs($pointsToReverse), // negative signed points
            'eligible_amount' => $refundedAmount,
            'description' => $description,
            'reference_id' => $referenceId,
        ]);
    }
}
