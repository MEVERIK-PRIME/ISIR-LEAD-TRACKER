<?php

namespace Tests\Feature;

use App\Jobs\EnqueueIsirSyncTask;
use App\Models\SyncCheckpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchIsirSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_a_dry_run_payload(): void
    {
        Queue::fake();

        SyncCheckpoint::query()->create([
            'provider' => 'isir_public_ws',
            'stream' => 'events',
            'checkpoint_value' => '42',
            'status' => 'idle',
        ]);

        $this->artisan('isir:dispatch-sync', [
            '--dry-run' => true,
            '--limit' => 125,
        ])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_it_queues_a_worker_handoff_job_and_updates_checkpoint_state(): void
    {
        Queue::fake();

        $checkpoint = SyncCheckpoint::query()->create([
            'provider' => 'isir_public_ws',
            'stream' => 'events',
            'checkpoint_value' => '9001',
            'status' => 'idle',
        ]);

        $this->artisan('isir:dispatch-sync', [
            '--limit' => 50,
        ])->assertSuccessful();

        Queue::assertPushed(EnqueueIsirSyncTask::class, function (EnqueueIsirSyncTask $job) {
            return $job->payload['task_type'] === 'isir.sync.events'
                && $job->payload['checkpoint'] === '9001'
                && $job->payload['limit'] === 50
                && $job->payload['provider'] === 'isir_public_ws'
                && $job->payload['stream'] === 'events';
        });

        $checkpoint->refresh();

        $this->assertSame('queued', $checkpoint->status);
        $this->assertSame('incremental', $checkpoint->meta['last_enqueued_mode']);
        $this->assertSame(50, $checkpoint->meta['last_requested_limit']);
    }
}
