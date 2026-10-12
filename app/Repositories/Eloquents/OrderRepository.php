<?php

namespace App\Repositories\Eloquents;

use Exception;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Cancellation;
use App\Models\Refund;
use App\Payments\Cod;
use App\Payments\Mollie;
use App\Payments\PayPal;
use App\Payments\Stripe;
use App\Helpers\Helpers;
use App\Enums\OrderEnum;
use App\Payments\PhonePe;
use App\Payments\RazorPay;
use App\Payments\InstaMojo;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use App\Models\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\PlaceOrderEvent;
use App\Models\OrderTransaction;
use App\Enums\WalletPointsDetail;
use App\Http\Traits\PaymentTrait;
use Illuminate\Support\Facades\DB;
use App\Http\Traits\CheckoutTrait;
use App\Http\Traits\TransactionsTrait;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use App\Events\UpdateOrderStatusEvent;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Payments\CCAvenue;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class OrderRepository extends BaseRepository
{
    use CheckoutTrait, PaymentTrait, TransactionsTrait;

    protected $settings;
    protected $orderStatus;
    protected $orderTransaction;

    protected $fieldSearchable = [
        'order_number' => 'like',
        'payment_method' => 'like',
        'orderStatus.name' => 'like',
        'consumer.name' => 'like',
        'payment_status' => 'like',
    ];

    public function boot()
    {
        try {

            $this->pushCriteria(app(RequestCriteria::class));

        } catch (ExceptionHandler $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    function model()
    {
        $this->orderStatus = new OrderStatus();
        $this->settings = Helpers::getSettings();
        $this->orderTransaction = new OrderTransaction();
        return Order::class;
    }

    public function getOrderNumber($digits)
    {
        $i = 0;
        do {

            $order_number = pow(10, $digits) + $i++;

        } while ($this->model->where("order_number", "=", $order_number)->exists());

        return $order_number;
    }

    public function show($order_number)
    {
        try {
            $order = Helpers::getOrderByOrderNumber($order_number);
            if ($order) {
                $order = $this->verifyPayment($order);
                
                // Add cancellation and refund details
                $this->enhanceOrderWithCancellationRefund($order);
                
                return $order;
            }

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    protected function enhanceOrderWithCancellationRefund($order)
    {
        // Cancellation data
        $cancellation = $order->cancellation;

        // Refund data
        $refund = $order->refund;

        // Add cancellable flag: true if order is not cancelled and not packed/delivered
        $isCancelled = $order->status === OrderEnum::CANCELLED 
            || ($order->order_status && $order->order_status->name === OrderEnum::CANCELLED);
        $order->cancellable = !$isCancelled && ($order->order_status?->name !== OrderEnum::PACKED);

        // Payment defaults
        $order->payment_method = $order->payment_method ?? 'cod';
        $order->payment_status = $order->payment_status ?? PaymentStatus::PENDING;

        // Attach cancel_status to product pivots without losing product model data/thumbnails
        if ($order->products) {
            $cancelStatusMap = [];
            if ($cancellation && $cancellation->items) {
                foreach ((array)$cancellation->items as $item) {
                    if (isset($item['product_id'])) {
                        $cancelStatusMap[$item['product_id']] = $cancellation->status;
                    }
                }
            }

            foreach ($order->products as $product) {
                if ($product->pivot) {
                    $product->pivot->cancel_status = $cancelStatusMap[$product->id] ?? ($isCancelled ? 'cancelled' : 'none');
                }
            }
        }

        // Add cancellation object
        if ($cancellation) {
            $order->cancellation = (object)[
                'status' => $cancellation->status,
                'scope' => $cancellation->scope,
                'reason' => $cancellation->reason,
                'created_at' => $cancellation->created_at ? $cancellation->created_at->toISOString() : null,
            ];
        } else {
            $order->cancellation = (object)[
                'status' => $isCancelled ? 'cancelled' : 'none',
                'scope' => 'full',
                'reason' => '',
                'created_at' => null,
            ];
        }

        // Add refund object
        if ($refund) {
            $order->refund = (object)[
                'required' => true,
                'status' => $refund->status,
                'amount' => (float)$refund->amount,
                'updated_at' => $refund->updated_at ? $refund->updated_at->toISOString() : null,
                'method' => $refund->payment_type ?? 'original_source',
            ];
        } else {
            $order->refund = (object)[
                'required' => false,
                'status' => 'none',
                'amount' => 0,
                'updated_at' => null,
                'method' => 'none',
            ];
        }
    }

    public function trackOrder($order_number)
    {
        try {

            return $this->show($order_number);

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function placeOrder($request)
    {
        DB::beginTransaction();
        try {

            $consumer_id = $this->getConsumerId($request);
            $products = $this->getUniqueProducts($request->products);
            $request->merge(['products' => $products]);
            $items = $this->calculate($request);

            if ($request->coupon) {
                $coupon = Helpers::getCoupon($request->coupon);
                $amount = Helpers::getTotalAmount($request->products);
                if ($this->isValidCoupon($coupon, $amount, $request->consumer_id)) {
                    $request->merge(['coupon_id' => $coupon->id]);
                }
            }

            $request->merge(['store_id' => head($items['items'])['store']]);
            $order = $this->createOrder($items, $request);
            if (Helpers::isMultiVendorEnable()) {
                $this->createSubOrder($items, $request, $order);
                $order->sub_orders;
            }

            DB::commit();
            Helpers::removeCart($order);

            $order = $order->fresh();
            if ($request->points_amount) {
                $balance = abs($items['total']['convert_point_amount']);
                if ($this->verifyPoints($consumer_id, $balance)) {
                    $balance = $this->currencyToPoints($balance);
                    $this->debitPoints($consumer_id, $balance, WalletPointsDetail::POINTS_ORDER.' #'.$order->order_number);
                }
            }

            if ($request->wallet_balance) {
                $balance = abs($items['total']['convert_wallet_balance']);
                if ($this->verifyWallet($consumer_id, $balance)) {
                    $this->debitWallet($consumer_id, $balance, WalletPointsDetail::WALLET_ORDER.' #'.$order->order_number);
                }
            }

            if (Helpers::pointIsEnable()) {
                $rewardPoints = $this->getRewardPoints($items['total']['total']);
                if ($rewardPoints) {
                    $this->creditPoints($consumer_id, $rewardPoints, WalletPointsDetail::REWARD.' #'.$order->order_number);
                }
            }

            if ($request->coupon_id) {
                $this->updateCouponUsage($request->coupon_id);
            }

            return $this->createPayment($order, $request);

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function createOrder($item, $request)
    {
        if ($this->isActivePaymentMethod($request->payment_method, $item['total']['total'])) {
            $order_number = (string) $this->getOrderNumber(3);
            if (!$request->points_amount) {
                $item['total']['convert_point_amount'] = 0;
            }
            if (!$request->wallet_balance) {
                $item['total']['convert_wallet_balance'] = 0;
            }

            $order =  $this->model->create([
                'order_number' => $order_number,
                'consumer_id' => $request->consumer_id ?? Helpers::getCurrentUserId(),
                'store_id' => $request->store_id,
                'tax_total' => $item['total']['tax_total'],
                'shipping_total' => $item['total']['shipping_total'],
                'payment_method' => $request->payment_method,
                'order_status_id' => Helpers::getOrderStatusIdByName(OrderEnum::PENDING),
                'shipping_address_id' => $request->shipping_address_id,
                'billing_address_id' =>  $request->billing_address_id,
                'delivery_description' => $request->delivery_description,
                'delivery_interval' => $request->delivery_interval,
                'parent_id' => $request->parent_id,
                'coupon_id' => $request->coupon_id,
                'points_amount' => $item['total']['convert_point_amount'],
                'wallet_balance' => $item['total']['convert_wallet_balance'],
                'invoice_url' => $this->generateInvoiceUrl($order_number),
                'coupon_total_discount' => $item['total']['coupon_total_discount'],
                'amount' => $item['total']['sub_total'],
                'total' => $item['total']['total']
            ]);

            if (!isset($item['products'])) {
                foreach($item['items'] as $itemValues) {
                    foreach ($itemValues['products'] as $productValue) {
                        $itemProduct[] = $productValue;
                    }
                }

                $item['products'] = $itemProduct;
            }

            foreach ($item['products'] as $itemProduct) {
                $itemProduct = Arr::except($itemProduct, ['store_id']);
                $item_products[] = $itemProduct;
            }

            $item['products'] = $item_products;
            $order->products()->attach($item['products']);
            $item_products = [];

            $order->store;
            $order->products;
            $order->order_status;

            event(new PlaceOrderEvent($order));
            return $order;
        }
    }

    public function createSubOrder($items, $request, Order $parentOrder)
    {
        $subOrders = [];
        if (count($items['items']) > 1) {
            foreach ($items['items'] as $item) {
                if (isset($request->products)){
                    $request->merge(['parent_id' => $parentOrder->id, 'store_id' => $item['store']]);
                    $order = $this->createOrder($item, $request);
                    $subOrders[] = $order;
                }
            }
        }

        return $subOrders;
    }

    public function getRewardPoints($total)
    {
        $minPerOrderAmount = $this->settings['wallet_points']['min_per_order_amount'] ?? 0;
        $rewardPerOrderAmount = $this->settings['wallet_points']['reward_per_order_amount'] ?? 0;
        if ($minPerOrderAmount > 0 && $total >= $minPerOrderAmount) {
            $rewardPoints = ($total/$minPerOrderAmount)*$rewardPerOrderAmount;
            return $rewardPoints;
        }
    }

    public function getWalletRatio()
    {
        $walletRatio = $this->settings['general']['wallet_currency_ratio'] ?? 1;
        return $walletRatio <= 0 ? 1 : $walletRatio;
    }

    public function update($request, $id)
    {
        DB::beginTransaction();
        try {

            $request = Arr::except($request, ['order_number']);
            $order = $this->model->findOrFail($id);
            if (isset($request['order_status_id'])) {
                $order_status = $this->orderStatus->where('id', $request['order_status_id'])->pluck('name')->first();
                if ($order_status == OrderEnum::DELIVERED && $order->payment_method == PaymentMethod::COD) {
                    $request['payment_status'] = PaymentStatus::COMPLETED;
                } else if ($order_status == OrderEnum::CANCELLED) {
                    $request['payment_status'] = PaymentStatus::CANCELLED;
                } else if ($order_status == OrderEnum::RETURNED) {
                    $request['payment_status'] = PaymentStatus::FAILED;
                    if ($order->order_status_id != $request['order_status_id']) {
                        Helpers::restockProductStock($order);
                    }
                }
            }

            $order->update($request);
            DB::commit();

            $order = $order->fresh();
            $order->products;
            $order->sub_orders;
            $order->billing_address;
            $order->shipping_address;

            if (isset($request['order_status_id'])) {
                if ($order->order_status->name == OrderEnum::DELIVERED) {
                    $order->delivered_at = Carbon::now()->toDateString();
                    $order->save();
                }

                event(new UpdateOrderStatusEvent($order));
            }

            return $order;

        } catch (Exception $e) {

            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function destroy($id)
    {
        try {

            return $this->model->where('id', $id)->where('consumer_id', Helpers::getCurrentUserId())->destroy($id);

        } catch (Exception $e){

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function verifyPayment($request)
    {
        try {

            $order = $this->verifyOrderNumber($request->order_number);
            $transaction_id = $this->orderTransaction->where('order_id',$order->id)->pluck('transaction_id')->first();
            if (is_null($order) || !$transaction_id) {
                return $order;
            }

            switch ($order->payment_method) {
                case PaymentMethod::PAYPAL:
                    return PayPal::status($order, $transaction_id);

                case PaymentMethod::STRIPE:
                    return Stripe::status($order, $transaction_id);

                case PaymentMethod::RAZORPAY:
                    return RazorPay::status($order, $transaction_id);

                case PaymentMethod::MOLLIE:
                    return Mollie::status($order, $transaction_id);

                case PaymentMethod::PHONEPE:
                    return PhonePe::status($order, $transaction_id);

                case PaymentMethod::INSTAMOJO:
                    return InstaMojo::status($order, $transaction_id);

                default:
                    return $order;
            }

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function verifyOrderNumber($order_number)
    {
        try {

            $order = $this->model->with(config('enums.order.with'))->where('order_number', $order_number)->first();
            if (!$order) {
                throw new Exception('The provided order number is not valid.', 400);
            }

            $order->products;
            return $order;

        }  catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function cancelOrder($order_number, Request $request)
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        $order = $this->verifyOrderNumber($order_number);

        // Check if order is already cancelled
        if ($order->status === OrderEnum::CANCELLED || ($order->order_status && $order->order_status->name === OrderEnum::CANCELLED)) {
            return response()->json([
                'code' => 'ALREADY_CANCELLED',
                'message' => 'Order is already cancelled',
            ], 422);
        }

        // Check if order is already packed
        if ($order->order_status && $order->order_status->name === OrderEnum::PACKED) {
            return response()->json([
                'code' => 'ALREADY_PACKED',
                'message' => 'Order cannot be cancelled after packing',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $items = $request->input('items', []);
            $scope = $request->input('scope', 'full');
            $reason = $request->input('reason', 'Customer requested cancellation');

            $cancellation = Cancellation::create([
                'order_id' => $order->id,
                'scope' => $scope,
                'items' => $scope === 'partial' ? $items : null,
                'reason' => $reason,
                'status' => 'cancelled',
            ]);

            // Update order status to cancelled
            $order->status = OrderEnum::CANCELLED;
            $cancelledStatus = OrderStatus::where('name', OrderEnum::CANCELLED)->first();
            if ($cancelledStatus) {
                $order->order_status_id = $cancelledStatus->id;
            }
            $order->save();

            // Determine refund requirements
            // COD: never needs refund, refundAmount = 0
            // Online: only if payment was completed
            $isCod = strtolower($order->payment_method ?? '') === 'cod';
            $isPaid = in_array(strtolower($order->payment_status ?? ''), ['completed', 'success', 'paid']);

            $refundRequired = false;
            $refundAmount = 0;

            if (!$isCod && $isPaid) {
                $refundRequired = true;
                if ($scope === 'partial') {
                    $refundAmount = array_sum(array_column($items, 'subtotal'));
                } else {
                    $refundAmount = (float)$order->total;
                }

                if ($refundAmount > 0) {
                    Refund::updateOrCreate(
                        ['order_id' => $order->id],
                        [
                            'reason' => $reason,
                            'amount' => $refundAmount,
                            'status' => 'pending',
                            'store_id' => $order->store_id,
                            'consumer_id' => $order->consumer_id,
                            'payment_type' => 'bank',
                        ]
                    );
                }
            }

            DB::commit();

            return response()->json([
                'cancellation_id' => $cancellation->id,
                'status' => 'cancelled',
                'scope' => $scope,
                'refund_required' => $refundRequired,
                'refund_amount' => $refundAmount,
                'cancellable' => false,
            ]);

        } catch (Exception $e) {
            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function rePayment($request)
    {
        try {

            $order = $this->verifyOrderNumber($request->order_number);
            if ($order->payment_status == PaymentStatus::COMPLETED) {
                throw new Exception('This payment has already been successfully processed previously.', 400);
            }

            return $this->createPayment($order, $request);

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function generateInvoiceUrl($order_number)
    {
        return route('invoice', ['order_number' => $order_number]);
    }

    public function getInvoiceUrl($order_number)
    {
        try {

            return $this->verifyOrderNumber($order_number)->invoice_url;

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function getInvoice($request)
    {
        try {

            $order = $this->verifyOrderNumber($request->order_number);
            $invoice = [
                'orders' => $order,
                'settings' => Helpers::getSettings(),
            ];

            return PDF::loadView('emails.invoice', $invoice)->download('invoice-'.$order->order_number.'.pdf');

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function createPayment(Order $order, $request)
    {
        try {

            switch ($request->payment_method) {
                case PaymentMethod::PAYPAL:
                    return PayPal::getIntent($order, $request);

                case PaymentMethod::STRIPE:
                    return Stripe::getIntent($order, $request);

                case PaymentMethod::RAZORPAY:
                    return RazorPay::getIntent($order, $request);

                case PaymentMethod::MOLLIE:
                    return Mollie::getIntent($order, $request);

                case PaymentMethod::PHONEPE:
                    return PhonePe::getIntent($order, $request);

                case PaymentMethod::INSTAMOJO:
                    return InstaMojo::getIntent($order, $request);

                case PaymentMethod::CCAVENUE:
                    return CCAvenue::getIntent($order, $request);

                case PaymentMethod::COD:
                    return Cod::status($order, $request);

                default:
                    throw new Exception('The selected payment method is not valid for this transaction.', 400);
            }

            return $order;

        } catch (Exception $e) {

            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }
}
