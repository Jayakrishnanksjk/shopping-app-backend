<?php

namespace App\Repositories\Eloquents;

use Exception;
use App\Models\PushBroadcast;
use App\Helpers\Helpers;
use App\Jobs\SendPushBroadcastJob;
use Illuminate\Support\Facades\DB;
use App\GraphQL\Exceptions\ExceptionHandler;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Criteria\RequestCriteria;

class PushBroadcastRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'title' => 'like',
        'status' => '=',
        'schedule_type' => '=',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (ExceptionHandler $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    function model()
    {
        return PushBroadcast::class;
    }

    public function show($id)
    {
        try {
            return $this->model->findOrFail($id);
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function store($request)
    {
        DB::beginTransaction();
        try {
            $data = $request->only([
                'title', 'body', 'image_id', 'image_url', 'redirect_type', 'redirect_target',
                'schedule_type', 'scheduled_at', 'daily_time', 'interval_minutes', 'starts_at', 'ends_at',
            ]);
            $data['created_by_id'] = Helpers::getCurrentUserId();
            $data['sent_count'] = 0;
            $data['status'] = $data['schedule_type'] === PushBroadcast::SCHEDULE_IMMEDIATE
                ? PushBroadcast::STATUS_ACTIVE
                : PushBroadcast::STATUS_SCHEDULED;
            $data['next_run_at'] = PushBroadcast::computeNextRunAt($data, now());
            if (in_array($data['schedule_type'], [PushBroadcast::SCHEDULE_ONCE]) && !$data['next_run_at']) {
                throw new Exception('scheduled_at is required for one-time broadcasts', 422);
            }

            $broadcast = $this->model->create($data);
            DB::commit();

            if ($broadcast->schedule_type === PushBroadcast::SCHEDULE_IMMEDIATE) {
                SendPushBroadcastJob::dispatchSync($broadcast->id);
            }

            return $broadcast->fresh();
        } catch (Exception $e) {
            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    public function update($request, $id)
    {
        DB::beginTransaction();
        try {
            $broadcast = $this->model->findOrFail($id);
            if (is_array($request)) {
                $data = $request;
            } else {
                $data = $request->only([
                    'title', 'body', 'image_id', 'image_url', 'redirect_type', 'redirect_target',
                    'schedule_type', 'scheduled_at', 'daily_time', 'interval_minutes',
                    'starts_at', 'ends_at', 'status',
                ]);
                $data = array_filter($data, fn ($v) => !is_null($v));
            }
            $merged = array_merge($broadcast->toArray(), $data);
            // Recompute next run when schedule fields or resume change.
            if (array_intersect_key($data, array_flip(['schedule_type', 'scheduled_at', 'daily_time', 'interval_minutes', 'starts_at', 'ends_at', 'status']))) {
                if (($data['status'] ?? $broadcast->status) !== PushBroadcast::STATUS_PAUSED
                    && ($data['status'] ?? $broadcast->status) !== PushBroadcast::STATUS_CANCELLED) {
                    $merged['last_sent_at'] = $broadcast->last_sent_at;
                    $next = PushBroadcast::computeNextRunAt($merged, now());
                    $data['next_run_at'] = $next;
                    if (!isset($data['status'])) {
                        $data['status'] = $broadcast->isRecurring()
                            ? PushBroadcast::STATUS_ACTIVE
                            : PushBroadcast::STATUS_SCHEDULED;
                    }
                }
            }
            $broadcast->update($data);
            DB::commit();
            return $broadcast->fresh();
        } catch (Exception $e) {
            DB::rollback();
            throw new ExceptionHandler($e->getMessage(), $e->getCode() ?: 422);
        }
    }

    public function destroy($id)
    {
        try {
            return $this->model->findOrFail($id)->delete();
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function deleteAll($ids)
    {
        try {
            return $this->model->whereIn('id', $ids)->delete();
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function sendNow($id)
    {
        try {
            $broadcast = $this->model->findOrFail($id);

            // Run synchronously so we can capture FCM result and return feedback.
            $fcm = app(\App\Services\FcmService::class);

            $broadcast->loadMissing('image');
            $imageUrl = $broadcast->resolved_image_url;

            // Resolve target details if redirect_type is product or category
            $slug = '';
            $categoryName = '';
            $categoryId = '';
            if (!empty($broadcast->redirect_type) && !empty($broadcast->redirect_target)) {
                if ($broadcast->redirect_type === 'product') {
                    $slug = \App\Models\Product::where('id', $broadcast->redirect_target)->value('slug') ?? '';
                } elseif ($broadcast->redirect_type === 'category') {
                    $category = \App\Models\Category::find($broadcast->redirect_target);
                    if ($category) {
                        $slug = $category->slug ?? '';
                        $categoryName = $category->name ?? '';
                        $categoryId = (string) $category->id;
                    }
                }
            }

            $data = [
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'broadcast_id' => (string) $broadcast->id,
                'type'          => (string) ($broadcast->redirect_type ?: 'broadcast'),
                'redirect_type'   => (string) ($broadcast->redirect_type ?? ''),
                'redirect_target' => (string) ($broadcast->redirect_target ?? ''),
                'target_id'       => (string) ($broadcast->redirect_target ?? ''),
                'id'              => (string) ($broadcast->redirect_target ?? ''),
                'slug'            => (string) $slug,
                'category_id'     => (string) ($categoryId ?: $broadcast->redirect_target),
                'category_slug'   => (string) $slug,
                'category_name'   => (string) $categoryName,
            ];

            // Send push via shared FCM topic 'all_users' (single dispatch to all subscribed devices)
            $topicResult = $fcm->sendToTopic(
                \App\Services\FcmService::ALL_USERS_TOPIC,
                $broadcast->title,
                $broadcast->body,
                $imageUrl,
                $data
            );

            // In-app inbox copies for all users
            $this->storeInboxCopies($broadcast);

            // Update broadcast status
            $broadcast->last_sent_at = now();
            $broadcast->sent_count = ($broadcast->sent_count ?? 0) + 1;
            if (!$broadcast->isRecurring()) {
                $broadcast->status = PushBroadcast::STATUS_COMPLETED;
                $broadcast->next_run_at = null;
            } elseif ($broadcast->isRecurring() && $broadcast->status !== PushBroadcast::STATUS_PAUSED) {
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

            // Determine overall success/failure
            $topicSuccess = ($topicResult['success'] ?? 0) > 0;
            $fcmConfigured = $fcm->isConfigured();

            // Build detailed status message
            if (!$fcmConfigured) {
                $status = 'warning';
                $message = 'Firebase is not configured. Notification was saved to user inboxes only (no push sent to phones). Reason: ' . ($fcm->bootError() ?: 'Missing Firebase credentials.');
            } elseif ($topicSuccess) {
                $message = "Notification sent successfully via FCM topic 'all_users'.";
                $status = 'success';
            } else {
                $status = 'error';
                $message = 'FCM topic send FAILED. Reason: ' . ($topicResult['reason'] ?? 'Unknown error') . '. Notification was saved to user inboxes only.';
            }

            \Illuminate\Support\Facades\Log::info('Push broadcast sendNow result', [
                'broadcast_id'   => $broadcast->id,
                'status'         => $status,
                'message'        => $message,
                'topic_result'   => $topicResult,
                'fcm_configured' => $fcmConfigured,
            ]);

            return response()->json([
                'success' => $status !== 'error',
                'status'  => $status,
                'message' => $message,
                'data'    => $broadcast->fresh(),
                'debug'   => [
                    'fcm_configured' => $fcmConfigured,
                    'topic_result'   => $topicResult,
                ],
            ]);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('Push broadcast sendNow failed', [
                'broadcast_id' => $id,
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    /** Bulk-insert database notifications in chunks (used by sendNow and the job). */
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
        \App\Models\User::select('id')->chunkById(500, function ($users) use ($payload) {
            $now = now()->toDateTimeString();
            $rows = $users->map(fn ($u) => [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => \App\Notifications\BroadcastPushNotification::class,
                'notifiable_type' => \App\Models\User::class,
                'notifiable_id' => $u->id,
                'data' => json_encode($payload),
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();
            DB::table('notifications')->insert($rows);
        });
    }

    public function pause($id)
    {
        return $this->update(['status' => PushBroadcast::STATUS_PAUSED], $id);
    }

    public function resume($id)
    {
        $broadcast = $this->model->findOrFail($id);
        $data = ['status' => $broadcast->isRecurring() ? PushBroadcast::STATUS_ACTIVE : PushBroadcast::STATUS_SCHEDULED];
        $merged = array_merge($broadcast->toArray(), $data);
        $merged['last_sent_at'] = $broadcast->last_sent_at;
        $data['next_run_at'] = PushBroadcast::computeNextRunAt($merged, now());
        return $this->update($data, $id);
    }
}
