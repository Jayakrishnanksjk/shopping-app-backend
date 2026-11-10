<!DOCTYPE html>
<html>
<head>
    <title>{{ $settings['general']['site_title'] ?? 'Invoice' }}</title>
</head>
<style type="text/css">
    body{ font-family: 'DejaVu Sans', 'Roboto Condensed', sans-serif; }
    .m-0{ margin:0px; } .p-0{ padding:0px; } .pt-5{ padding-top:5px; }
    .mt-10{ margin-top:10px; } .text-center{ text-align:center !important; }
    .w-100{ width:100%; } .w-50{ width:50%; } .w-85{ width:85%; } .w-15{ width:15%; }
    .logo img{ width:200px; height:60px; }
    .gray-color{ color:#52a750a4; } .text-bold{ font-weight:bold; }
    table tr,th,td{ border:1px solid #d2d2d2; border-collapse:collapse; padding:7px 8px; }
    table tr th{ background:#F4F4F4; font-size:15px; }
    table tr td{ font-size:13px; } table{ border-collapse:collapse; }
    .box-text p{ line-height:10px; } .float-left{ float:left; }
    .total-part{ font-size:14px; line-height:14px; }
    .total-right p{ padding-right:20px; }
    .label-col{ width:70%; text-align:right; padding-right:10px; }
    .value-col{ width:30%; text-align:right; font-weight:bold; }
    .gst-label{ color:#555; font-size:12px; }
</style>
<body>
<div class="head-title">
    <h1 class="text-center m-0 p-0">Tax Invoice</h1>
</div>
<div class="add-detail mt-10">
    <div class="w-50 float-left mt-10">
        <p class="m-0 pt-5 text-bold w-100">Order No - <span class="gray-color">{{$orders->order_number}}</span></p>
        <p class="m-0 pt-5 text-bold w-100">Order Date - <span class="gray-color">{{$orders->created_at->format("d/m/Y")}}</span></p>
        <p class="m-0 pt-5 text-bold w-100">Payment Method - <span class="gray-color">{{$orders->payment_method}}</span></p>
    </div>
    <div style="clear:both;"></div>
</div>
<div class="table-section bill-tbl w-100 mt-10">
    <table class="table w-100 mt-10">
        <tr>
            <th class="w-50">Billing Address</th>
            <th class="w-50">Shipping Address</th>
        </tr>
        <tr>
            <td>
                <div class="box-text">
                    <p>{{$orders->billing_address->street}}</p>
                    <p>{{$orders->billing_address->pincode}},</p>
                    <p>{{$orders->billing_address->city}},</p>
                    <p>{{$orders->billing_address?->state->name}}, {{$orders->billing_address?->country->name}}</p>
                    <p>Contact: ({{$orders->billing_address?->country_code}}) {{$orders->billing_address?->phone}}</p>
                </div>
            </td>
            <td>
                <div class="box-text" style="width:68%;float:left;">
                    <p>{{$orders->shipping_address->street}}</p>
                    <p>{{$orders->shipping_address->pincode}},</p>
                    <p>{{$orders->shipping_address->city}},</p>
                    <p>{{$orders->shipping_address?->state->name}}, {{$orders->shipping_address?->country->name}}</p>
                    <p>Contact: ({{$orders->shipping_address?->country_code}}) {{$orders->shipping_address?->phone}}</p>
                </div>
                @php
                    // 1. Retrieve coordinates from shipping address or root order
                    $lat = $orders->shipping_address?->latitude 
                        ?? $orders->shipping_address?->lat 
                        ?? $orders->latitude 
                        ?? null;
                    $lng = $orders->shipping_address?->longitude 
                        ?? $orders->shipping_address?->lng 
                        ?? $orders->longitude 
                        ?? null;

                    // 2. Fallback check for comma-separated coordinate strings ("lat,lng")
                    if (!$lat || !$lng) {
                        $coordStr = $orders->shipping_address?->screen_geo_coordinates 
                            ?? $orders->shipping_address?->geo_coordinates 
                            ?? $orders->screen_geo_coordinates 
                            ?? null;
                        if ($coordStr && str_contains($coordStr, ',')) {
                            [$lat, $lng] = explode(',', $coordStr);
                            $lat = trim($lat);
                            $lng = trim($lng);
                        }
                    }

                    // 3. Generate Turn-by-Turn Navigation URL (or generic search fallback)
                    if (!empty($lat) && !empty($lng)) {
                        $mapUrl = "https://www.google.com/maps/dir/?api=1&destination={$lat},{$lng}";
                    } else {
                        $shippingAddressString = implode(', ', array_filter([
                            $orders->shipping_address?->street,
                            $orders->shipping_address?->city,
                            $orders->shipping_address?->state?->name,
                            $orders->shipping_address?->country?->name,
                            $orders->shipping_address?->pincode,
                        ]));
                        $mapUrl = !empty($shippingAddressString) 
                            ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($shippingAddressString) 
                            : url("/orders/{$orders->order_number}");
                    }
                @endphp
                @if($mapUrl)
                <div style="width:30%;float:right;text-align:center;">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=85x85&data={{ urlencode($mapUrl) }}" alt="Map QR" style="width:75px;height:75px;" />
                    <p style="font-size:9px;color:#555;margin:2px 0 0 0;line-height:11px;">Scan for Delivery Location</p>
                </div>
                @endif
                <div style="clear:both;"></div>
            </td>
        </tr>
    </table>
</div>
<div class="table-section bill-tbl w-100 mt-10">
    <table class="table w-100 mt-10">
        <tr>
            <th>No</th>
            <th>Product Name</th>
            <th>HSN</th>
            <th>GST%</th>
            <th>Qty</th>
            <th>Unit Price (Incl.)</th>
            <th>Taxable Value</th>
            <th>GST Amt</th>
            <th>Shipping</th>
            <th>Line Total</th>
        </tr>
        @php
            $grandTaxableTotal = 0;
            $grandGstTotal     = 0;
        @endphp
        @foreach ($orders->products as $no => $product)
        @php
            $pivotTax      = $product->pivot->tax ?? 0;
            $pivotSubtotal = $product->pivot->subtotal ?? 0;
            $pivotShipping = $product->pivot->shipping_cost ?? 0;
            $pivotQty      = $product->pivot->quantity ?? 1;
            $pivotUnitPrice= $product->pivot->single_price ?? 0;
            
            // Fetch product's defined tax rate
            $gstRate       = (float) ($product->tax->rate ?? 0);

            // If pivot tax is not populated (e.g. legacy orders or pre-save states), back-calculate
            if ($pivotTax <= 0 && $gstRate > 0) {
                $actualInclusive = $pivotUnitPrice * $pivotQty;
                $expectedInclusive = $pivotSubtotal * (1 + $gstRate / 100);

                if (abs($expectedInclusive - $actualInclusive) < 1.0) {
                    // New order, subtotal is already taxable value
                    $pivotTax = $actualInclusive - $pivotSubtotal;
                } else {
                    // Legacy order, subtotal is GST-inclusive
                    $pivotTax = ($pivotSubtotal * $gstRate) / (100 + $gstRate);
                    $pivotSubtotal = $pivotSubtotal - $pivotTax;
                }
            }

            $lineTotal     = $pivotSubtotal + $pivotTax + $pivotShipping;
            $hsnCode       = $product->hsn_code ?? '-';
            $grandTaxableTotal += $pivotSubtotal;
            $grandGstTotal     += $pivotTax;
        @endphp
        <tr align="center">
            <td>{{++$no}}</td>
            <td align="left">{{$product->name}}</td>
            <td>{{$hsnCode}}</td>
            <td>{{$gstRate}}%</td>
            <td>{{$pivotQty}}</td>
            <td>&#8377; {{number_format($pivotUnitPrice, 2)}}</td>
            <td>&#8377; {{number_format($pivotSubtotal, 2)}}</td>
            <td>&#8377; {{number_format($pivotTax, 2)}}</td>
            <td>&#8377; {{number_format($pivotShipping, 2)}}</td>
            <td>&#8377; {{number_format($lineTotal, 2)}}</td>
        </tr>
        @endforeach
        <tr>
            <td colspan="10">
                <div class="total-part">
                    <table style="width:100%;border:none;">
                        <tr>
                            <td class="label-col gst-label">Taxable Value</td>
                            <td class="value-col">&#8377; {{number_format($grandTaxableTotal, 2)}}</td>
                        </tr>
                        <tr>
                            <td class="label-col gst-label">CGST</td>
                            <td class="value-col">&#8377; {{number_format($grandGstTotal / 2, 2)}}</td>
                        </tr>
                        <tr>
                            <td class="label-col gst-label">SGST</td>
                            <td class="value-col">&#8377; {{number_format($grandGstTotal / 2, 2)}}</td>
                        </tr>
                        <tr>
                            <td class="label-col gst-label">Total GST</td>
                            <td class="value-col">&#8377; {{number_format($grandGstTotal, 2)}}</td>
                        </tr>
                        <tr>
                            <td class="label-col gst-label">Shipping</td>
                            <td class="value-col">&#8377; {{number_format($orders->shipping_total ?? 0, 2)}}</td>
                        </tr>
                        <tr style="border-top:2px solid #333;">
                            <td class="label-col text-bold" style="font-size:15px;">Invoice Total</td>
                            <td class="value-col" style="font-size:15px;">&#8377; {{number_format($orders->total, 2)}}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>
    </table>
</div>
</html>