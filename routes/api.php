<?php

use App\Http\Controllers\Api\AdminApiController;
use App\Http\Middleware\AuthenticateMcpToken;
use Illuminate\Support\Facades\Route;

// Admin JSON API. Same bearer tokens as the admin MCP server.
Route::middleware([AuthenticateMcpToken::class, 'throttle:60,1'])->prefix('admin')->group(function () {
    Route::get('report', [AdminApiController::class, 'report']);
    Route::get('report/days/{date}', [AdminApiController::class, 'day'])->where('date', '\d{4}-\d{2}-\d{2}');
    Route::get('generations/{id}', [AdminApiController::class, 'generation']);
});
