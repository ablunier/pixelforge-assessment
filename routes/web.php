<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('precognitive')
        ->name('generations.store');
    Route::post('generations/{generation}/try-again', [GenerationController::class, 'tryAgain'])
        ->can('tryAgain', 'generation')
        ->name('generations.try-again');
    Route::get('generations/{generation}/image', [GenerationImageController::class, 'show'])
        ->name('generations.image');
    Route::get('generations/{generation}/image/download', [GenerationImageController::class, 'download'])
        ->name('generations.image.download');
});

require __DIR__.'/settings.php';
