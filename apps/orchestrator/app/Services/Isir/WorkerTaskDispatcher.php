<?php

namespace App\Services\Isir;

use Illuminate\Support\Facades\Redis;
use JsonException;

class WorkerTaskDispatcher
{
    /**
     * @throws JsonException
     */
    public function dispatch(array $payload): void
    {
        Redis::connection((string) config('isir.queue.redis_connection', 'default'))
            ->rpush(
                (string) config('isir.queue.worker_task_queue', 'isir:tasks'),
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
    }
}
