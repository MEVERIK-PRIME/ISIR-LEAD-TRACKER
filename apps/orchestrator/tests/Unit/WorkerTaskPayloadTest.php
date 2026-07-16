<?php

namespace Tests\Unit;

use App\Support\Isir\WorkerTaskPayload;
use PHPUnit\Framework\TestCase;

class WorkerTaskPayloadTest extends TestCase
{
    public function test_it_builds_the_expected_sync_payload_shape(): void
    {
        $payload = WorkerTaskPayload::forIsirSync(
            provider: 'isir_public_ws',
            stream: 'events',
            mode: 'backfill',
            startingCheckpoint: '12345',
            limit: 25,
            context: [
                'source' => [
                    'public_ws_url' => 'https://isir.justice.cz:8443/isir_public_ws/IsirWsPublicService',
                    'document_base_url' => 'https://isir.justice.cz/isir/common/stat.do',
                    'use_hlidac_statu' => true,
                ],
                'filters' => [
                    'section' => 'B',
                    'proceeding' => 'konkurs',
                    'final_report_token' => 'konec',
                    'lead_min_claim_amount' => 300000,
                    'lead_max_claim_amount' => 600000,
                ],
            ],
        )->toArray();

        $this->assertSame('isir.sync.events', $payload['task_type']);
        $this->assertSame('isir_public_ws', $payload['provider']);
        $this->assertSame('events', $payload['stream']);
        $this->assertSame('backfill', $payload['mode']);
        $this->assertSame('12345', $payload['checkpoint']);
        $this->assertSame(25, $payload['limit']);
        $this->assertSame('B', $payload['context']['filters']['section']);
        $this->assertSame(600000, $payload['context']['filters']['lead_max_claim_amount']);
        $this->assertArrayHasKey('task_id', $payload);
        $this->assertArrayHasKey('requested_at', $payload);
    }
}
