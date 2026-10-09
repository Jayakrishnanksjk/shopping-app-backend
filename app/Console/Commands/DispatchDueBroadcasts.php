<?php

namespace App\Console\Commands;

use App\Jobs\SendPushBroadcastJob;
use App\Models\PushBroadcast;
use Illuminate\Console\Command;

class DispatchDueBroadcasts extends Command
{
    protected $signature = 'broadcasts:dispatch-due';
    protected $description = 'Dispatch push broadcasts whose next_run_at is due (once / daily / interval)';

    public function handle(): int
    {
        $now = now();
        $due = PushBroadcast::whereIn('status', [PushBroadcast::STATUS_SCHEDULED, PushBroadcast::STATUS_ACTIVE])
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->pluck('id');

        foreach ($due as $id) {
            SendPushBroadcastJob::dispatch($id);
        }

        // Auto-complete expired one-shot / recurring campaigns so the admin list stays clean.
        PushBroadcast::whereIn('status', [PushBroadcast::STATUS_SCHEDULED, PushBroadcast::STATUS_ACTIVE])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $now)
            ->update(['status' => PushBroadcast::STATUS_COMPLETED]);

        $this->info("Dispatched {$due->count()} due broadcast(s).");
        return self::SUCCESS;
    }
}
