<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\SyncCheckpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdvanceCheckpointController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) config('services.internal_ingest.token', '');
        $providedToken = (string) ($request->header('X-Internal-Token') ?: $request->bearerToken() ?: '');

        abort_unless($configuredToken !== '' && hash_equals($configuredToken, $providedToken), 401);

        $validated = $request->validate([
            'provider'         => ['required', 'string', 'max:100'],
            'stream'           => ['required', 'string', 'max:100'],
            'checkpoint_value' => ['required', 'string', 'max:255'],
        ]);

        $checkpoint = SyncCheckpoint::query()->firstOrCreate(
            [
                'provider' => $validated['provider'],
                'stream'   => $validated['stream'],
            ],
            [
                'checkpoint_value' => '0',
                'status'           => 'idle',
                'meta'             => [],
            ],
        );

        $checkpoint->forceFill([
            'checkpoint_value' => $validated['checkpoint_value'],
            'status'           => 'idle',
        ])->save();

        return response()->json([
            'ok'               => true,
            'checkpoint_value' => $validated['checkpoint_value'],
        ]);
    }
}
