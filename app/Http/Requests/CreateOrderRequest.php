<?php

namespace App\Http\Requests;

use App\Enums\RoleEnum;
use App\Helpers\Helpers;
use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class CreateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $roleName = Helpers::getCurrentRoleName();
        $order = [
            'consumer_id' => ['required','nullable','exists:users,id,deleted_at,NULL'],
            'products' => ['required','array'],
            'products.*.product_id' => ['required','exists:products,id,deleted_at,NULL'],
            'products.*.variation_id' => ['nullable','exists:variations,id'],
            'coupon' => ['nullable','exists:coupons,code,deleted_at,NULL'],
            'billing_address_id' => ['required', 'exists:addresses,id,deleted_at,NULL'],
            'shipping_address_id'=>['required', 'exists:addresses,id,deleted_at,NULL'],
            'payment_method' => ['string', 'in:razorpay,phonepe,instamojo,paypal,stripe,mollie,ccavenue,cod,wallet'],
            'delivery_interval' => ['nullable','string'],
        ];

        if ($roleName == RoleEnum::CONSUMER) {
            return array_merge($order, ['consumer_id' => ['exists:users,id,deleted_at,NULL']]);
        }

        return $order;
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }
}
