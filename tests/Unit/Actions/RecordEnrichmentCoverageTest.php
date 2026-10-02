<?php

declare(strict_types=1);

use App\Actions\RecordEnrichmentCoverage;
use App\Enums\CreditType;
use App\Enums\MetadataSource;
use App\Enums\ResolutionStatus;
use App\Models\Contributor;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\RecordingResolution;
use App\Models\SimilarRecording;
use App\Models\Tag;
use App\Services\Metadata\EnrichmentTelemetry;
use Illuminate\Support\Facades\DB;
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

it('measures Last.fm tags, similar tracks and popularity', function (): void {
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);
    $tagged = Recording::factory()->create(['lastfm_listeners' => 10]);
    $throughArtist = Recording::factory()->create();
    Recording::factory()->create();
    $tag = Tag::named('chill', false);
    DB::table('recording_tags')->insert(['recording_id' => $tagged->id, 'tag_id' => $tag->id, 'source' => 'lastfm', 'weight' => 50]);
    $artist = Contributor::factory()->create();
    RecordingContributor::query()->create(['recording_id' => $throughArtist->id, 'contributor_id' => $artist->id, 'credit_type' => CreditType::Artist, 'role' => '', 'source' => MetadataSource::MusicBrainz, 'credit_attributes' => []]);
    DB::table('contributor_tags')->insert(['contributor_id' => $artist->id, 'tag_id' => $tag->id, 'source' => 'lastfm', 'weight' => 50]);
    SimilarRecording::query()->create(['recording_id' => $tagged->id, 'title' => 'x', 'artist_name' => 'y', 'match' => 0.5, 'source' => MetadataSource::LastFm]);

    resolve(RecordEnrichmentCoverage::class)->handle();

    expect(round($telemetry->coverage['lastfm_tags'], 2))->toBe(0.67)
        ->and(round($telemetry->coverage['similar'], 2))->toBe(0.33)
        ->and(round($telemetry->coverage['popularity'], 2))->toBe(0.33);
});
