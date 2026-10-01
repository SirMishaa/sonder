<?php

declare(strict_types=1);

use App\Actions\VerifyYouTubeMusicCookie;
use App\Exceptions\YouTubeMusicException;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\FakeYouTubeMusicClient;

it('clears the expired flag once YouTube Music accepts the cookie again', function (): void {
    $this->freezeSecond();
    $account = YouTubeMusicAccount::factory()->expired()->create(['last_verified_at' => now()->subWeek()]);
    $this->fakeYouTubeMusic()->playlists = [FakeYouTubeMusicClient::aPlaylistSummary(id: 'PL1')];
    $this->fakeYouTubeMusic()->tracks['PL1'] = FakeYouTubeMusicClient::aPlaylist(id: 'PL1');

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeTrue()
        ->and($account->refresh()->cookie_expired_at)->toBeNull()
        ->and($account->last_verified_at)->toEqual(now());
});

it('flags the cookie when YouTube Music refuses it', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->shouldFail = true;
    Exceptions::fake();

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeFalse()
        ->and($account->refresh()->cookie_expired_at)->not->toBeNull();

    Exceptions::assertReported(YouTubeMusicException::class);
});

it('keeps the date of the first refusal', function (): void {
    $account = YouTubeMusicAccount::factory()->create(['cookie_expired_at' => now()->subDays(3)]);
    $firstRefusal = $account->cookie_expired_at;
    $this->fakeYouTubeMusic()->shouldFail = true;

    resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($account->refresh()->cookie_expired_at)->toEqual($firstRefusal);
});

it('flags the cookie when YouTube Music serves an empty library', function (): void {
    $account = YouTubeMusicAccount::factory()->create();
    $this->fakeYouTubeMusic()->playlists = [];

    $works = resolve(VerifyYouTubeMusicCookie::class)->handle($account);

    expect($works)->toBeFalse()
        ->and($account->refresh()->cookie_expired_at)->not->toBeNull();
});
