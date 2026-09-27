<?php

declare(strict_types=1);

use App\Actions\VerifyYouTubeMusicCookie;
use App\Models\YouTubeMusicAccount;

it('clears the expired flag once YouTube Music accepts the cookie again', function (): void {
    $this->freezeSecond();
    $account = YouTubeMusicAccount::factory()->expired()->create(['last_verified_at' => now()->subWeek()]);

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeTrue()
        ->and($account->refresh()->cookie_expired_at)->toBeNull()
        ->and($account->last_verified_at)->toEqual(now());
});

it('flags the cookie when YouTube Music refuses it', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->shouldFail = true;

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeFalse()
        ->and($account->refresh()->cookie_expired_at)->not->toBeNull();
});

it('keeps the date of the first refusal', function (): void {
    $account = YouTubeMusicAccount::factory()->create(['cookie_expired_at' => now()->subDays(3)]);
    $firstRefusal = $account->cookie_expired_at;
    $this->fakeYouTubeMusic()->shouldFail = true;

    resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($account->refresh()->cookie_expired_at)->toEqual($firstRefusal);
});
