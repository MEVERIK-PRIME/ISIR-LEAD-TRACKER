<?php

namespace App\Console\Commands;

use App\Jobs\EnqueueIsirSyncTask;
use App\Models\SyncCheckpoint;
use App\Support\Isir\WorkerTaskPayload;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

#[Signature('isir:dispatch-sync
    {--provider= : Sync provider key}
    {--stream= : Sync stream name}
    {--from= : Override starting checkpoint value}
    {--limit= : Number of source events to request}
    {--backfill : Dispatch the task in backfill mode}
    {--dry-run : Render the payload without dispatching the queue handoff job}')]
#[Description('Build and dispatch an ISIR incremental or backfill sync payload for the Python worker.')]
class DispatchIsirSyncCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $provider = (string) ($this->option('provider') ?: config('isir.sync.default_provider'));
        $stream = (string) ($this->option('stream') ?: config('isir.sync.default_stream'));
        $limit = (int) ($this->option('limit') ?: config('isir.sync.batch_size'));

        if ($limit < 1) {
            $this->error('The sync batch limit must be a positive integer.');

            return self::INVALID;
        }

        $checkpoint = SyncCheckpoint::query()->firstOrCreate(
            [
                'provider' => $provider,
                'stream' => $stream,
            ],
            [
                'checkpoint_value' => '0',
                'status' => 'idle',
                'meta' => [],
            ],
        );

        $mode = $this->option('backfill') ? 'backfill' : 'incremental';
        $startingCheckpoint = (string) ($this->option('from') ?: $checkpoint->checkpoint_value ?: '0');

        $payload = WorkerTaskPayload::forIsirSync(
            provider: $provider,
            stream: $stream,
            mode: $mode,
            startingCheckpoint: $startingCheckpoint,
            limit: $limit,
            context: [
                'source' => [
                    'public_ws_url' => config('isir.sources.public_ws_url'),
                    'document_base_url' => config('isir.sources.document_base_url'),
                    'use_hlidac_statu' => config('isir.sources.use_hlidac_statu'),
                ],
                'filters' => [
                    'section' => config('isir.filter.section'),
                    'proceeding' => config('isir.filter.proceeding'),
                    'final_report_token' => config('isir.filter.final_report_token'),
                    'lead_min_claim_amount' => config('isir.filter.lead_min_claim_amount'),
                    'lead_max_claim_amount' => config('isir.filter.lead_max_claim_amount'),
                ],
            ],
        );

        if ($this->option('dry-run')) {
            $this->line(json_encode($payload->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $checkpoint->forceFill([
            'status' => 'queued',
            'meta' => array_merge(Arr::wrap($checkpoint->meta), [
                'last_enqueued_task_id' => $payload->taskId,
                'last_enqueued_mode' => $mode,
                'last_enqueued_at' => Carbon::now()->toIso8601String(),
                'last_requested_limit' => $limit,
            ]),
        ])->save();

        EnqueueIsirSyncTask::dispatch($payload->toArray())
            ->onQueue((string) config('isir.queue.orchestrator_queue', 'default'));

        $this->info(sprintf(
            'Queued %s sync task %s for %s/%s from checkpoint %s.',
            $mode,
            $payload->taskId,
            $provider,
            $stream,
            $startingCheckpoint,
        ));

        return self::SUCCESS;
    }
}
