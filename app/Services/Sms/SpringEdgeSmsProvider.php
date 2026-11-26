<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SpringEdgeSmsProvider implements SmsProviderInterface
{
    public function send(string $phone, string $message): bool
    {
        $apiKey = config('services.springedge.api_key') ?: (env('SPRING_EDGE_API_KEY') ?: env('SPRINGEDGE_API_KEY'));
        $sender = config('services.springedge.sender') ?: (env('SPRING_EDGE_SENDER') ?: env('SPRINGEDGE_SENDER'));
        $dltTemplateId = config('services.springedge.dlt_template_id') ?: (env('SPRING_EDGE_DLT_TEMPLATE_ID') ?: env('SPRINGEDGE_DLT_TEMPLATE_ID'));
        $dltEntityId = config('services.springedge.dlt_entity_id') ?: (env('SPRING_EDGE_DLT_ENTITY_ID') ?: env('SPRINGEDGE_DLT_ENTITY_ID'));

        if (empty($apiKey) || empty($sender)) {
            Log::warning("Spring Edge SMS not configured. Logging message: " . $message);
            $logPath = storage_path('logs/otps.txt');
            $timestamp = now()->toDateTimeString();
            file_put_contents($logPath, "[{$timestamp}] (Fallback) SMS to {$phone}: {$message}" . PHP_EOL, FILE_APPEND);
            return true;
        }

        try {
            $url = 'https://instantalerts.co/api/web/send';

            $formattedPhone = $phone;
            if (!str_starts_with($formattedPhone, '91') && strlen($formattedPhone) == 10) {
                $formattedPhone = '91' . $formattedPhone;
            }

            $params = [
                'apikey'  => $apiKey,
                'sender'  => $sender,
                'to'      => $formattedPhone,
                'message' => $message,
            ];

            if (!empty($dltEntityId)) {
                $params['entity_id'] = $dltEntityId;
            }

            if (!empty($dltTemplateId)) {
                $params['template_id'] = $dltTemplateId;
                $params['dlttemplateid'] = $dltTemplateId;
                $params['header_id'] = $dltTemplateId;
            }

            $fullUrl = $url . '?' . http_build_query($params);
            Log::info("Spring Edge URL: " . $fullUrl);

            $response = Http::timeout(10)->get($url, $params);

            $responseBody = $response->body();
            Log::info("Spring Edge response: " . $responseBody);

            if ($response->successful()) {
                Log::info("SMS successfully sent via Spring Edge to " . $formattedPhone);
                return true;
            }

            Log::error("Spring Edge SMS failed. Status: " . $response->status() . " Body: " . $responseBody);
            return false;

        } catch (\Exception $e) {
            Log::error("Spring Edge SMS exception: " . $e->getMessage());
            return false;
        }
    }
}
