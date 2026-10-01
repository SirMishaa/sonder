<?php

declare(strict_types=1);

use App\Actions\RecordEnrichmentCoverage;
use App\Enums\ResolutionStatus;
use App\Models\RecordingResolution;
use App\Services\Metadata\EnrichmentTelemetry;
use Tests\Support\FakeEnrichmentTelemetry;

it('records the share of the library resolved and the backlog', function (): void {
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);

    foreach (['video000001', 'video000002', 'video000003', 'video000004'] as $video) {
        libraryTrack(null, $video);
    }
    RecordingResolution::factory()->create(['external_id' => 'video000001']);
    RecordingResolution::factory()->create(['external_id' => 'video000002', 'status' => ResolutionStatus::NotFound, 'recording_id' => null]);
    RecordingResolution::factory()->create(['external_id' => 'video000003', 'status' => ResolutionStatus::Pending, 'recording_id' => null]);

    resolve(RecordEnrichmentCoverage::class)->handle();

    expect($telemetry->coverage['resolved'])->toBe(0.25)
        ->and($telemetry->backlog['not_found'])->toBe(1)
        ->and($telemetry->backlog['unresolved'])->toBe(2);
});
