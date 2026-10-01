<?php

declare(strict_types=1);

use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Artisan;

it('flags every account whose cookie YouTube Music refuses', function (): void {
    $accounts = YouTubeMusicAccount::factory()->count(2)->create();
    $this->fakeProvider()->shouldFail = true;

    $exitCode = Artisan::call('youtube-music:verify-cookies');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('2 expired, 0 unreachable')
        ->and($accounts->every(fn (YouTubeMusicAccount $account): bool => $account->refresh()->hasExpiredCookie()))->toBeTrue();
});

it('keeps going when YouTube Music cannot be reached', function (): void {
    YouTubeMusicAccount::factory()->count(2)->create();
    $this->fakeProvider()->unavailable = true;

    $exitCode = Artisan::call('youtube-music:verify-cookies');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('0 expired, 2 unreachable');
});
