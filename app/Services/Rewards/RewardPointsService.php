<?php

namespace App\Services\Rewards;

use App\Models\CustomerRewardWallet;
use App\Models\Order;
use App\Models\Refund;
use App\Models\RewardTransaction;
use App\Enums\OrderEnum;
use App\Enums\RequestEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RewardPointsService
{
    protected RewardSettingsService $settingsService;
    protected RewardWalletService $walletService;
    protected RewardTransactionService $transactionService;

    public function __construct(
        RewardSettingsService $settingsService,
        RewardWalletService $walletService,
        RewardTransactionService $transactionService
    ) {
        $this->settingsService = $settingsService;
        $this->walletService = $walletService;
        $this->transactionService = $transactionService;
    }

    /**
     * Calculate eligible purchase amount for an order:
     * Product subtotal after item-level & order-level product discounts.
     * Excludes taxes, delivery charges, and handling fees.
     */
    public function calculateEligibleAmount(Order $order): float
    {
        $subtotal = (float) ($order->amount ?? 0);
        $couponDiscount = (float) ($order->coupon_total_discount ?? 0);

        return (float) max(0, round($subtotal - $couponDiscount, 2));
    }

    /**
     * Calculate reward points using floor() per Section 3.2
     */
    public function calculateRewardPoints(float $eligibleAmount): int
    {
        if ($eligibleAmount <= 0) {
            return 0;
        }

        $threshold = $this->settingsService->getThreshold();
        if ($threshold <= 0) {
            $threshold = 100.00;
        }

        $pointsPerThreshold = $this->settingsService->getPointsPerThreshold();

        return (int) (floor($eligibleAmount / $threshold) * $pointsPerThreshold);
    }

    /**
     * Award points for a delivered order.
     * Atomic, idempotent, and protected against concurrency.
     */
    public function awardPointsForOrder(Order $order): ?RewardTransaction
    {
        // 1. Must be enabled
        if (!$this->settingsService->isRewardsEnabled()) {
            return null;
        }

        // 2. Must belong to a registered customer
        if (!$order->consumer_id) {
            return null;
        }

        $referenceId = "EARN_ORDER_{$order->id}";

        return DB::transaction(function () use ($order, $referenceId) {
            // Lock customer wallet row to prevent concurrent race conditions
            $wallet = CustomerRewardWallet::where('customer_id', $order->consumer_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = $this->walletService->getOrCreateWallet($order->consumer_id);
            }

            // 3. Verify order has not already received reward points
            if ($this->transactionService->hasBeenRewarded($order->id)) {
                return $this->transactionService->getEarnTransaction($order->id);
            }

            // 4. Retrieve final eligible product amount
            $eligibleAmount = $this->calculateEligibleAmount($order);

            // 5. Calculate reward points
            $points = $this->calculateRewardPoints($eligibleAmount);

            // 6. If calculated reward is zero, do not create transaction
            if ($points <= 0) {
                return null;
            }

            $orderNumber = $order->order_number ?? $order->id;
            $description = "Purchase reward for Order #{$orderNumber}";

            // 7. Insert reward transaction
            $tx = $this->transactionService->createEarnTransaction(
                $wallet,
                $order,
                $points,
                $eligibleAmount,
                $description,
                $referenceId
            );

            // 8. Update customer wallet points balance
            $this->walletService->creditPoints($wallet, $points);

            Log::info("Reward points awarded: {$points} points credited to Customer #{$order->consumer_id} for Order #{$orderNumber}.");

            return $tx;
        });
    }

    /**
     * Process points reversal on full or partial refund
     */
    public function handleRefundReversal(Order $order, Refund $refund): ?RewardTransaction
    {
        // Must belong to a registered customer and have originally earned points
        if (!$order->consumer_id) {
            return null;
        }

        $earnTx = $this->transactionService->getEarnTransaction($order->id);
        if (!$earnTx || $earnTx->points <= 0) {
            return null; // Order never earned points, nothing to reverse
        }

        $referenceId = "REVERSAL_REFUND_{$refund->id}";

        // Idempotency: verify this refund has not already been processed for reversal
        if (RewardTransaction::where('reference_id', $referenceId)->exists()) {
            return RewardTransaction::where('reference_id', $referenceId)->first();
        }

        return DB::transaction(function () use ($order, $refund, $earnTx, $referenceId) {
            $wallet = CustomerRewardWallet::where('customer_id', $order->consumer_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = $this->walletService->getOrCreateWallet($order->consumer_id);
            }

            $originalEligible = (float) $earnTx->eligible_amount;
            $originalPoints = (int) $earnTx->points;

            // Calculate cumulative approved refund amount on this order
            $totalRefundedAmount = (float) Refund::where('order_id', $order->id)
                ->where('status', RequestEnum::APPROVED)
                ->sum('amount');

            // Remaining eligible amount
            $remainingEligible = max(0, $originalEligible - $totalRefundedAmount);

            // Correct new points entitlement
            $newEntitlement = $this->calculateRewardPoints($remainingEligible);

            // Points previously reversed for this order
            $alreadyReversed = $this->transactionService->getTotalReversedPoints($order->id);

            // Points currently retained by customer for this order
            $currentlyRetained = max(0, $originalPoints - $alreadyReversed);

            // Points to reverse now
            $pointsToReverse = max(0, $currentlyRetained - $newEntitlement);

            if ($pointsToReverse <= 0) {
                return null;
            }

            $orderNumber = $order->order_number ?? $order->id;
            $description = "Points reversal for refunded Order #{$orderNumber}";

            $tx = $this->transactionService->createReversalTransaction(
                $wallet,
                $order,
                $pointsToReverse,
                (float) $refund->amount,
                $description,
                $referenceId
            );

            $this->walletService->reversePoints($wallet, $pointsToReverse);

            Log::info("Reward points reversed: -{$pointsToReverse} points for Order #{$orderNumber} (Refund #{$refund->id}).");

            return $tx;
        });
    }

    /**
     * Get reward summary for an order (for order details screens and API)
     */
    public function getOrderRewardDetails(Order $order): array
    {
        $eligibleAmount = $this->calculateEligibleAmount($order);
        $earnTx = $this->transactionService->getEarnTransaction($order->id);
        $reversedPoints = $this->transactionService->getTotalReversedPoints($order->id);

        $isDelivered = ($order->order_status && $order->order_status->name === OrderEnum::DELIVERED);

        $pointsEarned = $earnTx ? (int) $earnTx->points : ($isDelivered ? $this->calculateRewardPoints($eligibleAmount) : $this->calculateRewardPoints($eligibleAmount));
        $netPoints = max(0, $pointsEarned - $reversedPoints);

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number ?? $order->id,
            'eligible_product_amount' => $eligibleAmount,
            'points_earned' => $pointsEarned,
            'points_reversed' => $reversedPoints,
            'net_points' => $netPoints,
            'is_credited' => (bool) $earnTx,
            'status' => $earnTx ? 'Credited' : ($isDelivered ? 'Pending Credit' : 'Credited after delivery'),
        ];
    }
}
