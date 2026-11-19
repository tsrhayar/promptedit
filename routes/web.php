<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Home: signed-in users go straight to the generator, guests to the login page.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('generations.create')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])->name('generations.retry');

    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', ReportController::class)->name('admin.report');
});

// Called by Pixelforge: no session user, no CSRF token (excluded in bootstrap/app.php).
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
