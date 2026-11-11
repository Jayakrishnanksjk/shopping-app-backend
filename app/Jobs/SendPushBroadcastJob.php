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

        // Resolve target slug if redirect_type is product or category
        $slug = '';
        if (!empty($broadcast->redirect_type) && !empty($broadcast->redirect_target)) {
            if ($broadcast->redirect_type === 'product') {
                $slug = \App\Models\Product::where('id', $broadcast->redirect_target)->value('slug') ?? '';
            } elseif ($broadcast->redirect_type === 'category') {
                $slug = \App\Models\Category::where('id', $broadcast->redirect_target)->value('slug') ?? '';
            }
        }

        $data = [
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'broadcast_id' => (string) $broadcast->id,
            'type' => (string) ($broadcast->redirect_type ?: 'broadcast'),
            'redirect_type' => (string) ($broadcast->redirect_type ?? ''),
            'redirect_target' => (string) ($broadcast->redirect_target ?? ''),
            'target_id' => (string) ($broadcast->redirect_target ?? ''),
            'id' => (string) ($broadcast->redirect_target ?? ''),
            'slug' => (string) $slug,
        ];

        // Real push via shared FCM topic 'all_users' (single dispatch to all subscribed devices)
        $topicResult = $fcm->sendToTopic(FcmService::ALL_USERS_TOPIC, $broadcast->title, $broadcast->body, $imageUrl, $data);

        // In-app inbox for logged-in users (existing GET /api/notifications keeps working).
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
