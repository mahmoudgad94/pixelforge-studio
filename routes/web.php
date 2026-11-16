<?php

use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\ReferenceImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('history', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/updates', [GenerationController::class, 'updates'])->name('generations.updates');
    Route::post('references', [ReferenceImageController::class, 'store'])->middleware('throttle:20,1')->name('references.store');
    Route::post('credits', [CreditController::class, 'store'])->middleware('throttle:10,1')->name('credits.store');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('report', ReportController::class)->name('report');
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('approvals/{action}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{action}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
        Route::post('mcp-token', [ApprovalController::class, 'createToken'])->middleware('throttle:10,1')->name('mcp-token.store');
        Route::delete('mcp-token', [ApprovalController::class, 'revokeToken'])->name('mcp-token.destroy');
    });
});

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
