<?php

declare(strict_types=1);

use App\Http\Controllers\PlayerCommandController;
use App\Http\Controllers\VideoLookupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'abilities:extension'])->prefix('extension')->group(function (): void {
    // Player Commands...
    Route::post('commands', [PlayerCommandController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('player-command.store');

    // Video Lookup...
    Route::post('lookup', [VideoLookupController::class, 'store'])
        ->middleware('throttle:120,1')
        ->name('video-lookup.store');
});
