<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::redirect('dashboard', '/generate');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generate', GenerateController::class)->name('generate');
    Route::get('history', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware(['precognitive', 'throttle:generations'])
        ->name('generations.store');
    Route::post('generations/{generation}/try-again', [GenerationController::class, 'tryAgain'])
        ->can('tryAgain', 'generation')
        ->middleware('throttle:generations')
        ->name('generations.try-again');
    Route::get('generations/{generation}/image', [GenerationImageController::class, 'show'])
        ->name('generations.image');
    Route::get('generations/{generation}/image/download', [GenerationImageController::class, 'download'])
        ->name('generations.image.download');
    Route::get('admin/report', AdminReportController::class)
        ->can('viewAdminReport')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
