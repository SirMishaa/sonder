<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\DB;

it('encrypts the cookie at rest', function (): void {
    // The cookie is a live session for the user's Google account. It must
    // never be readable by anyone holding a copy of the database file.
    $account = YouTubeMusicAccount::factory()->create();

    $stored = DB::table('youtube_music_accounts')->where('id', $account->id)->value('cookie');

    expect($stored)->toBeString()
        ->and($stored)->not->toBe($account->cookie)
        ->and($stored)->not->toContain('SAPISID')
        ->and($account->fresh()?->cookie)->toBe($account->cookie);
});

it('hides the cookie from serialisation', function (): void {
    $account = YouTubeMusicAccount::factory()->create();

    expect($account->toArray())->not->toHaveKey('cookie');
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $account = YouTubeMusicAccount::factory()->for($user)->create();

    expect($account->user->id)->toBe($user->id)
        ->and($user->youTubeMusicAccount()->first()?->id)->toBe($account->id);
});

it('goes away with its user', function (): void {
    $user = User::factory()->create();
    YouTubeMusicAccount::factory()->for($user)->create();

    $user->delete();

    expect(YouTubeMusicAccount::query()->count())->toBe(0);
});
