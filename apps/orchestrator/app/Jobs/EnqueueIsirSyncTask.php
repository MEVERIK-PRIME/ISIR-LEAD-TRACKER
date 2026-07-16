<?php

namespace App\Jobs;

use App\Models\SyncCheckpoint;
use App\Services\Isir\WorkerTaskDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class EnqueueIsirSyncTask implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $payload,
    )
    {
    }

    /**
     * Execute the job.
     */
    public function handle(WorkerTaskDispatcher $dispatcher): void
    {
        if (! isset($this->payload['task_id'], $this->payload['task_type'], $this->payload['checkpoint'], $this->payload['provider'], $this->payload['stream'])) {
            throw new InvalidArgumentException('Worker task payload is missing required keys.');
        }

        $dispatcher->dispatch($this->payload);

        $checkpoint = SyncCheckpoint::query()
            ->forStream((string) $this->payload['provider'], (string) $this->payload['stream'])
            ->first();

        if ($checkpoint === null) {
            return;
        }

        $checkpoint->forceFill([
            'status' => 'dispatched',
            'last_seen_reference' => (string) $this->payload['checkpoint'],
            'meta' => array_merge($checkpoint->meta ?? [], [
                'last_dispatched_task_id' => $this->payload['task_id'],
                'last_dispatched_at' => Carbon::now()->toIso8601String(),
                'last_dispatched_mode' => $this->payload['mode'] ?? 'incremental',
            ]),
        ])->save();
    }
}
