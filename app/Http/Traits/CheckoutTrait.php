<?php

namespace App\Http\Traits;

use Exception;
use App\Helpers\Helpers;
use App\Helpers\GstCalculator;
use App\Enums\AmountEnum;
use Illuminate\Http\Request;
use App\Enums\ShippingRuleEnum;
use App\GraphQL\Exceptions\ExceptionHandler;

trait CheckoutTrait
{
  use UtilityTrait, CouponTrait, ShippingTrait, WalletPointsTrait;

  public function calculate(Request $request)
  {
    try {

      $settings = Helpers::getSettings();
      $amount = Helpers::getTotalAmount($request->products);
      if ($this->isActivePaymentMethod($request->payment_method, $amount)) {
        $minOrderAmount = $settings['general']['min_order_amount'] ?? 0;
        $products = $this->getUniqueProducts($request->products);
        $request->merge(['products' => $products]);
        if ($amount < $minOrderAmount) {
          throw new Exception("Please ensure your order is at least {$minOrderAmount} before proceed.", 422);
        }

        $outOfStockProduct = $this->isOutOfStock($request->products);
        if ($outOfStockProduct) {
          return $outOfStockProduct;
        }

        return $this->getCosts($request);
      }

    } catch (Exception $e) {

      throw new ExceptionHandler($e->getMessage(), $e->getCode());
    }
  }

  public function getCosts($request)
  {
    $shippingRules = $this->getShippingRules($request->shipping_address_id);
    return $this->calculateCosts($request, $shippingRules);
  }

