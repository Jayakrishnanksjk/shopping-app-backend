<?php
namespace App\Http\Traits;

use App\Models\Order;
use App\Helpers\Helpers;
use App\Models\OrderTransaction;
use App\Enums\PaymentStatus;
use App\Events\PlaceOrderEvent;

trait PaymentTrait {

  use UtilityTrait;

  public static function updateOrderPaymentStatus(Order $order, $status)
  {
    $previousStatus = strtoupper($order->payment_status ?? '');
    $wasAlreadyPaid = in_array($previousStatus, [PaymentStatus::COMPLETED, 'PAID', 'SUCCESS']);

    $order->update([
      'payment_status' => $status
    ]);

    Order::where('parent_id', $order->id)->update(['payment_status' => $status]);
    $order = $order->fresh();

    // Safe to call on every status update (payment polling, webhooks, COD
    // status changes): updateProductStock() claims a per-order flag
    // atomically and only ever decrements stock once per order.
    Helpers::updateProductStock($order);

    // If transitioning to COMPLETED for an online payment for the first time,
    // dispatch PlaceOrderEvent so store/admin/customer receive order notifications.
    if (!$wasAlreadyPaid && strtoupper($status) === PaymentStatus::COMPLETED) {
      $parentOrder = $order->parent_id ? Order::find($order->parent_id) : $order;
      if ($parentOrder) {
        event(new PlaceOrderEvent($parentOrder));
      } else {
        event(new PlaceOrderEvent($order));
      }
    }

    return $order;
  }

  public static function updateOrderPaymentMethod(Order $order, $method)
  {
    $order->update([
      'payment_method' => $method
    ]);

    $order = $order->fresh();
    return $order;
  }

  public static function storeOrderTransaction(Order $order, $transaction_id,$payment_method) : void
  {
    $order = self::updateOrderPaymentMethod($order, $payment_method);
    $order->order_transactions()->updateOrCreate(['order_id' => $order->id],
      ['transaction_id' => $transaction_id]
    );
  }

  public static function verifyOrderTransaction($order_id, $transaction_id)
  {
    return OrderTransaction::where([['order_id', $order_id],['transaction_id', $transaction_id]])->first();
  }
}
