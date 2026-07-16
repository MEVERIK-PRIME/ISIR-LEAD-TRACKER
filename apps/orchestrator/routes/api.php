<?php

use App\Http\Controllers\Internal\AdvanceCheckpointController;
use App\Http\Controllers\Internal\ImportParsedCaseDocumentController;
use Illuminate\Support\Facades\Route;

Route::post('/internal/isir/parsed-documents', ImportParsedCaseDocumentController::class);
Route::post('/internal/advance-checkpoint', AdvanceCheckpointController::class);
