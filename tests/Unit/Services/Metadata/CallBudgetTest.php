<?php

declare(strict_types=1);

use App\Services\Metadata\CallBudget;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Support\Sleep;
use Tests\Support\FakeEnrichmentTelemetry;

beforeEach(function (): void {
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
    $this->budget = resolve(CallBudget::class);
});

it('allows MusicBrainz one call a second', function (): void {
    expect($this->budget->spend(CallBudget::MUSICBRAINZ))->toBeNull()
        ->and($this->budget->spend(CallBudget::MUSICBRAINZ))->toBe(1)
        ->and($this->telemetry->refusals)->toBe(['musicbrainz']);

    $this->travel(1)->seconds();

    expect($this->budget->spend(CallBudget::MUSICBRAINZ))->toBeNull();
});

it('caps credits.fm by the hour as well as by the second', function (): void {
    foreach (range(1, 3000) as $call) {
        if ($call % 2 === 1) {
            $this->travel(1)->seconds();
        }

        expect($this->budget->spend(CallBudget::CREDITS_FM))->toBeNull();
    }

    $this->travel(1)->seconds();

    expect($this->budget->spend(CallBudget::CREDITS_FM))->toBeGreaterThan(1);
});

it('waits out a short refusal instead of failing the call', function (): void {
    Sleep::fake(syncWithCarbon: true);

    expect($this->budget->await(CallBudget::MUSICBRAINZ))->toBeNull()
        ->and($this->budget->await(CallBudget::MUSICBRAINZ))->toBeNull();

    Sleep::assertSleptTimes(1);
});

it('gives up at once when the wait would be long', function (): void {
    Sleep::fake(syncWithCarbon: true);

    foreach (range(1, 3000) as $call) {
        if ($call % 2 === 1) {
            $this->travel(1)->seconds();
        }

        $this->budget->spend(CallBudget::CREDITS_FM);
    }

    expect($this->budget->await(CallBudget::CREDITS_FM))->toBeGreaterThan(2);
    Sleep::assertNeverSlept();
});
