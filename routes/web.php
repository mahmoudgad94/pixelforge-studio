<?php

use App\Http\Controllers\Admin\ApprovalController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');
    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('history', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('report', ReportController::class)->name('report');
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('approvals/{action}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('approvals/{action}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    });
});

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
