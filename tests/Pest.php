<?php

declare(strict_types=1);

use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeMusicBrainzGateway;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();

        // Tests never reach a real metadata service; a test needing answers binds its own fake.
        app()->instance(CreditsFmGateway::class, new FakeCreditsFmGateway());
        app()->instance(MusicBrainzGateway::class, new FakeMusicBrainzGateway());
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * A real metadata service response, captured once (tests/Fixtures/Metadata).
 *
 * @return array<string, mixed>
 */
function metadataFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__."/Fixtures/Metadata/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);
    $fixture = [];

    foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
        $fixture[(string) $key] = $value;
    }

    return $fixture;
}

function something(): void
{
    // ..
}
