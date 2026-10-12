<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    /** 
     * Determine if the user is authorized to make this request. 
     */
    public function authorize()
    {
        return true;
    }

    /** Get the validation rules that apply to the request. */
    public function rules()
    {
        return [
            'order_number' => 'required|string|exists:orders,order_number',
            'scope' => 'required|in:full,partial',
            'reason' => 'required|string',
            'payment_type' => 'nullable|string',
            'items' => 'required_if:scope,partial|array',
            'items.*.product_id' => 'required_if:scope,partial',
            'items.*.variation_id' => 'nullable',
            'items.*.quantity' => 'required_if:scope,partial|integer|min:1',
            'items.*.subtotal' => 'required_if:scope,partial|numeric|min:0',
        ];
    }
}
