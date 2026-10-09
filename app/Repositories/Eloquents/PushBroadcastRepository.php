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
                SendPushBroadcastJob::dispatch($broadcast->id);
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
            SendPushBroadcastJob::dispatch($broadcast->id, true);
            return $broadcast->fresh();
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
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
