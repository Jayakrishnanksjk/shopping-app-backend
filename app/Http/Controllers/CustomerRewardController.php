<?php

namespace App\Http\Controllers;

use App\Helpers\Helpers;
use App\Models\Order;
use App\Models\RewardTransaction;
use App\Services\Rewards\RewardPointsService;
use App\Services\Rewards\RewardWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerRewardController extends Controller
{
    protected RewardWalletService $walletService;
    protected RewardPointsService $pointsService;

    public function __construct(RewardWalletService $walletService, RewardPointsService $pointsService)
    {
        $this->walletService = $walletService;
        $this->pointsService = $pointsService;
    }

    /**
     * GET /api/customer/rewards
     * Retrieve points and equivalent wallet value for authenticated customer
     */
    public function index(): JsonResponse
    {
        $userId = Helpers::getCurrentUserId();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $balance = $this->walletService->getCustomerBalance($userId);

        return response()->json([
            'success' => true,
            'data' => $balance
        ]);
    }

    /**
     * GET /api/customer/rewards/transactions
     * Retrieve paginated reward transaction history for authenticated customer
     */
    public function transactions(Request $request): JsonResponse
    {
        $userId = Helpers::getCurrentUserId();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $paginate = (int) ($request->paginate ?? $request->limit ?? 15);
        if ($paginate <= 0 || $paginate > 100) {
            $paginate = 15;
        }

        $transactions = RewardTransaction::with('order:id,order_number')
            ->where('customer_id', $userId)
            ->orderBy('id', 'desc')
            ->paginate($paginate);

        $formatted = $transactions->getCollection()->map(function ($tx) {
            $orderNumber = $tx->order ? ($tx->order->order_number ?? $tx->order->id) : null;
            return [
                'id' => $tx->id,
                'order_id' => $orderNumber ? "GRZ-{$orderNumber}" : ($tx->order_id ? "GRZ-{$tx->order_id}" : null),
                'raw_order_id' => $tx->order_id,
                'transaction_type' => $tx->transaction_type,
                'points' => (int) $tx->points,
                'eligible_amount' => (float) $tx->eligible_amount,
                'description' => $tx->description,
                'created_at' => $tx->created_at ? $tx->created_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'total' => $transactions->total(),
            'current_page' => $transactions->currentPage(),
            'last_page' => $transactions->lastPage(),
        ]);
    }

    /**
     * GET /api/customer/rewards/orders/{id}
     * Retrieve reward details for a customer's order
     */
    public function orderDetails($id): JsonResponse
    {
        $userId = Helpers::getCurrentUserId();
        if (!$userId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $order = Order::where(function ($q) use ($id) {
            $q->where('id', $id)->orWhere('order_number', $id);
        })
        ->where('consumer_id', $userId)
        ->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }

        $summary = $this->pointsService->getOrderRewardDetails($order);

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }
}

