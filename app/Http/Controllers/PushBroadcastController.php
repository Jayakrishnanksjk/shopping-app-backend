<?php

namespace App\Http\Controllers;

use Exception;
use App\Models\PushBroadcast;
use Illuminate\Http\Request;
use App\Http\Requests\CreatePushBroadcastRequest;
use App\Http\Requests\UpdatePushBroadcastRequest;
use App\GraphQL\Exceptions\ExceptionHandler;
use App\Repositories\Eloquents\PushBroadcastRepository;

class PushBroadcastController extends Controller
{
    protected $repository;

    public function __construct(PushBroadcastRepository $repository)
    {
        $this->authorizeResource(PushBroadcast::class, 'push_broadcast', [
            'except' => ['index', 'show'],
        ]);
        $this->repository = $repository;
    }

    public function index(Request $request)
    {
        try {
            $broadcasts = $this->filter($this->repository, $request);
            return $broadcasts->latest('created_at')->paginate($request->paginate ?? $broadcasts->count());
        } catch (Exception $e) {
            throw new ExceptionHandler($e->getMessage(), $e->getCode());
        }
    }

    public function store(CreatePushBroadcastRequest $request)
    {
        return $this->repository->store($request);
    }

    public function show(PushBroadcast $pushBroadcast)
    {
        return $this->repository->show($pushBroadcast->id);
    }

    public function update(UpdatePushBroadcastRequest $request, PushBroadcast $pushBroadcast)
    {
        return $this->repository->update($request, $pushBroadcast->id);
    }

    public function destroy(Request $request, PushBroadcast $pushBroadcast)
    {
        return $this->repository->destroy($pushBroadcast->id);
    }

    public function sendNow(PushBroadcast $pushBroadcast)
    {
        return $this->repository->sendNow($pushBroadcast->id);
    }

    public function pause(PushBroadcast $pushBroadcast)
    {
        return $this->repository->pause($pushBroadcast->id);
    }

    public function resume(PushBroadcast $pushBroadcast)
    {
        return $this->repository->resume($pushBroadcast->id);
    }

    public function deleteAll(Request $request)
    {
        return $this->repository->deleteAll($request->ids);
    }

    public function filter($broadcasts, $request)
    {
        if ($request->field && $request->sort) {
            $broadcasts = $broadcasts->orderBy($request->field, $request->sort);
        }
        if (isset($request->status)) {
            $broadcasts = $broadcasts->where('status', $request->status);
        }
        if (isset($request->schedule_type)) {
            $broadcasts = $broadcasts->where('schedule_type', $request->schedule_type);
        }
        return $broadcasts;
    }
}
