<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\YouTubeMusic\CachedClient;
use App\Services\YouTubeMusic\Client;
use App\Services\YouTubeMusic\RateLimitedClient;
use App\Services\YouTubeMusic\YtmusicapiClient;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The cache sits outside the rate limit, so a cached lookup never
        // spends a call from the budget.
        $this->app->bind(Client::class, fn (): Client => new CachedClient(
            new RateLimitedClient(new YtmusicapiClient(), $this->app->make(CacheRateLimiter::class)),
            $this->app->make(Repository::class),
        ));

        Gate::define('viewInertiaDevTools', fn (User $user): bool => $user->email === 'mishaa.pro@proton.me');
    }

    public function boot(): void
    {
        // YouTube Music documents no limit for its private API, only that one
        // exists and that its quotas reset hourly. A person browsing makes a
        // few dozen calls a minute; this stays well under that, while the
        // hourly cap stops any runaway loop long before YouTube would.
        RateLimiter::for(RateLimitedClient::LIMITER, fn (string $account): array => [
            Limit::perMinute(30)->by('youtube-music:minute:'.$account),
            Limit::perHour(500)->by('youtube-music:hour:'.$account),
        ]);
    }
}
