<?php

namespace App\Http\Controllers;

use App\Models\CustomerRewardWallet;
use App\Models\Order;
use App\Models\RewardTransaction;
use App\Models\User;
use App\Services\Rewards\RewardPointsService;
use App\Services\Rewards\RewardSettingsService;
use App\Services\Rewards\RewardWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRewardController extends Controller
{
    protected RewardSettingsService $settingsService;
    protected RewardWalletService $walletService;
    protected RewardPointsService $pointsService;

    public function __construct(
        RewardSettingsService $settingsService,
        RewardWalletService $walletService,
        RewardPointsService $pointsService
    ) {
        $this->settingsService = $settingsService;
        $this->walletService = $walletService;
        $this->pointsService = $pointsService;
    }

    /**
     * GET /api/admin/rewards/overview
     * High-level metrics for reward management
     */
    public function overview(): JsonResponse
    {
        $enrolledCustomers = CustomerRewardWallet::count();

        $pointsIssued = (int) RewardTransaction::where('transaction_type', RewardTransaction::TYPE_EARN)->sum('points');
        $outstandingPoints = (int) CustomerRewardWallet::sum('total_points');
        $pointsReversed = abs((int) RewardTransaction::where('transaction_type', RewardTransaction::TYPE_REVERSAL)->sum('points'));

        $conversionRate = $this->settingsService->getConversionRate();
        $outstandingRupeeValue = (float) number_format(round($outstandingPoints * $conversionRate, 2), 2, '.', '');

        return response()->json([
            'success' => true,
            'data' => [
                'enrolled_customers' => $enrolledCustomers,
                'points_issued' => $pointsIssued,
                'outstanding_points' => $outstandingPoints,
                'points_reversed' => $pointsReversed,
                'outstanding_rupee_value' => $outstandingRupeeValue,
                'conversion_rate' => $conversionRate,
            ]
        ]);
    }

    /**
     * GET /api/admin/rewards/customers
     * Search customer rewards
     */
    public function customers(Request $request): JsonResponse
    {
        $paginate = (int) ($request->paginate ?? 15);
        $search = $request->search ?? null;
        $conversionRate = $this->settingsService->getConversionRate();

        $query = CustomerRewardWallet::with('customer:id,name,email,phone');

        if ($search) {
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        $wallets = $query->orderBy('total_points', 'desc')->paginate($paginate);

        $formatted = $wallets->getCollection()->map(function ($w) use ($conversionRate) {
            $totalPoints = (int) $w->total_points;
            return [
                'id' => $w->id,
                'customer_id' => $w->customer_id,
                'customer_name' => $w->customer->name ?? 'Guest/Unknown',
                'customer_email' => $w->customer->email ?? '',
                'customer_phone' => $w->customer->phone ?? '',
                'total_points' => $totalPoints,
                'lifetime_points' => (int) $w->lifetime_points,
                'wallet_balance' => (float) number_format(round($totalPoints * $conversionRate, 2), 2, '.', ''),
                'created_at' => $w->created_at ? $w->created_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'total' => $wallets->total(),
            'current_page' => $wallets->currentPage(),
            'last_page' => $wallets->lastPage(),
        ]);
    }

    /**
     * GET /api/admin/rewards/customers/{id}
     * Retrieve single customer reward details & recent transactions
     */
    public function customerDetails($id): JsonResponse
    {
        $wallet = CustomerRewardWallet::with('customer:id,name,email,phone')->where('customer_id', $id)->first();
        if (!$wallet) {
            $customer = User::find($id);
            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
            }
            $wallet = $this->walletService->getOrCreateWallet($id);
            $wallet->setRelation('customer', $customer);
        }

        $conversionRate = $this->settingsService->getConversionRate();
        $totalPoints = (int) $wallet->total_points;

        $recentTransactions = RewardTransaction::with('order:id,order_number')
            ->where('customer_id', $id)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'order_id' => $tx->order ? ($tx->order->order_number ?? $tx->order->id) : $tx->order_id,
                    'transaction_type' => $tx->transaction_type,
                    'points' => (int) $tx->points,
                    'eligible_amount' => (float) $tx->eligible_amount,
                    'description' => $tx->description,
                    'reference_id' => $tx->reference_id,
                    'created_at' => $tx->created_at ? $tx->created_at->toIso8601String() : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'customer_id' => $wallet->customer_id,
                'customer_name' => $wallet->customer->name ?? '',
                'customer_email' => $wallet->customer->email ?? '',
                'customer_phone' => $wallet->customer->phone ?? '',
                'total_points' => $totalPoints,
                'lifetime_points' => (int) $wallet->lifetime_points,
                'wallet_balance' => (float) number_format(round($totalPoints * $conversionRate, 2), 2, '.', ''),
                'transactions' => $recentTransactions,
            ]
        ]);
    }

    /**
     * GET /api/admin/rewards/transactions
     * Transaction ledger listing with filters
     */
    public function transactions(Request $request): JsonResponse
    {
        $paginate = (int) ($request->paginate ?? 15);
        $query = RewardTransaction::with(['customer:id,name,email,phone', 'order:id,order_number']);

        if ($request->transaction_type && in_array(strtoupper($request->transaction_type), [RewardTransaction::TYPE_EARN, RewardTransaction::TYPE_REVERSAL])) {
            $query->where('transaction_type', strtoupper($request->transaction_type));
        }

        if ($request->order_id) {
            $term = $request->order_id;
            $query->where(function ($q) use ($term) {
                $q->where('order_id', $term)
                  ->orWhereHas('order', function ($oq) use ($term) {
                      $oq->where('order_number', 'like', "%{$term}%");
                  });
            });
        }

        if ($request->customer) {
            $customerTerm = $request->customer;
            $query->whereHas('customer', function ($cq) use ($customerTerm) {
                $cq->where('name', 'like', "%{$customerTerm}%")
                   ->orWhere('phone', 'like', "%{$customerTerm}%")
                   ->orWhere('id', $customerTerm);
            });
        }

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
        }

        if ($request->sort_points && in_array(strtolower($request->sort_points), ['asc', 'desc'])) {
            $query->orderBy('points', strtolower($request->sort_points));
        } else {
            $query->orderBy('id', 'desc');
        }

        $list = $query->paginate($paginate);

        $formatted = $list->getCollection()->map(function ($tx) {
            return [
                'id' => $tx->id,
                'customer_id' => $tx->customer_id,
                'customer_name' => $tx->customer->name ?? 'Guest',
                'customer_phone' => $tx->customer->phone ?? '',
                'order_id' => $tx->order ? ($tx->order->order_number ?? $tx->order->id) : $tx->order_id,
                'transaction_type' => $tx->transaction_type,
                'points' => (int) $tx->points,
                'eligible_amount' => (float) $tx->eligible_amount,
                'description' => $tx->description,
                'reference_id' => $tx->reference_id,
                'created_at' => $tx->created_at ? $tx->created_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'total' => $list->total(),
            'current_page' => $list->currentPage(),
            'last_page' => $list->lastPage(),
        ]);
    }

    /**
     * GET /api/admin/rewards/settings
     * Retrieve reward program settings
     */
    public function getSettings(): JsonResponse
    {
        $settings = $this->settingsService->getSettings();

        return response()->json([
            'success' => true,
            'data' => [
                'rewards_enabled' => (bool) $settings->rewards_enabled,
                'purchase_threshold' => (float) $settings->purchase_threshold,
                'points_per_threshold' => (int) $settings->points_per_threshold,
                'point_conversion_value' => (float) $settings->point_conversion_value,
            ]
        ]);
    }

    /**
     * PUT /api/admin/rewards/settings
     * Update reward program settings
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rewards_enabled' => 'nullable|boolean',
            'purchase_threshold' => 'nullable|numeric|min:1',
            'points_per_threshold' => 'nullable|integer|min:1',
            'point_conversion_value' => 'nullable|numeric|min:0.0001',
        ]);

        $settings = $this->settingsService->updateSettings($validated);

        return response()->json([
            'success' => true,
            'message' => 'Reward settings updated successfully',
            'data' => [
                'rewards_enabled' => (bool) $settings->rewards_enabled,
                'purchase_threshold' => (float) $settings->purchase_threshold,
                'points_per_threshold' => (int) $settings->points_per_threshold,
                'point_conversion_value' => (float) $settings->point_conversion_value,
            ]
        ]);
    }

    /**
     * GET /api/admin/rewards/export
     * Export reward transaction ledger to CSV
     */
    public function export(Request $request): StreamedResponse
    {
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="reward_transactions_' . date('Y-m-d_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return new StreamedResponse(function () use ($request) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Transaction ID',
                'Date (IST)',
                'Customer ID',
                'Customer Name',
                'Customer Phone',
                'Order Number',
                'Type',
                'Points',
                'Eligible Amount (INR)',
                'Description',
                'Reference ID'
            ]);

            $query = RewardTransaction::with(['customer:id,name,phone', 'order:id,order_number'])->orderBy('id', 'desc');

            if ($request->transaction_type) {
                $query->where('transaction_type', strtoupper($request->transaction_type));
            }

            if ($request->start_date && $request->end_date) {
                $query->whereBetween('created_at', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
            }

            $query->chunk(250, function ($rows) use ($handle) {
                foreach ($rows as $tx) {
                    $orderNum = $tx->order ? ($tx->order->order_number ?? $tx->order->id) : ($tx->order_id ?? 'N/A');
                    fputcsv($handle, [
                        $tx->id,
                        $tx->created_at ? $tx->created_at->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s') : '',
                        $tx->customer_id,
                        $tx->customer->name ?? 'Guest',
                        $tx->customer->phone ?? '',
                        $orderNum,
                        $tx->transaction_type,
                        $tx->points,
                        number_format($tx->eligible_amount, 2),
                        $tx->description,
                        $tx->reference_id,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}

