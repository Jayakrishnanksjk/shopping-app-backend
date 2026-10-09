<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class CreatePushBroadcastRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:500'],
            'image_id' => ['nullable', 'integer', 'exists:attachments,id'],
            'image_url' => ['nullable', 'string', 'max:1000'],
            'redirect_type' => ['nullable', 'string', 'max:50'],
            'redirect_target' => ['nullable', 'string', 'max:1000'],
            'schedule_type' => ['required', 'in:immediate,once,daily,interval'],
            'scheduled_at' => ['nullable', 'date', 'required_if:schedule_type,once'],
            'daily_time' => ['nullable', 'date_format:H:i,H:i:s', 'required_if:schedule_type,daily'],
            'interval_minutes' => ['nullable', 'integer', 'min:5', 'max:43200', 'required_if:schedule_type,interval'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }
}
