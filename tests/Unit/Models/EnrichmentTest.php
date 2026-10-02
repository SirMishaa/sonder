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

it('refreshes popularity weekly and the rest every ninety days', function (): void {
    expect(EnrichmentStatus::Done->nextAttemptAt(Enrichment::INFO)->toIso8601String())->toBe(now()->addDays(7)->toIso8601String())
        ->and(EnrichmentStatus::Done->nextAttemptAt('top_tags')->toIso8601String())->toBe(now()->addDays(90)->toIso8601String())
        ->and(EnrichmentStatus::NotFound->nextAttemptAt(Enrichment::INFO)->toIso8601String())->toBe(now()->addDays(30)->toIso8601String());
});

it('is due when never asked, failed, or older than its cadence, whatever next_attempt_at says', function (): void {
    expect(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO))->toBeTrue();

    Enrichment::store(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, ['track' => []]);
    Enrichment::store(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, 'top_tags', EnrichmentStatus::Failed, error: 'boom');
    Enrichment::query()->where('endpoint', Enrichment::INFO)->update(['next_attempt_at' => now()->subYear()]);

    expect(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO))->toBeFalse()
        ->and(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, 'top_tags'))->toBeTrue()
        ->and(Enrichment::fetchedAt(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO)?->toIso8601String())->toBe(now()->toIso8601String());

    $this->travel(8)->days();

    expect(Enrichment::isDue(Enrichment::RECORDING, 'r1', MetadataSource::LastFm, Enrichment::INFO))->toBeTrue();
});
