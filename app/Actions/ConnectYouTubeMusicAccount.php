<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\YouTubeMusicException;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Services\YouTubeMusic\Client;
use SensitiveParameter;

final readonly class ConnectYouTubeMusicAccount
{
    public function __construct(private Client $client) {}

    /**
     * Verifies the cookie against YouTube Music before storing it. A cookie
     * that cannot read the account is worthless, and finding that out at
     * connection time is far kinder than failing on every later page.
     *
     * @throws YouTubeMusicException
     */
    public function handle(User $user, #[SensitiveParameter] string $cookie): YouTubeMusicAccount
    {
        $account = $this->client->account($cookie);

        return YouTubeMusicAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'cookie' => $cookie,
                'account_name' => $account->name,
                'last_verified_at' => now(),
                'cookie_expired_at' => null,
            ],
        );
    }
}
