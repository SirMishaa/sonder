<?php

declare(strict_types=1);

namespace Tests;

use App\Services\YouTubeMusic\Client;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeYouTubeMusicClient;

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

        $this->app?->instance(Client::class, new FakeYouTubeMusicClient());
    }

    protected function fakeYouTubeMusic(): FakeYouTubeMusicClient
    {
        $client = $this->app?->make(Client::class);

        assert($client instanceof FakeYouTubeMusicClient);

        return $client;
    }
}
