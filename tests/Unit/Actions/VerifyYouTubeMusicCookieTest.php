<?php

declare(strict_types=1);

use App\Actions\VerifyYouTubeMusicCookie;
use App\Exceptions\Providers\CredentialsRejected;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeProviderAdapter;

it('clears the expired flag once YouTube Music accepts the cookie again', function (): void {
    $this->freezeSecond();
    $account = YouTubeMusicAccount::factory()->expired()->create(['last_verified_at' => now()->subWeek()]);
    $this->fakeProvider()->playlists = [FakeProviderAdapter::aPlaylistSummary(id: 'PL1')];
    $this->fakeProvider()->tracks['PL1'] = FakeProviderAdapter::aPlaylist(id: 'PL1');

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeTrue()
        ->and($account->refresh()->cookie_expired_at)->toBeNull()
        ->and($account->last_verified_at)->toEqual(now());
});

it('flags the cookie when YouTube Music refuses it', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeProvider()->shouldFail = true;
    Exceptions::fake();

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeFalse()
        ->and($account->refresh()->cookie_expired_at)->not->toBeNull();

    Exceptions::assertReported(CredentialsRejected::class);
});

it('keeps the date of the first refusal', function (): void {
    $account = YouTubeMusicAccount::factory()->create(['cookie_expired_at' => now()->subDays(3)]);
    $firstRefusal = $account->cookie_expired_at;
    $this->fakeProvider()->shouldFail = true;

    resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($account->refresh()->cookie_expired_at)->toEqual($firstRefusal);
});

it('leaves the cookie alone when YouTube Music cannot be reached', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeProvider()->unavailable = true;

    expect(fn () => resolve(VerifyYouTubeMusicCookie::class)->handle($account))
        ->toThrow(App\Exceptions\Providers\ProviderUnavailable::class);
    expect($account->refresh()->cookie_expired_at)->toBeNull();
});

it('checks the cookie with a live account call', function (): void {
    $account = YouTubeMusicAccount::factory()->create();

    resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($this->fakeProvider()->callCount('account'))->toBe(1)
        ->and($this->fakeProvider()->callCount('playlists'))->toBe(0);
});
