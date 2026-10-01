<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Provider;
use App\Exceptions\Providers\ProviderException;
use App\Models\User;
use App\Models\YouTubeMusicAccount;
use App\Services\Music\ProviderRegistry;
use SensitiveParameter;

final readonly class ConnectYouTubeMusicAccount
{
    public function __construct(private ProviderRegistry $providers) {}

    /**
     * Verifies the cookie against YouTube Music before storing it. A cookie
     * that cannot read the account is worthless, and finding that out at
     * connection time is far kinder than failing on every later page.
     *
     * @throws ProviderException
     */
    public function handle(User $user, #[SensitiveParameter] string $cookie): YouTubeMusicAccount
    {
        $account = $this->providers->adapter(Provider::YouTubeMusic)
            ->account($this->providers->credentials(Provider::YouTubeMusic, ['cookie' => $cookie]));

        return YouTubeMusicAccount::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'cookie' => $cookie,
                'account_name' => $account->displayName,
                'last_verified_at' => now(),
                'cookie_expired_at' => null,
            ],
        );
    }
}