  public function calculateCosts($request, $shippingRules)
  {
    try {

      $tax = [];
      $points = 0;
      $pointsAmount = 0;
      $walletBalance = 0;
      $shippingTotal = 0;
      $perProductCost = [];
      $couponTotalDiscount = [];
      $convert_point_amount = 0;
      $convert_wallet_balance = 0;
      $settings = Helpers::getSettings();
      $amount = Helpers::getTotalAmount($request->products);

      $coupon = null;
      $totalEligibleAmount = 0;
      if (isset($request->coupon)) {
          $coupon = Helpers::getCoupon($request->coupon);
          if ($this->isValidCoupon($coupon, $amount, $this->getConsumerId($request))) {
              foreach ($request->products as $prod) {
                  if ($this->isIncludeOrExclude($coupon, $prod)) {
                      $totalEligibleAmount += Helpers::getSubTotal(Helpers::getSalePrice($prod), $prod['quantity']);
                  }
              }
          } else {
              $coupon = null;
          }
      }

      foreach ($request->products as $product) {
        $shippingCost = 0;
        $perProductTax = 0;
        $perProductDiscount = 0;
        $perProductShippingCost = 0;
        $singleProductPrice = Helpers::getSalePrice($product);
        // inclusiveSubTotal = GST-inclusive line total (price x qty)
        $inclusiveSubTotal = Helpers::getSubTotal($singleProductPrice, $product['quantity']);
        $subTotal = $inclusiveSubTotal; // may be reduced by coupon below

        $minOrderFreeShipping = $settings['general']['min_order_free_shipping'] ?? 0;
        if ($minOrderFreeShipping >= $amount) {
          if ($shippingRules) {
            if ($this->isNotFreeShipping($product['product_id'])) {
              foreach ($shippingRules as $shippingRule) {
                switch ($shippingRule->rule_type) {

                  case ShippingRuleEnum::BASE_ON_WEIGHT:
                    $shippingCost = $this->baseOnWeight($product, $shippingRule);
                    if ($shippingCost > 0) {
                      $perProductShippingCost = $shippingCost;
                    }

                    $shippingTotal += $shippingCost;
                    break;

                  case ShippingRuleEnum::BASE_ON_PRICE:
                    $shippingCost = $this->baseOnPrice($product, $shippingRule);
                    if ($shippingCost > 0) {
                      $perProductShippingCost = $shippingCost;
                    }

                    $shippingTotal += $shippingCost;
                    break;

                  default:
                    $shippingCost = 0;
                    $shippingTotal += $shippingCost;
                }
              }
            }
          }
        }

        if ($coupon) {
          if ($this->isIncludeOrExclude($coupon, $product)) {
            switch ($coupon->type) {
              case AmountEnum::FIXED:
                if ($totalEligibleAmount > 0) {
                    $effectiveCouponAmount = min($coupon->amount, $totalEligibleAmount);
                    $perProductDiscount = ($subTotal / $totalEligibleAmount) * $effectiveCouponAmount;
                } else {
                    $perProductDiscount = 0;
                }
                break;

              case AmountEnum::PERCENTAGE:
                $perProductDiscount =  $this->percentageDiscount($subTotal, $coupon->amount);
                break;

              default:
                $perProductShippingCost = 0;
                $shippingTotal = 0;
            }

            $couponTotalDiscount[] = $perProductDiscount;
            // Reduce the GST-inclusive line total by the coupon discount
            $subTotal = $subTotal - $perProductDiscount;
          }
        }

        // Extract GST using back-calculation from the (post-coupon) GST-inclusive line total.
        // taxableSubTotal + perProductTax = $subTotal (the inclusive amount).
        $gstBreakdown    = $this->getGstBreakdown($product['product_id'], $subTotal);
        $perProductTax   = $gstBreakdown['gst_amount'];   // extracted GST
        $taxableSubTotal = $gstBreakdown['taxable_value']; // ex-GST amount stored as subtotal

        $tax[] = $perProductTax;
        $perProductCost[] = [
          'store_id'     => Helpers::getStoreIdByProductId($product['product_id']),
          'product_id'   => $product['product_id'],
          'variation_id' => $product['variation_id'] ?? null,
          'tax'          => $perProductTax,    // GST extracted from inclusive price
          'shipping_cost'=> $perProductShippingCost,
          'single_price' => $singleProductPrice,
          'quantity'     => $product['quantity'],
          // subtotal = taxable value (ex-GST); taxable + tax = inclusive line total
          'subtotal'     => $taxableSubTotal,
        ];
      }

      if (Helpers::isMultiVendorEnable()) {
        foreach (array_unique(data_get($perProductCost, '*.store_id')) as $storeIds) {
          $store_ids[] = $storeIds;
        }
      } else {
        $store_ids = array_unique(data_get($perProductCost, '*.store_id'));
      }

      $filtered_sub_Total = [];
      foreach ($store_ids as $store_id) {

        $_total = [];
        $_products = [];
        $_tax_total = [];
        $_shipping_total = [];

        foreach ($perProductCost as $value) {
          if ($value['store_id'] == $store_id) {
            $_products[] = $value;
            $_tax_total[] = $value['tax'];
            $_shipping_total[] = $value['shipping_cost'];
            $_total[] = $value['subtotal'];
          }
        }

        $_item['store'] = $store_id;
        $_item['products'] = $_products;
        $_item['total'] = [
          'tax_total' => $this->formatDecimal(array_sum($_tax_total)),
          'shipping_total' => $this->formatDecimal(array_sum($_shipping_total)),
          'sub_total' => $this->formatDecimal(array_sum($_total)),  // taxable value (ex-GST)
          // total = taxable_value + gst + shipping = inclusive_price + shipping
          // Price to customer is UNCHANGED; GST is extracted, not added on top.
          'total' => $this->formatDecimal(array_sum($_shipping_total) + array_sum($_total) + array_sum($_tax_total)),
          'convert_point_amount' => $this->formatDecimal($convert_wallet_balance),
          'convert_wallet_balance' => $this->formatDecimal($convert_point_amount),
          'coupon_total_discount' => $this->formatDecimal(array_sum($couponTotalDiscount)),
        ];

        // Track taxable sub-totals per store (used for $subTotal below)
        $filtered_sub_Total[] = array_sum($_total);
        $items['items'][] = $_item;
      }

      if (Helpers::pointIsEnable()) {
        $points = $this->getPointAmount($this->getConsumerId($request));
        $convert_point_amount = - ($this->pointsToCurrency($points));
        $pointsAmount = abs($convert_point_amount);
      }

      if (Helpers::walletIsEnable()) {
        $convert_wallet_balance =  - ($this->getWalletBalance($this->getConsumerId($request)));
        $walletBalance = abs($convert_wallet_balance);
      }

      // $subTotal = sum of per-store taxable values (ex-GST), used for GST reporting sub-total.
      $subTotal = array_sum($filtered_sub_Total);
      // $total starts from getTotalAmount() = GST-inclusive price total.
      // This ensures the customer's payable amount is NEVER changed by GST extraction.
      $total = $amount;
      $couponDiscount = array_sum($couponTotalDiscount);

      if ($request->wallet_balance) {
        if ($this->verifyWallet($this->getConsumerId($request), $walletBalance)) {
          $convert_wallet_balance = abs($walletBalance);
          $walletBalance -=  $convert_wallet_balance;
          $total -= $convert_wallet_balance;
          if ($total < 0) {
            $walletBalance = abs($total);
            $total = 0;
          }

          if ($walletBalance > 0) {
            $convert_wallet_balance -= $walletBalance;
          }

          if ($walletBalance <= 0) {
            $convert_point_amount = - (min($pointsAmount, ($total - $walletBalance)));
          }

          $convert_wallet_balance = -$convert_wallet_balance;
        }
      }

      if ($request->points_amount) {
        if ($this->verifyPoints($this->getConsumerId($request), $pointsAmount)) {
          $convert_point_amount =  abs($pointsAmount);
          $pointsAmount -=  $convert_point_amount;
          $total -= $convert_point_amount;

          if ($total < 0) {
            $pointsAmount = abs($total);
            $total = 0;
          }

          if ($pointsAmount > 0) {
            $convert_point_amount -= $pointsAmount;
          }

          $convert_point_amount = -$convert_point_amount;
        }
      }

      if ($couponDiscount > 0) {
        $total -= $couponDiscount;
        if ($total < 0) {
          $couponDiscount = abs($total);
          $total = 0;
        }
      }

      $total +=  $shippingTotal;
      $itemTotal = [
        'tax_total' => $this->formatDecimal(array_sum($tax)),
        'shipping_total' => $this->formatDecimal($shippingTotal),
        'points' => $this->formatDecimal($points),
        'convert_point_amount' => $this->formatDecimal($convert_point_amount),
        'points_amount' => $this->formatDecimal($pointsAmount),
        'wallet_balance' => $this->formatDecimal($walletBalance),
        'convert_wallet_balance' => $this->formatDecimal($convert_wallet_balance),
        'coupon_total_discount' => $this->formatDecimal($couponDiscount),
        'sub_total' => $this->formatDecimal($subTotal),
        'total' => $this->formatDecimal($total)
      ];

      $items['total'] = $itemTotal;
      return $items;

    } catch (Exception $e) {

      throw new ExceptionHandler($e->getMessage(), $e->getCode());
    }
  }

  /**
   * Get the GST breakdown for a product's GST-inclusive line total.
   *
   * Returns:
   *   taxable_value = inclusiveLineTotal x 100 / (100 + gstRate)
   *   gst_amount    = inclusiveLineTotal - taxable_value
   *
   * @param  int   $product_id         Product ID
   * @param  float $inclusiveLineTotal  GST-inclusive line total (after discounts)
   * @return array{taxable_value: float, gst_amount: float, gst_rate: float}
   */
  public function getGstBreakdown(int $product_id, float $inclusiveLineTotal): array
  {
    $tax_id  = $this->getTaxId($product_id);
    $gstRate = $this->getTaxRate($tax_id) ?? 0;

    return GstCalculator::breakdown($inclusiveLineTotal, (float) $gstRate);
  }

  /**
   * @deprecated Use getGstBreakdown() instead.
   * Kept for backward-compatibility with any direct callers.
   */
  public function getTax($product_id, $subtotal)
  {
    return $this->getGstBreakdown($product_id, (float) $subtotal)['gst_amount'];
  }
}
