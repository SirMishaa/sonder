<?php

declare(strict_types=1);

use App\Http\Controllers\DiscoverController;
use App\Http\Controllers\PlaylistController;
use App\Http\Controllers\PlaylistRefreshController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\ThumbnailController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserEmailResetNotificationController;
use App\Http\Controllers\UserEmailVerificationController;
use App\Http\Controllers\UserEmailVerificationNotificationController;
use App\Http\Controllers\UserLocaleController;
use App\Http\Controllers\UserPasswordController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\UserTwoFactorAuthenticationController;
use App\Http\Controllers\YouTubeMusicConnectionController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome/Index')->middleware('guest')->name('home');
Route::inertia('design-system', 'design-system/Index')->name('design-system');

Route::get('thumbnails/{hash}', [ThumbnailController::class, 'show'])->name('thumbnail.show');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', [DiscoverController::class, 'index'])->name('dashboard');

    // YouTube Music Connection...
    Route::get('youtube-music', [YouTubeMusicConnectionController::class, 'create'])
        ->name('youtube-music-connection.create');
    Route::post('youtube-music', [YouTubeMusicConnectionController::class, 'store'])
        ->name('youtube-music-connection.store');
    Route::delete('youtube-music', [YouTubeMusicConnectionController::class, 'destroy'])
        ->name('youtube-music-connection.destroy');
    Route::get('youtube-music/sync/{sync}', [YouTubeMusicConnectionController::class, 'show'])
        ->name('youtube-music-connection.sync');

    // Playlists...
    Route::get('playlists', [PlaylistController::class, 'index'])->name('playlist.index');
    Route::get('playlists/{playlistId}', [PlaylistController::class, 'show'])->name('playlist.show');
    Route::post('playlists/{playlistId}/refresh', [PlaylistRefreshController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('playlist-refresh.store');
});

Route::middleware('auth')->group(function (): void {
    // User...
    Route::delete('user', [UserController::class, 'destroy'])->name('user.destroy');

    // User Profile...
    Route::redirect('settings', '/settings/profile');
    Route::get('settings/profile', [UserProfileController::class, 'edit'])->name('user-profile.edit');
    Route::patch('settings/profile', [UserProfileController::class, 'update'])->name('user-profile.update');

    // User Locale...
    Route::put('settings/locale', [UserLocaleController::class, 'update'])->name('user-locale.update');

    // User Password...
    Route::get('settings/password', [UserPasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [UserPasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    // User Two-Factor Authentication...
    Route::get('settings/two-factor', [UserTwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');
});

Route::middleware('guest')->group(function (): void {
    // User...
    Route::get('register', [UserController::class, 'create'])
        ->name('register');
    Route::post('register', [UserController::class, 'store'])
        ->name('register.store');

    // User Password...
    Route::get('reset-password/{token}', [UserPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('reset-password', [UserPasswordController::class, 'store'])
        ->name('password.store');

    // User Email Reset Notification...
    Route::get('forgot-password', [UserEmailResetNotificationController::class, 'create'])
        ->name('password.request');
    Route::post('forgot-password', [UserEmailResetNotificationController::class, 'store'])
        ->name('password.email');

    // Session...
    Route::get('login', [SessionController::class, 'create'])
        ->name('login');
    Route::post('login', [SessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    // User Email Verification...
    Route::get('verify-email', [UserEmailVerificationNotificationController::class, 'create'])
        ->name('verification.notice');
    Route::post('email/verification-notification', [UserEmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // User Email Verification...
    Route::get('verify-email/{id}/{hash}', [UserEmailVerificationController::class, 'update'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    // Session...
    Route::post('logout', [SessionController::class, 'destroy'])
        ->name('logout');
});
