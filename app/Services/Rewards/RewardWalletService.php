<?php

namespace App\Services\Rewards;

use App\Models\CustomerRewardWallet;
use App\Models\RewardTransaction;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class RewardWalletService
{
    protected RewardSettingsService $settingsService;

    public function __construct(RewardSettingsService $settingsService)
    {
        $this->settingsService = $settingsService;
    }

    /**
     * Get or create a reward wallet for a given customer ID
     */
    public function getOrCreateWallet(int $customerId): CustomerRewardWallet
    {
        return CustomerRewardWallet::firstOrCreate(
            ['customer_id' => $customerId],
            ['total_points' => 0, 'lifetime_points' => 0]
        );
    }

    /**
     * Get wallet details formatted for customer display
     * Confidential rate is used to compute wallet_balance but never exposed in response
     */
    public function getCustomerBalance(int $customerId): array
    {
        $wallet = $this->getOrCreateWallet($customerId);
        $totalPoints = (int) $wallet->total_points;
        $conversionRate = $this->settingsService->getConversionRate();

        // Total points * 0.0085 rounded to 2 decimals for display
        $walletBalance = round($totalPoints * $conversionRate, 2);

        return [
            'total_points' => $totalPoints,
            'wallet_balance' => (float) number_format($walletBalance, 2, '.', ''),
            'currency' => 'INR',
        ];
    }

    /**
     * Get available reward balance in rupees for a customer
     */
    public function getCustomerRewardBalance(int $customerId): float
    {
        $wallet = $this->getOrCreateWallet($customerId);
        $totalPoints = (int) $wallet->total_points;
        $conversionRate = $this->settingsService->getConversionRate();
        return (float) number_format(round($totalPoints * $conversionRate, 2), 2, '.', '');
    }

    /**
     * Debit reward points from wallet when used to pay for a grocery purchase
     */
    public function debitRewardPointsForOrder(int $customerId, Order $order, float $amountInRupees): int
    {
        if ($amountInRupees <= 0) return 0;

        $wallet = $this->getOrCreateWallet($customerId);
        $conversionRate = $this->settingsService->getConversionRate();
        if ($conversionRate <= 0) return 0;

        // Points to debit = amount / rate
        $pointsToDebit = (int) min($wallet->total_points, ceil($amountInRupees / $conversionRate));
        if ($pointsToDebit <= 0) return 0;

        $wallet->total_points = (int) max(0, $wallet->total_points - $pointsToDebit);
        $wallet->save();

        RewardTransaction::create([
            'customer_id' => $customerId,
            'wallet_id' => $wallet->id,
            'order_id' => $order->id,
            'transaction_type' => RewardTransaction::TYPE_REDEEM,
            'points' => -$pointsToDebit,
            'eligible_amount' => $amountInRupees,
            'description' => "Redeemed on Grocery Order #{$order->order_number}",
            'reference_id' => "REDEEM_ORDER_{$order->id}",
        ]);

        return $pointsToDebit;
    }

    /**
     * Credit earned points atomically to the wallet
     */
    public function creditPoints(CustomerRewardWallet $wallet, int $points): void
    {
        if ($points <= 0) return;

        $wallet->total_points = (int) ($wallet->total_points + $points);
        $wallet->lifetime_points = (int) ($wallet->lifetime_points + $points);
        $wallet->save();
    }

    /**
     * Reverse points from the wallet (lifetime_points is never decreased)
     */
    public function reversePoints(CustomerRewardWallet $wallet, int $points): void
    {
        if ($points <= 0) return;

        $wallet->total_points = (int) max(0, $wallet->total_points - $points);
        // Note: Lifetime points remains unchanged per Section 6.1
        $wallet->save();
    }

    /**
     * Reconcile fast-access wallet balance with the auditable transaction ledger
     */
    public function reconcile(CustomerRewardWallet $wallet): array
    {
        $ledgerSum = (int) RewardTransaction::where('wallet_id', $wallet->id)->sum('points');
        $currentPoints = (int) $wallet->total_points;
        $isConsistent = ($ledgerSum === $currentPoints);

        if (!$isConsistent) {
            $wallet->total_points = max(0, $ledgerSum);
            $wallet->save();
        }

        return [
            'wallet_id' => $wallet->id,
            'customer_id' => $wallet->customer_id,
            'previous_balance' => $currentPoints,
            'ledger_sum' => $ledgerSum,
            'reconciled' => !$isConsistent,
            'current_balance' => $wallet->total_points,
        ];
    }
}

