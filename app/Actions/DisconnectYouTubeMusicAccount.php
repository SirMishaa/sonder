<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\YouTubeMusicAccount;

final readonly class DisconnectYouTubeMusicAccount
{
    public function handle(YouTubeMusicAccount $account): void
    {
        $account->delete();
    }
}
