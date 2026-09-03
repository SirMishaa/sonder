<?php

declare(strict_types=1);

use App\Actions\DisconnectYouTubeMusicAccount;
use App\Models\YouTubeMusicAccount;

it('deletes the stored account', function (): void {
    $account = YouTubeMusicAccount::factory()->create();

    resolve(DisconnectYouTubeMusicAccount::class)->handle($account);

    expect(YouTubeMusicAccount::query()->count())->toBe(0);
});
