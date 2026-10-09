<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\DeviceToken;
use App\Models\PushBroadcast;
use App\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $broadcastId, public bool $manual = false)
    {
    }

    public function handle(FcmService $fcm): void
    {
        $broadcast = PushBroadcast::find($this->broadcastId);
        if (!$broadcast) {
            return;
        }
        if (!$this->manual && in_array($broadcast->status, [PushBroadcast::STATUS_PAUSED, PushBroadcast::STATUS_COMPLETED, PushBroadcast::STATUS_CANCELLED], true)) {
            return;
        }
        if (!$this->manual && $broadcast->next_run_at && $broadcast->next_run_at->isFuture()) {
            return;
        }

        $broadcast->loadMissing('image');
        $imageUrl = $broadcast->resolved_image_url;
        $data = [
            'broadcast_id' => (string) $broadcast->id,
            'type' => 'broadcast',
            'redirect_type' => (string) ($broadcast->redirect_type ?? ''),
            'redirect_target' => (string) ($broadcast->redirect_target ?? ''),
        ];

        // 1) Real push (works even when app is closed) via shared topic.
        $topicResult = $fcm->sendToTopic(FcmService::ALL_USERS_TOPIC, $broadcast->title, $broadcast->body, $imageUrl, $data);

        // 2) Direct token multicast as backup (users who missed topic subscription).
        $tokenResult = ['success' => 0, 'failure' => 0, 'mode' => $topicResult['mode'] ?? 'log'];
        if ($fcm->isConfigured()) {
            DeviceToken::where('is_active', true)->select('token')->chunkById(1000, function ($rows) use ($fcm, $broadcast, $imageUrl, $data, &$tokenResult) {
                $r = $fcm->sendToTokens($rows->pluck('token')->all(), $broadcast->title, $broadcast->body, $imageUrl, $data);
                $tokenResult['success'] += $r['success'];
                $tokenResult['failure'] += $r['failure'];
            });
        }

        // 3) In-app inbox for logged-in users (existing GET /api/notifications keeps working).
        $this->storeInboxCopies($broadcast);

        // 4) Advance schedule.
        $broadcast->last_sent_at = now();
        $broadcast->sent_count = ($broadcast->sent_count ?? 0) + 1;

        if ($broadcast->schedule_type === PushBroadcast::SCHEDULE_IMMEDIATE || $broadcast->schedule_type === PushBroadcast::SCHEDULE_ONCE || $this->manualOnce($broadcast)) {
            if (!$broadcast->isRecurring()) {
                $broadcast->status = PushBroadcast::STATUS_COMPLETED;
                $broadcast->next_run_at = null;
            }
        }
        if ($broadcast->isRecurring() && $broadcast->status !== PushBroadcast::STATUS_PAUSED) {
            $dailyTime = $broadcast->daily_time
                ? \Carbon\Carbon::parse($broadcast->daily_time)->format('H:i:s')
                : null;
            $next = PushBroadcast::computeNextRunAt([
                'schedule_type' => $broadcast->schedule_type,
                'daily_time' => $dailyTime,
                'interval_minutes' => $broadcast->interval_minutes,
                'starts_at' => $broadcast->starts_at,
                'ends_at' => $broadcast->ends_at,
                'last_sent_at' => $broadcast->last_sent_at,
            ], now());
            if ($next) {
                $broadcast->next_run_at = $next;
                $broadcast->status = PushBroadcast::STATUS_ACTIVE;
            } else {
                $broadcast->next_run_at = null;
                $broadcast->status = PushBroadcast::STATUS_COMPLETED;
            }
        }

        $broadcast->save();

        Log::info('Push broadcast sent', [
            'id' => $broadcast->id,
            'topic' => $topicResult,
            'tokens' => $tokenResult,
        ]);
    }

    protected function manualOnce(PushBroadcast $broadcast): bool
    {
        return $this->manual && !$broadcast->isRecurring();
    }

    /** Bulk-insert database notifications in chunks (fast for large user bases). */
    protected function storeInboxCopies(PushBroadcast $broadcast): void
    {
        $payload = [
            'title' => $broadcast->title,
            'message' => $broadcast->body,
            'image' => $broadcast->resolved_image_url,
            'type' => 'broadcast',
            'broadcast_id' => $broadcast->id,
            'redirect_type' => $broadcast->redirect_type,
            'redirect_target' => $broadcast->redirect_target,
        ];
        User::select('id')->chunkById(500, function ($users) use ($payload) {
            $now = now()->toDateTimeString();
            $rows = $users->map(fn ($u) => [
                'id' => (string) Str::uuid(),
                'type' => \App\Notifications\BroadcastPushNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $u->id,
                'data' => json_encode($payload),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            DB::table('notifications')->insert($rows);
        });
    }
}
