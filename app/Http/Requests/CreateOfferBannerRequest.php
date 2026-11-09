<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class CreateOfferBannerRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'banner_image_id' => ['required', 'integer', 'exists:attachments,id'],
            'redirect_type' => ['required', 'in:product,category'],
            'redirect_id' => ['required', 'integer'],
            'store_id' => ['nullable', 'exists:stores,id'],
            'status' => ['required', 'min:0', 'max:1'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }
}
