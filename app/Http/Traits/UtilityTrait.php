<?php

namespace App\Http\Traits;

use Exception;
use App\Models\Tax;
use App\Models\Product;
use App\Helpers\Helpers;
use App\Enums\PaymentMethod;
use App\Enums\PaypalCurrencies;

trait UtilityTrait
{
  public function getUniqueProducts($products)
  {
    return collect($products)->unique(function ($product) {
      return $product['product_id'] . '-' . ($product['variation_id'] ?? '');
    })->values()->toArray();
  }

  public function isEnablePaymentMethod($method)
  {
    $settings = Helpers::getSettings();
    $paymentMethods = $settings['payment_methods'] ?? [];
    if (isset($paymentMethods[$method])) {
      if (isset($paymentMethods[$method]['status']) && $paymentMethods[$method]['status']) {
        return true;
      }
    }

    if ($method === 'cod') {
      return true;
    }

    return false;
  }

  public function isActivePaymentMethod($method, $amount = null)
  {
    $settings = Helpers::getSettings();
    if ($this->isEnablePaymentMethod($method)) {
      $defaultCurrencyCode = Helpers::getDefaultCurrencyCode();
      if ($method == PaymentMethod::PAYPAL) {
        if (!in_array($defaultCurrencyCode, array_column(PaypalCurrencies::cases(), 'value'))) {
          throw new Exception($defaultCurrencyCode . ' currency code is not support for '.$method, 400);
        }
      }

      if ($method == PaymentMethod::PHONEPE) {
        $phonepeSettings = $settings['payment_methods'][PaymentMethod::PHONEPE] ?? [];
        if ($phonepeSettings['sandbox_mode'] ?? false) {
          if (Helpers::getDefaultCurrencyCode() != 'INR') {
            $amount = Helpers::convertToINR($amount);
          }

          if (max(min($amount, 1000), 1) == $amount) {
            return true;
          }

          throw new Exception("In the PhonePe Sandbox mode, transactions between 1 to 1000 INR can be processed.", 400);
        }
      }

      return true;
    }

    throw new Exception('The provided payment method is not currently enable.', 400);
  }

  public function formatDecimal($value)
  {
    return Helpers::formatDecimal($value);
  }

  public function getConsumerId($request)
  {
    return $request->consumer_id ?? Helpers::getCurrentUserId();
  }

  public function getTaxId($product_id)
  {
    return Product::where('id', $product_id)->pluck('tax_id')->first();
  }

  public function getTaxRate($tax_id)
  {
    return Tax::where([['id', $tax_id], ['status', true]])->pluck('rate')->first();
  }

  public function isOutOfStock($products)
  {
    $outOfStockProducts = [];
    foreach ($products as $product) {
      if (isset($product['variation_id']) && !empty($product['variation_id'])) {
        $variationStock = Helpers::getVariationStock($product['variation_id']);
        if (!isset($variationStock)) {
          $outOfStockProducts[] = [
            'product_id' => $product['product_id'],
            'variation_id' => $product['variation_id'],
          ];
        }
      } else {
        $productStock = Helpers::getProductStock($product['product_id']);
        if (!isset($productStock)) {
          $outOfStockProducts[] = [
            'product_id' => $product['product_id'],
          ];
        }
      }
    }

    if (!empty($outOfStockProducts)) {
      \Illuminate\Support\Facades\Log::warning('Checkout/cart stock check failed: items out of stock or inactive', [
        'items' => $outOfStockProducts
      ]);
      throw new Exception("Some of the products you've selected are either out of stock or inactive.", 400);
    }

    return false;
  }
}
