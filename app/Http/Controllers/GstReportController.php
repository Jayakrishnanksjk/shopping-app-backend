<?php

namespace App\Http\Controllers;

use App\Enums\OrderEnum;
use App\Helpers\Helpers;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GstReportController extends Controller
{
    /**
     * Return Simple GST Report & HSN/Subcategory Summary Report for the given date range.
     *
     * Query params:
     *   from     (string) Start date YYYY-MM-DD (default: today)
     *   to       (string) End date   YYYY-MM-DD (default: today)
     *   store_id (int)    Store ID filter (optional)
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from', now()->toDateString());
        $to   = $request->query('to',   now()->toDateString());
        $storeId = $request->header('X-Store-Id') ?: $request->query('store_id');

        $orderQuery = Order::with([
                'products:id,name,hsn_code,tax_id',
                'products.tax:id,rate,name',
                'products.categories:id,name,parent_id',
                'order_status:id,name,slug',
            ])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        if ($storeId) {
            $orderQuery->where('store_id', $storeId);
        }

        $allOrders = $orderQuery->orderBy('id', 'asc')->get();

        $totalBills = $allOrders->count();
        $billNumbers = $allOrders->pluck('order_number')->filter()->values();
        $billStart = $billNumbers->first() ?: '-';
        $billEnd = $billNumbers->last() ?: '-';

        $cancelledStatusId = Helpers::getOrderStatusIdByName(OrderEnum::CANCELLED);

        $cancelledCount = 0;
        $activeOrders = [];

        foreach ($allOrders as $order) {
            $isCancelled = false;
            if ($cancelledStatusId && $order->order_status_id == $cancelledStatusId) {
                $isCancelled = true;
            } elseif ($order->order_status && (
                strtolower($order->order_status->slug ?? '') === 'cancelled' ||
                strtolower($order->order_status->name ?? '') === strtolower(OrderEnum::CANCELLED)
            )) {
                $isCancelled = true;
            }

            if ($isCancelled) {
                $cancelledCount++;
            } else {
                $activeOrders[] = $order;
            }
        }

        // Available tax rates in the system
        $allTaxes = Tax::orderBy('rate', 'asc')->get();
        $knownRates = [];
        foreach ($allTaxes as $t) {
            $r = (float)$t->rate;
            $knownRates[(string)$r] = $r;
        }
        $defaultRates = [0, 3, 5, 12, 18, 28, 40];
        foreach ($defaultRates as $dr) {
            $knownRates[(string)$dr] = (float)$dr;
        }
        ksort($knownRates, SORT_NUMERIC);

        $salesByRate = [];
        foreach ($knownRates as $k => $r) {
            $salesByRate[$k] = 0.0;
        }

        $lines = [];
        $hsnMap = [];

        foreach ($activeOrders as $order) {
            foreach ($order->products as $product) {
                $pivot = $product->pivot;
                $quantity = (int)($pivot->quantity ?? 1);
                $singlePrice = (float)($pivot->single_price ?? 0);

                $rate = 0.0;
                if ($product->tax && isset($product->tax->rate)) {
                    $rate = (float)$product->tax->rate;
                }

                $lineSale = $singlePrice * $quantity;
                if ($lineSale <= 0 && isset($pivot->subtotal)) {
                    $lineSale = (float)$pivot->subtotal + (float)($pivot->tax ?? 0);
                }

                $rateKey = (string)$rate;
                if (!isset($salesByRate[$rateKey])) {
                    $salesByRate[$rateKey] = 0.0;
                }
                $salesByRate[$rateKey] += $lineSale;

                $lineTaxable = $rate > 0 ? round(($lineSale * 100) / (100 + $rate), 2) : round($lineSale, 2);
                $lineCgst = $rate > 0 ? round($lineTaxable * (($rate / 2) / 100), 2) : 0.0;
                $lineSgst = $rate > 0 ? round($lineTaxable * (($rate / 2) / 100), 2) : 0.0;
                $lineGst = round($lineCgst + $lineSgst, 2);

                // Determine Subcategory name
                $subcat = null;
                if ($product->categories && $product->categories->count() > 0) {
                    $subcatObj = $product->categories->firstWhere('parent_id', '!=', null);
                    $subcat = $subcatObj ? $subcatObj->name : $product->categories->first()->name;
                }
                $subcatName = $subcat ?: 'General';
                $hsnCode = !empty($product->hsn_code) ? trim($product->hsn_code) : 'N/A';

                $lines[] = [
                    'order_number' => $order->order_number,
                    'order_date' => $order->created_at->toDateString(),
                    'product_name' => $product->name,
                    'hsn_code' => $hsnCode,
                    'subcategory_name' => $subcatName,
                    'gst_rate' => $rate,
                    'quantity' => $quantity,
                    'unit_price' => round($singlePrice, 2),
                    'total_sale' => round($lineSale, 2),
                    'taxable_value' => $lineTaxable,
                    'cgst' => $lineCgst,
                    'sgst' => $lineSgst,
                    'total_gst' => $lineGst,
                ];

                // Consolidate HSN / Subcategory / GST Group
                $groupKey = "{$hsnCode}__{$subcatName}__{$rate}";
                if (!isset($hsnMap[$groupKey])) {
                    $hsnMap[$groupKey] = [
                        'hsn_code' => $hsnCode,
                        'subcategory_name' => $subcatName,
                        'gst_rate' => $rate,
                        'total_qty' => 0,
                        'total_sale' => 0.0,
                        'taxable_value' => 0.0,
                        'cgst' => 0.0,
                        'sgst' => 0.0,
                    ];
                }
                $hsnMap[$groupKey]['total_qty'] += $quantity;
                $hsnMap[$groupKey]['total_sale'] += $lineSale;
                $hsnMap[$groupKey]['taxable_value'] += $lineTaxable;
                $hsnMap[$groupKey]['cgst'] += $lineCgst;
                $hsnMap[$groupKey]['sgst'] += $lineSgst;
            }
        }

        // Build Slabs Array
        $slabs = [];
        $grandTotalSale = 0.0;
        $grandTaxable = 0.0;
        $grandCgst = 0.0;
        $grandSgst = 0.0;

        foreach ($salesByRate as $rateKey => $saleAmount) {
            $r = (float)$rateKey;
            if ($saleAmount <= 0 && !in_array($r, [0, 3, 5, 18, 40])) {
                continue;
            }

            $sale = round($saleAmount, 2);
            $taxable = $r > 0 ? round(($sale * 100) / (100 + $r), 2) : $sale;
            $cgst = $r > 0 ? round($taxable * (($r / 2) / 100), 2) : 0.0;
            $sgst = $r > 0 ? round($taxable * (($r / 2) / 100), 2) : 0.0;

            $slabs[] = [
                'name' => "Sales (b2c)-{$r}%",
                'rate' => $r,
                'total_sale' => $sale,
                'taxable_value' => $taxable,
                'cgst' => $cgst,
                'sgst' => $sgst,
                'igst' => 0.0,
                'gst_cess' => 0.0,
            ];

            $grandTotalSale += $sale;
            $grandTaxable += $taxable;
            $grandCgst += $cgst;
            $grandSgst += $sgst;
        }

        // Build HSN summary array
        $hsnSummary = [];
        foreach ($hsnMap as $item) {
            $item['total_sale'] = round($item['total_sale'], 2);
            $item['taxable_value'] = round($item['taxable_value'], 2);
            $item['cgst'] = round($item['cgst'], 2);
            $item['sgst'] = round($item['sgst'], 2);
            $hsnSummary[] = $item;
        }

        usort($hsnSummary, function ($a, $b) {
            $cmp = strcmp($a['hsn_code'], $b['hsn_code']);
            if ($cmp === 0) {
                return strcmp($a['subcategory_name'], $b['subcategory_name']);
            }
            return $cmp;
        });

        $settings = Helpers::getSettings();
        $storeName = 'MATHER TRADING COMPANY';
        $storeAddress = 'NH-47, KOTTIYAM, KOLLAM';
        if ($storeId) {
            $store = Store::find($storeId);
            if ($store) {
                $storeName = $store->store_name;
                $storeAddress = $store->address ?? $storeAddress;
            }
        } elseif (!empty($settings['general']['site_title'])) {
            $storeName = $settings['general']['site_title'];
        }

        return response()->json([
            'from' => $from,
            'to' => $to,
            'store' => [
                'name' => $storeName,
                'address' => $storeAddress,
            ],
            'bills' => [
                'bill_start' => $billStart,
                'bill_end' => $billEnd,
                'total_bills' => $totalBills,
                'cancelled_bills' => $cancelledCount,
                'active_bills' => $totalBills - $cancelledCount,
            ],
            'summary' => [
                'total_sale' => round($grandTotalSale, 2),
                'total_taxable_value' => round($grandTaxable, 2),
                'total_cgst' => round($grandCgst, 2),
                'total_sgst' => round($grandSgst, 2),
                'total_igst' => 0.0,
                'total_gst_cess' => 0.0,
                'total_gst' => round($grandCgst + $grandSgst, 2),
            ],
            'slabs' => $slabs,
            'hsn_summary' => $hsnSummary,
            'lines' => $lines,
        ]);
    }
}