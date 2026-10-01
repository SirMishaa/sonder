<?php

declare(strict_types=1);

use App\Services\Metadata\CallBudget;
use App\Services\Metadata\EnrichmentTelemetry;
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
