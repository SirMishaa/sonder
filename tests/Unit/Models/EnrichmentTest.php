<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Enrichment;

it('keeps one row per subject, source and endpoint', function (): void {
    Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, ['a' => 1]);
    Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, ['a' => 2]);
    Enrichment::store('recording', 'r1', MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::Done, ['b' => 1]);

    expect(Enrichment::query()->count())->toBe(2)
        ->and(Enrichment::payloadFor('recording', 'r1', MetadataSource::CreditsFm, 'isrc'))->toBe(['a' => 2]);
});

it('keeps the last good payload and counts attempts when a refresh fails', function (): void {
    Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, ['a' => 1]);
    $failed = Enrichment::store('recording', 'r1', MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Failed, error: 'timeout');

    expect($failed->payload)->toBe(['a' => 1])
        ->and($failed->attempts)->toBe(1)
        ->and($failed->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String())
        ->and(Enrichment::payloadFor('recording', 'r1', MetadataSource::CreditsFm, 'isrc'))->toBe(['a' => 1]);
});

it('asks again for a missing answer after thirty days', function (): void {
    $enrichment = Enrichment::store('recording', 'r1', MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::NotFound);

    expect($enrichment->next_attempt_at?->toIso8601String())->toBe(now()->addDays(30)->toIso8601String());
});
