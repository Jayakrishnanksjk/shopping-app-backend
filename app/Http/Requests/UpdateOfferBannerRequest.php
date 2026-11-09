<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class UpdateOfferBannerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'  => ['nullable', 'string', 'max:255'],
            'banner_image_id' => ['nullable', 'integer', 'exists:attachments,id'],
            'redirect_type' => ['nullable', 'in:product,category'],
            'redirect_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'exists:stores,id'],
            'status' => ['nullable', 'min:0', 'max:1'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }
}
