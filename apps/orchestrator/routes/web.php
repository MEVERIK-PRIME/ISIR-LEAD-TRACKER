<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'isir-lead-tracker',
        'status' => 'ok',
    ]);
});
