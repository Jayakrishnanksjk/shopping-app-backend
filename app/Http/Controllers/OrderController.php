<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Order;
use App\Models\Refund;
use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Repositories\Eloquents\OrderRepository;

class OrderController extends Controller
{
    public $repository;

    public function __construct(OrderRepository $repository)
    {
        $this->repository = $repository;
        $this->authorizeResource(Order::class, 'order', [
            'except' => ['show'],
        ]);
    }

    
    public function index(Request $request)
    {
        try {

            $orders = $this->repository->whereNull('parent_id')->with('sub_orders');
            $orders = $this->filter($orders, $request);
            return $orders->latest('created_at')->paginate($request->paginate  ?? $orders->count());

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    
    public function store(CreateOrderRequest $request)
    {
        return $this->repository->placeOrder($request);
    }

    
    public function show($id)
    {
        return $this->repository->show($id);
    }

    
    public function update(UpdateOrderRequest $request, Order $order)
    {
        return $this->repository->update($request->all(), $order->getId($request));
    }

    
    public function destroy(Request $request, Order $order)
    {
        return $this->repository->destroy($order->getId($request));
    }

    
    public function trackOrder(Request $request)
    {
        return $this->repository->trackOrder($request->order_number);
    }

    
    public function verifyPayment(Request $request)
    {
        return $this->repository->verifyPayment($request);
    }

    public function verifyRefund(Request $request, $order_number)
    {
        try {
            $order = $this->repository->verifyOrderNumber($order_number);
            
            // Check admin authorization
            $roleName = Helpers::getCurrentRoleName();
            if ($roleName !== RoleEnum::ADMIN) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }
            
            // Get refund ID from request
            $refundId = $request->input('refund_id');
            if (!$refundId) {
                return response()->json(['message' => 'Refund ID required'], 422);
            }
            
            $refund = Refund::find($refundId);
            if (!$refund) {
                return response()->json(['message' => 'Refund not found'], 404);
            }
            
            // Toggle/refund status - admin checks the box to mark as done
            // The frontend sends: { status: 'done' }
            $newStatus = $request->input('status', 'done');
            $validStatuses = ['pending', 'processing', 'done'];
            
            if (!in_array($newStatus, $validStatuses)) {
                return response()->json(['message' => 'Invalid status'], 422);
            }
            
            $refund->status = $newStatus;
            $refund->updated_at = now();
            $refund->save();
            
            return response()->json([
                'message' => 'Refund status updated',
                'refund' => [
                    'id' => $refund->id,
                    'status' => $refund->status,
                    'amount' => $refund->amount,
                    'updated_at' => $refund->updated_at,
                ]
            ]);
            
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function rePayment(Request $request)
    {
        return $this->repository->rePayment($request);
    }

    public function getInvoiceUrl(Request $request)
    {
        return $this->repository->getInvoiceUrl($request->order_number);
    }

    public function getInvoice(Request $request)
    {
        return $this->repository->getInvoice($request);
    }

    public function filter($orders, $request)
    {
        $roleName = Helpers::getCurrentRoleName();
        if ($roleName == RoleEnum::CONSUMER) {
            $orders = $orders->where('consumer_id',Helpers::getCurrentUserId());
        }

        if ($roleName == RoleEnum::VENDOR) {
            $orders = $this->repository->whereNotNull('parent_id')->where('store_id',Helpers::getCurrentVendorStoreId());
        }

        if ($request->field && $request->sort) {
            $orders = $orders->orderBy($request->field, $request->sort);
        }

        if (isset($request->status)) {
            $orders = $orders->where('status',$request->status);
        }

        if ($request->start_date && $request->end_date) {
            $orders = $orders->whereBetween('created_at',[$request->start_date, $request->end_date]);
        }

        return $orders;
    }
}
