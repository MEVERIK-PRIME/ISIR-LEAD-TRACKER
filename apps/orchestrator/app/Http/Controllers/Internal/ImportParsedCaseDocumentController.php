<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Isir\ImportParsedCaseDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportParsedCaseDocumentController extends Controller
{
    public function __invoke(Request $request, ImportParsedCaseDocument $importer): JsonResponse
    {
        $configuredToken = (string) config('services.internal_ingest.token', '');
        $providedToken = (string) ($request->header('X-Internal-Token') ?: $request->bearerToken() ?: '');

        abort_unless($configuredToken !== '' && hash_equals($configuredToken, $providedToken), 401);

        return response()->json([
            'data' => $importer->import($request->all()),
        ]);
    }
}
