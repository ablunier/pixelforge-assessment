<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GenerationController;
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
});

require __DIR__.'/settings.php';
