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
use OpenTelemetry\API\Logs\LoggerInterface as OpenTelemetryLoggerInterface;
use OpenTelemetry\API\Logs\NoopLogger;

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

        $this->registerOpenTelemetryLoggerFallback();
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

    /**
     * Keep the otlp log channel usable before the OpenTelemetry package boots.
     *
     * keepsuit/laravel-opentelemetry declares the `otlp` channel in
     * packageRegistered() but only binds its LoggerInterface in packageBooted().
     * Any log written in between (a provider logging from register(), an
     * exception during boot) dies with "Target [LoggerInterface] is not
     * instantiable", masking the original error and breaking package:discover
     * in the production build. The package overrides this binding once it boots,
     * so the real logger always wins.
     */
    private function registerOpenTelemetryLoggerFallback(): void
    {
        if ($this->app->bound(OpenTelemetryLoggerInterface::class)) {
            return;
        }

        $this->app->singleton(OpenTelemetryLoggerInterface::class, fn (): NoopLogger => NoopLogger::getInstance());
    }
}
