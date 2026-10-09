<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmService
{
    public const ALL_USERS_TOPIC = 'all_users';

    protected ?Messaging $messaging = null;
    protected bool $bootFailed = false;
    protected string $bootError = '';

    protected function messaging(): ?Messaging
    {
        if ($this->messaging || $this->bootFailed) {
            return $this->messaging;
        }
        try {
            if (!interface_exists(\Kreait\Firebase\Contract\Messaging::class) && !class_exists(\Kreait\Firebase\Contract\Messaging::class)) {
                $this->bootFailed = true;
                $this->bootError = 'kreait/firebase-php not installed';
                return null;
            }
            $factory = (new \Kreait\Firebase\Factory);
            $credentials = config('services.firebase.credentials');
            $projectId = config('services.firebase.project_id');
            if ($credentials && file_exists($credentials)) {
                $factory = $factory->withServiceAccount($credentials);
            }
            if ($projectId) {
                $factory = $factory->withProjectId($projectId);
            }
            $this->messaging = $factory->createMessaging();
        } catch (\Throwable $e) {
            $this->bootFailed = true;
            $this->bootError = $e->getMessage();
            Log::warning('FCM boot failed: ' . $e->getMessage());
        }
        return $this->messaging;
    }

    public function isConfigured(): bool
    {
        return $this->messaging() !== null;
    }

    public function bootError(): string
    {
        $this->messaging();
        return $this->bootError;
    }

    protected function buildMessage(string $title, string $body, ?string $imageUrl, array $data): CloudMessage
    {
        $notification = Notification::create($title, $body, $imageUrl ?: null);
        $message = CloudMessage::new()->withNotification($notification)->withData($data);
        // High priority + default channel so Android shows even when app is closed.
        $apns = ['payload' => ['aps' => ['mutable-content' => 1, 'sound' => 'default']]];
        if ($imageUrl) {
            $apns['fcm_options'] = ['image' => $imageUrl];
        }
        $message = $message->withAndroidConfig([
            'priority' => 'high',
            'notification' => array_filter([
                'channel_id' => 'default',
                'image' => $imageUrl,
            ]),
        ])->withApnsConfig($apns);
        return $message;
    }

    /** Push to the shared topic. Reaches logged-out guests too (app subscribes on launch). */
    public function sendToTopic(string $topic, string $title, string $body, ?string $imageUrl = null, array $data = []): array
    {
        $data = $this->stringifyData($data);
        if (!$this->messaging()) {
            Log::info('[FCM:log-mode] topic=' . $topic . ' title=' . $title . ' body=' . $body);
            return ['success' => 0, 'failure' => 0, 'mode' => 'log', 'reason' => $this->bootError];
        }
        try {
            $message = $this->buildMessage($title, $body, $imageUrl, $data)->withChangedTarget('topic', $topic);
            $this->messaging()->send($message);
            return ['success' => 1, 'failure' => 0, 'mode' => 'fcm'];
        } catch (\Throwable $e) {
            Log::error('FCM topic send failed: ' . $e->getMessage());
            return ['success' => 0, 'failure' => 1, 'mode' => 'fcm', 'reason' => $e->getMessage()];
        }
    }

    /**
     * Multicast to stored device tokens in chunks of 500.
     * Invalid (unregistered) tokens are deactivated automatically.
     */
    public function sendToTokens(array $tokens, string $title, string $body, ?string $imageUrl = null, array $data = []): array
    {
        $data = $this->stringifyData($data);
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (empty($tokens)) {
            return ['success' => 0, 'failure' => 0, 'mode' => $this->isConfigured() ? 'fcm' : 'log'];
        }
        if (!$this->messaging()) {
            Log::info('[FCM:log-mode] tokens=' . count($tokens) . ' title=' . $title);
            return ['success' => 0, 'failure' => 0, 'mode' => 'log', 'reason' => $this->bootError];
        }
        $success = 0;
        $failure = 0;
        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $message = $this->buildMessage($title, $body, $imageUrl, $data);
                $report = $this->messaging()->sendMulticast($message, $chunk);
                $success += $report->successes()->count();
                $failure += $report->failures()->count();
                // Deactivate dead tokens so future sends stay cheap.
                foreach ($report->failures()->getItems() as $failed) {
                    $reason = strtolower($failed->error()?->getMessage() ?? '');
                    if (str_contains($reason, 'not-registered') || str_contains($reason, 'unregistered') || str_contains($reason, 'invalid-registration')) {
                        DeviceToken::where('token', $failed->target()->value())->update(['is_active' => false]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('FCM multicast failed: ' . $e->getMessage());
                $failure += count($chunk);
            }
        }
        return ['success' => $success, 'failure' => $failure, 'mode' => 'fcm'];
    }

    protected function stringifyData(array $data): array
    {
        // FCM data values must be strings.
        return collect($data)->map(fn ($v) => is_string($v) ? $v : (string) json_encode($v))->toArray();
    }
}
