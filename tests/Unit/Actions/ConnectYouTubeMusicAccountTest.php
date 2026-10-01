<?php

declare(strict_types=1);

use App\Actions\ConnectYouTubeMusicAccount;
use App\Exceptions\Providers\CredentialsRejected;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Database\Factories\YouTubeMusicAccountFactory;
use Tests\Support\FakeProviderAdapter;

it('stores a verified cookie against the user', function (): void {
    $user = User::factory()->create();
    $cookie = YouTubeMusicAccountFactory::cookie();
    $this->fakeProvider()->account = FakeProviderAdapter::anAccount('Mishaa');

    $account = resolve(ConnectYouTubeMusicAccount::class)->handle($user, $cookie);

    expect($account->user_id)->toBe($user->id)
        ->and($account->cookie)->toBe($cookie)
        ->and($account->account_name)->toBe('Mishaa')
        ->and($account->last_verified_at)->not->toBeNull();
});

it('verifies the cookie against YouTube Music before storing it', function (): void {
    // Refusing a dead cookie here beats failing on every later page view.
    $user = User::factory()->create();
    $this->fakeProvider()->shouldFail = true;

    expect(fn () => resolve(ConnectYouTubeMusicAccount::class)->handle($user, YouTubeMusicAccountFactory::cookie()))
        ->toThrow(CredentialsRejected::class);

    expect(YouTubeMusicAccount::query()->count())->toBe(0);
});

it('replaces the cookie instead of adding a second account', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();
    $replacement = YouTubeMusicAccountFactory::cookie();

    $account = resolve(ConnectYouTubeMusicAccount::class)->handle($user, $replacement);

    expect(YouTubeMusicAccount::query()->count())->toBe(1)
        ->and($account->cookie)->toBe($replacement);
});

it('passes the cookie straight through to the provider', function (): void {
    $user = User::factory()->create();
    $cookie = YouTubeMusicAccountFactory::cookie();

    resolve(ConnectYouTubeMusicAccount::class)->handle($user, $cookie);

    $client = $this->fakeProvider();

    expect($client->calls)->toHaveCount(1)
        ->and($client->calls[0]['credentials']['cookie'])->toBe($cookie);
});
