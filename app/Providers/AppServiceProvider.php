<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Provider;
use App\Models\User;
use App\Services\Metadata\CallBudget;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\CreditsFm\HttpCreditsFmGateway;
use App\Services\Metadata\CreditsFm\RateLimitedCreditsFmGateway;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\OpenTelemetryEnrichmentTelemetry;
use App\Services\Music\ProviderRegistry;
use App\Services\Music\YouTubeMusic\Gateway\RateLimitedGateway;
use App\Services\Music\YouTubeMusic\Gateway\YouTubeMusicGateway;
use App\Services\Music\YouTubeMusic\Gateway\YtmusicapiGateway;
use App\Services\Music\YouTubeMusic\YouTubeMusicAdapter;
use App\Services\Music\YouTubeMusic\YouTubeMusicCredentials;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Logs\LoggerInterface as OpenTelemetryLoggerInterface;
use OpenTelemetry\API\Logs\NoopLogger;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(YouTubeMusicGateway::class, fn (): YouTubeMusicGateway => new RateLimitedGateway(
            $this->app->make(YtmusicapiGateway::class),
            $this->app->make(CacheRateLimiter::class),
        ));

        $this->app->bind(EnrichmentTelemetry::class, OpenTelemetryEnrichmentTelemetry::class);
        $this->app->bind(CreditsFmGateway::class, fn (): CreditsFmGateway => new RateLimitedCreditsFmGateway(
            $this->app->make(HttpCreditsFmGateway::class),
            $this->app->make(CallBudget::class),
        ));

        $this->app->singleton(ProviderRegistry::class, fn (): ProviderRegistry => (new ProviderRegistry($this->app))
            ->register(Provider::YouTubeMusic, YouTubeMusicAdapter::class, YouTubeMusicCredentials::class));

        Gate::define('viewInertiaDevTools', fn (User $user): bool => $user->email === 'mishaa.pro@proton.me');

        $this->registerOpenTelemetryLoggerFallback();
    }

    public function boot(): void
    {
        // YouTube Music documents no limit for its private API, only that one
        // exists and that its quotas reset hourly. A person browsing makes a
        // few dozen calls a minute; this stays well under that, while the
        // hourly cap stops any runaway loop long before YouTube would.
        RateLimiter::for(RateLimitedGateway::LIMITER, fn (string $account): array => [
            Limit::perMinute(30)->by('youtube-music:minute:'.$account),
            Limit::perHour(500)->by('youtube-music:hour:'.$account),
        ]);

        // credits.fm documents no limit: stay polite. MusicBrainz allows one
        // request a second per IP and blocks clients that exceed it.
        RateLimiter::for(CallBudget::CREDITS_FM, fn (string $key): array => [
            Limit::perSecond(2)->by('credits-fm:second:'.$key),
            Limit::perHour(3000)->by('credits-fm:hour:'.$key),
        ]);
        RateLimiter::for(CallBudget::MUSICBRAINZ, fn (string $key): array => [
            Limit::perSecond(1)->by('musicbrainz:second:'.$key),
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
