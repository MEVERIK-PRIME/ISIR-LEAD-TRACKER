<?php

namespace App\Support\Isir;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class WorkerTaskPayload
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $taskType,
        public readonly string $provider,
        public readonly string $stream,
        public readonly string $mode,
        public readonly string $checkpoint,
        public readonly int $limit,
        public readonly array $context,
        public readonly string $requestedAt,
    ) {
    }

    public static function forIsirSync(
        string $provider,
        string $stream,
        string $mode,
        string $startingCheckpoint,
        int $limit,
        array $context = [],
    ): self {
        return new self(
            taskId: (string) Str::uuid(),
            taskType: 'isir.sync.events',
            provider: $provider,
            stream: $stream,
            mode: $mode,
            checkpoint: $startingCheckpoint,
            limit: $limit,
            context: $context,
            requestedAt: CarbonImmutable::now()->toIso8601String(),
        );
    }

    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'task_type' => $this->taskType,
            'provider' => $this->provider,
            'stream' => $this->stream,
            'mode' => $this->mode,
            'checkpoint' => $this->checkpoint,
            'limit' => $this->limit,
            'context' => $this->context,
            'requested_at' => $this->requestedAt,
        ];
    }
}
