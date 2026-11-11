<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Order;
use App\Models\User;
use App\Models\Refund;
use App\Models\Store;
use App\Models\CustomerRewardWallet;
use App\Models\RewardTransaction;
use App\Models\RewardSetting;
use App\Services\Rewards\RewardSettingsService;
use App\Services\Rewards\RewardWalletService;
use App\Services\Rewards\RewardTransactionService;
use App\Services\Rewards\RewardPointsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class RewardPointsSystemTest extends TestCase
{
    use DatabaseTransactions;

    protected RewardPointsService $pointsService;
    protected RewardWalletService $walletService;
    protected RewardSettingsService $settingsService;
    protected RewardTransactionService $transactionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsService = app(RewardSettingsService::class);
        $this->walletService = app(RewardWalletService::class);
        $this->transactionService = app(RewardTransactionService::class);
        $this->pointsService = app(RewardPointsService::class);

        // Ensure default settings
        $this->settingsService->updateSettings([
            'rewards_enabled' => true,
            'purchase_threshold' => 100.00,
            'points_per_threshold' => 1,
            'point_conversion_value' => 0.0085,
        ]);
    }

    /**
     * Section 15.1: Purchase calculation tests
     */
    public function test_purchase_calculation_thresholds()
    {
        $testCases = [
            50 => 0,
            99 => 0,
            100 => 1,
            149 => 1,
            199 => 1,
            200 => 2,
            250 => 2,
            299 => 2,
            300 => 3,
            500 => 5,
            999 => 9,
            1000 => 10,
        ];

        foreach ($testCases as $amount => $expectedPoints) {
            $points = $this->pointsService->calculateRewardPoints((float) $amount);
            $this->assertEquals(
                $expectedPoints,
                $points,
                "Eligible amount ₹{$amount} should yield {$expectedPoints} points, got {$points}."
            );
        }
    }

    /**
     * Section 15.2: Eligible amount calculations
     */
    public function test_eligible_amount_calculation_scenarios()
    {
        // Scenario 1: Product ₹500, discount ₹50 -> ₹450 -> 4 points
        $order1 = new Order([
            'amount' => 500.00,
            'coupon_total_discount' => 50.00,
            'shipping_total' => 0.00,
            'tax_total' => 0.00,
        ]);
        $eligible1 = $this->pointsService->calculateEligibleAmount($order1);
        $this->assertEquals(450.00, $eligible1);
        $this->assertEquals(4, $this->pointsService->calculateRewardPoints($eligible1));

        // Scenario 2: Product ₹500, delivery ₹40 -> ₹500 -> 5 points
        $order2 = new Order([
            'amount' => 500.00,
            'coupon_total_discount' => 0.00,
            'shipping_total' => 40.00,
            'tax_total' => 0.00,
        ]);
        $eligible2 = $this->pointsService->calculateEligibleAmount($order2);
        $this->assertEquals(500.00, $eligible2);
        $this->assertEquals(5, $this->pointsService->calculateRewardPoints($eligible2));

        // Scenario 3: Product ₹500, tax ₹25 -> ₹500 -> 5 points
        $order3 = new Order([
            'amount' => 500.00,
            'coupon_total_discount' => 0.00,
            'shipping_total' => 0.00,
            'tax_total' => 25.00,
        ]);
        $eligible3 = $this->pointsService->calculateEligibleAmount($order3);
        $this->assertEquals(500.00, $eligible3);
        $this->assertEquals(5, $this->pointsService->calculateRewardPoints($eligible3));

        // Scenario 4: Product ₹500, discount ₹100, delivery ₹40 -> ₹400 -> 4 points
        $order4 = new Order([
            'amount' => 500.00,
            'coupon_total_discount' => 100.00,
            'shipping_total' => 40.00,
            'tax_total' => 20.00,
        ]);
        $eligible4 = $this->pointsService->calculateEligibleAmount($order4);
        $this->assertEquals(400.00, $eligible4);
        $this->assertEquals(4, $this->pointsService->calculateRewardPoints($eligible4));
    }

    /**
     * Section 15.4: Wallet conversion tests (points * 0.0085)
     */
    public function test_internal_conversion_wallet_values()
    {
        $testCases = [
            100 => 0.85,
            500 => 4.25,
            1000 => 8.50,
            1250 => 10.63,
            2000 => 17.00,
            5000 => 42.50,
            10000 => 85.00,
        ];

        foreach ($testCases as $pts => $expectedRupees) {
            $wallet = new CustomerRewardWallet([
                'total_points' => $pts,
            ]);
            $calc = round($pts * 0.0085, 2);
            $this->assertEquals(
                $expectedRupees,
                $calc,
                "{$pts} points should equal ₹{$expectedRupees}, got ₹{$calc}."
            );
        }
    }

    /**
     * Section 15.3: Order delivery award and duplicate prevention
     */
    public function test_order_delivery_awards_points_and_prevents_duplicate_credit()
    {
        $user = User::first() ?? User::factory()->create();

        $order = Order::create([
            'order_number' => rand(100000, 999999),
            'consumer_id' => $user->id,
            'amount' => 550.00,
            'coupon_total_discount' => 50.00, // Eligible = ₹500 -> 5 points
            'shipping_total' => 40.00,
            'tax_total' => 10.00,
            'total' => 550.00,
        ]);

        // Award points
        $tx = $this->pointsService->awardPointsForOrder($order);
        $this->assertNotNull($tx);
        $this->assertEquals(5, $tx->points);
        $this->assertEquals('EARN', $tx->transaction_type);
        $this->assertEquals(500.00, $tx->eligible_amount);

        // Check wallet
        $wallet = CustomerRewardWallet::where('customer_id', $user->id)->first();
        $this->assertNotNull($wallet);
        $this->assertGreaterThanOrEqual(5, $wallet->total_points);

        // Repeated DELIVERED event must NEVER duplicate points (idempotency check)
        $tx2 = $this->pointsService->awardPointsForOrder($order);
        $this->assertEquals($tx->id, $tx2->id);

        $walletAfter = CustomerRewardWallet::where('customer_id', $user->id)->first();
        $this->assertEquals($wallet->total_points, $walletAfter->total_points);
    }

    /**
     * Section 7 & 15.3: Partial and full refund point adjustments
     */
    public function test_refund_reversals_adjust_points_correctly()
    {
        $user = User::first() ?? User::factory()->create();

        $order = Order::create([
            'order_number' => rand(100000, 999999),
            'consumer_id' => $user->id,
            'amount' => 500.00,
            'coupon_total_discount' => 0.00, // Eligible = ₹500 -> 5 points
            'total' => 500.00,
        ]);

        // Initial award: 5 points
        $this->pointsService->awardPointsForOrder($order);
        $initialWallet = CustomerRewardWallet::where('customer_id', $user->id)->first();
        $initialPoints = $initialWallet->total_points;

        // Partial Refund 1: ₹200 refunded. Remaining eligible = ₹300 -> Entitlement: 3 points -> Reverse: 2 points
        $refund1 = Refund::create([
            'order_id' => $order->id,
            'consumer_id' => $user->id,
            'amount' => 200.00,
            'status' => 'approved',
            'reason' => 'Damaged item',
        ]);

        $revTx1 = $this->pointsService->handleRefundReversal($order, $refund1);
        $this->assertNotNull($revTx1);
        $this->assertEquals(-2, $revTx1->points);

        $walletAfterRefund1 = CustomerRewardWallet::where('customer_id', $user->id)->first();
        $this->assertEquals($initialPoints - 2, $walletAfterRefund1->total_points);

        // Partial Refund 2: Additional ₹250 refunded. Cumulative refunded = ₹450 -> Remaining eligible = ₹50 -> Entitlement: 0 points
        // Previously retained: 3 points. New entitlement: 0 points. Reverse: 3 points.
        $refund2 = Refund::create([
            'order_id' => $order->id,
            'consumer_id' => $user->id,
            'amount' => 250.00,
            'status' => 'approved',
            'reason' => 'Wrong item',
        ]);

        $revTx2 = $this->pointsService->handleRefundReversal($order, $refund2);
        $this->assertNotNull($revTx2);
        $this->assertEquals(-3, $revTx2->points);

        $walletAfterRefund2 = CustomerRewardWallet::where('customer_id', $user->id)->first();
        $this->assertEquals($initialPoints - 5, $walletAfterRefund2->total_points);
    }

    /**
     * Requirement: Reward points are strictly exclusive to Grocery Store orders.
     * Foodzone (store_id = 4 or 1) and Jewellery (store_id = 5 or 2) must never earn points.
     */
    public function test_non_grocery_orders_do_not_earn_points()
    {
        $user = User::first() ?? User::factory()->create();

        $foodStore = Store::firstOrCreate(['id' => 4], [
            'store_name' => 'Food Zone',
            'slug' => 'foodzone',
        ]);

        // 1. Foodzone order (store_id = 4)
        $foodOrder = Order::create([
            'order_number' => rand(100000, 999999),
            'consumer_id' => $user->id,
            'store_id' => $foodStore->id,
            'amount' => 1000.00,
            'total' => 1000.00,
        ]);

        $this->assertFalse($this->pointsService->isGroceryOrder($foodOrder));
        $foodTx = $this->pointsService->awardPointsForOrder($foodOrder);
        $this->assertNull($foodTx);

        $foodDetails = $this->pointsService->getOrderRewardDetails($foodOrder);
        $this->assertFalse($foodDetails['is_eligible_store']);
        $this->assertEquals(0, $foodDetails['points_earned']);

        $jewelStore = Store::firstOrCreate(['id' => 5], [
            'store_name' => 'Mather Jewellery',
            'slug' => 'jewellery',
        ]);

        // 2. Jewellery order (store_id = 5)
        $jewelOrder = Order::create([
            'order_number' => rand(100000, 999999),
            'consumer_id' => $user->id,
            'store_id' => $jewelStore->id,
            'amount' => 50000.00,
            'total' => 50000.00,
        ]);

        $this->assertFalse($this->pointsService->isGroceryOrder($jewelOrder));
        $jewelTx = $this->pointsService->awardPointsForOrder($jewelOrder);
        $this->assertNull($jewelTx);

        $jewelDetails = $this->pointsService->getOrderRewardDetails($jewelOrder);
        $this->assertFalse($jewelDetails['is_eligible_store']);
        $this->assertEquals(0, $jewelDetails['points_earned']);
    }
}
