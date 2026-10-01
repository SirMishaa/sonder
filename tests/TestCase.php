<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeProviderAdapter;
use App\Enums\Provider;
use App\Services\Music\ProviderRegistry;
use App\Services\Music\YouTubeMusic\YouTubeMusicCredentials;

abstract class TestCase extends BaseTestCase
{
    /**
     * Replaces YouTube Music for every test in the suite.
     *
     * The package behind the real client uses its own HTTP layer, so
     * `Http::preventStrayRequests()` cannot catch a call that escapes. Binding
     * the fake for all tests is what actually keeps the suite offline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(FakeProviderAdapter::class, new FakeProviderAdapter());
        $this->app->make(ProviderRegistry::class)
            ->register(Provider::YouTubeMusic, FakeProviderAdapter::class, YouTubeMusicCredentials::class);

        Http::fake([
            '*/__inertia_ssr*' => Http::response(''),
        ]);
    }

    /**
     * The fake every provider resolves to in tests.
     */
    protected function fakeProvider(): FakeProviderAdapter
    {
        return $this->app->make(FakeProviderAdapter::class);
    }

}
