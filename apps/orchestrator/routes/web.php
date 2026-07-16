<?php

use App\Http\Controllers\Internal\ImportParsedCaseDocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/api/internal/isir/parsed-documents', ImportParsedCaseDocumentController::class);
