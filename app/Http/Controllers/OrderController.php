<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\Order;
use App\Models\Refund;
use App\Enums\RoleEnum;
use App\Enums\OrderEnum;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Helpers\Helpers;
use Illuminate\Http\Request;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Repositories\Eloquents\OrderRepository;
use Illuminate\Database\Eloquent\Builder;

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

        // Hide unpaid online payment attempts by default (e.g. abandoned PhonePe/UPI checkouts)
        // Keep orders if:
        //  - payment_method is COD
        //  - OR payment_status is COMPLETED
        //  - OR order has already moved past initial 'pending' status (e.g. cancelled, confirmed, processing)
        // If caller explicitly specifies ?payment_status=... or ?include_unpaid=1, don't filter them out.
        if (!$request->has('payment_status') && !$request->boolean('include_unpaid')) {
            $pendingStatusId = Helpers::getOrderStatusIdByName(OrderEnum::PENDING);
            $orders = $orders->where(function ($q) use ($pendingStatusId) {
                $q->whereRaw('LOWER(payment_method) = ?', [strtolower(PaymentMethod::COD)])
                  ->orWhere('payment_status', PaymentStatus::COMPLETED)
                  ->orWhere('order_status_id', '!=', $pendingStatusId);
            });
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

        $storeId = $request->store_id ?: $request->header('X-Store-Id');
        if ($storeId) {
            $orders = $orders->where(function ($q) use ($storeId) {
                if ((int)$storeId === 3 || (int)$storeId === 1) {
                    $q->where('store_id', $storeId)
                      ->orWhereNull('store_id')
                      ->orWhereHas('sub_orders', function (Builder $subOrders) use ($storeId) {
                          $subOrders->where('store_id', $storeId)->orWhereNull('store_id');
                      })
                      ->orWhereHas('products', function (Builder $products) use ($storeId) {
                          $products->where('store_id', $storeId)->orWhereNull('store_id');
                      });
                } else {
                    $q->where('store_id', $storeId)
                      ->orWhereHas('sub_orders', function (Builder $subOrders) use ($storeId) {
                          $subOrders->where('store_id', $storeId);
                      })
                      ->orWhereHas('products', function (Builder $products) use ($storeId) {
                          $products->where('store_id', $storeId);
                      });
                }
            });
        }

        if ($request->store_slug) {
            $store_slug = $request->store_slug;
            $orders = $orders->where(function ($q) use ($store_slug) {
                if ($store_slug === 'grocery' || $store_slug === 'main-store') {
                    $q->whereHas('store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    })->orWhereNull('store_id')
                    ->orWhereHas('sub_orders.store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    })
                    ->orWhereHas('products.store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    });
                } else {
                    $q->whereHas('store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    })
                    ->orWhereHas('sub_orders.store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    })
                    ->orWhereHas('products.store', function (Builder $stores) use ($store_slug) {
                        $stores->where('slug', $store_slug);
                    });
                }
            });
        }

        return $orders;
    }
}
