<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use Illuminate\Contracts\Validation\Validator;

class UpdatePushBroadcastRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:500'],
            'image_id' => ['nullable', 'integer', 'exists:attachments,id'],
            'image_url' => ['nullable', 'string', 'max:1000'],
            'redirect_type' => ['nullable', 'string', 'max:50'],
            'redirect_target' => ['nullable', 'string', 'max:1000'],
            'schedule_type' => ['sometimes', 'in:immediate,once,daily,interval'],
            'scheduled_at' => ['nullable', 'date'],
            'daily_time' => ['nullable', 'date_format:H:i,H:i:s'],
            'interval_minutes' => ['nullable', 'integer', 'min:5', 'max:43200'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:scheduled,active,paused,completed,cancelled'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ExceptionHandler($validator->errors()->first(), 422);
    }
}
