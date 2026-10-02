<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Events\LibraryEnrichmentUpdated;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ProjectRecordingMetadata;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Playlist;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\YouTubeMusicAccount;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\LastFm\LastFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeEnrichmentTelemetry;
use Tests\Support\FakeLastFmGateway;
use Tests\Support\FakeMusicBrainzGateway;

beforeEach(function (): void {
    $this->creditsFm = new FakeCreditsFmGateway();
    $this->musicBrainz = new FakeMusicBrainzGateway();
    app()->instance(CreditsFmGateway::class, $this->creditsFm);
    app()->instance(MusicBrainzGateway::class, $this->musicBrainz);
});

/**
 * @return list<array{provider: string, externalId: string, title: string, artists: string, durationSeconds: int|null}>
 */
function survivalBatch(): array
{
    return [['provider' => Provider::YouTubeMusic->value, 'externalId' => 'UcOUJM08bYk', 'title' => 'Survival', 'artists' => 'Muse', 'durationSeconds' => 258]];
}

it('resolves tracks then asks each source about the recording', function (): void {
    Queue::fake();
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    (new ResolveLibraryTracks(survivalBatch()))->handle();

    Queue::assertPushedOn('enrichment', EnrichRecording::class, fn (EnrichRecording $job): bool => $job->source === MetadataSource::CreditsFm);
    Queue::assertPushedOn('enrichment', EnrichRecording::class, fn (EnrichRecording $job): bool => $job->source === MetadataSource::MusicBrainz);
});

it('skips the tracks resolved since the batch was queued', function (): void {
    Queue::fake();
    $job = new ResolveLibraryTracks(survivalBatch());
    $this->travel(1)->seconds();
    RecordingResolution::factory()->create(['external_id' => 'UcOUJM08bYk', 'status' => ResolutionStatus::Resolved]);

    $job->handle();

    expect($this->creditsFm->calls)->toBe([]);
});

it('describes then projects a recording', function (): void {
    Queue::fake();
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $this->creditsFm->details['GBAHT1200434'] = metadataFixture('credits-fm-isrc');

    (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->handle();

    Queue::assertPushedOn('enrichment', ProjectRecordingMetadata::class, fn (ProjectRecordingMetadata $job): bool => $job->recordingId === $recording->id);
});

it('describes the main artists the projection names', function (): void {
    Queue::fake();
    $recording = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454']);
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::Done, metadataFixture('musicbrainz-recording'));

    (new ProjectRecordingMetadata($recording->id))->handle();

    Queue::assertPushedOn('enrichment', EnrichContributor::class);
});

it('queues one description per artist even when two projections name it', function (): void {
    Queue::fake();

    EnrichContributor::dispatch('contributor-1', MetadataSource::MusicBrainz);
    EnrichContributor::dispatch('contributor-1', MetadataSource::MusicBrainz);

    Queue::assertPushed(EnrichContributor::class, 1);
});

it('projects the contributor tags after describing it', function (): void {
    $muse = Contributor::factory()->create(['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
    $this->musicBrainz->artists['9c9f1380-2516-4fc9-a3e6-f9f61941d090'] = metadataFixture('musicbrainz-artist');

    (new EnrichContributor($muse->id, MetadataSource::MusicBrainz))->handle();

    expect(DB::table('contributor_tags')->where('contributor_id', $muse->id)->count())->toBeGreaterThan(0);
});

it('releases on a rate limit without recording a failure', function (): void {
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $this->creditsFm->failure = new MetadataSourceRateLimited(MetadataSource::CreditsFm, 12);
    $job = (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(12);
    expect(Enrichment::query()->count())->toBe(0);
});

it('releases when credits.fm itself answers 429', function (): void {
    Http::fake(['api.credits.fm/*' => Http::response('', 429, ['Retry-After' => '7'])]);
    app()->forgetInstance(CreditsFmGateway::class);
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    $job = (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(7);
    expect(Enrichment::query()->count())->toBe(0);
});

it('records a failure once the job gives up', function (): void {
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);

    (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->failed(new MetadataSourceUnavailable(MetadataSource::CreditsFm, 'down'));

    $enrichment = Enrichment::query()->sole();
    expect($enrichment->status)->toBe(EnrichmentStatus::Failed)
        ->and($enrichment->endpoint)->toBe('isrc')
        ->and($enrichment->next_attempt_at?->toIso8601String())->toBe(now()->addDay()->toIso8601String());
});

it('describes every recording it resolved, even across released runs', function (): void {
    Queue::fake();
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->creditsFm->isrcs['Muse|Hysteria'] = 'GBAHT0300001';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');
    $this->musicBrainz->isrcs['GBAHT0300001'] = ['recordings' => [[
        'id' => '22222222-2222-2222-2222-222222222222',
        'title' => 'Hysteria',
        'length' => 227000,
        'artist-credit' => [['name' => 'Muse', 'artist' => ['id' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090', 'name' => 'Muse']]],
    ]]];
    $job = (new ResolveLibraryTracks([
        ...survivalBatch(),
        ['provider' => Provider::YouTubeMusic->value, 'externalId' => 'hysteria000', 'title' => 'Hysteria', 'artists' => 'Muse', 'durationSeconds' => 227],
    ]))->withFakeQueueInteractions();
    $this->musicBrainz->refuseAfterCalls = 1;

    $job->handle();

    $job->assertReleased(1);
    Queue::assertNotPushed(EnrichRecording::class);

    $this->travel(1)->seconds();
    $this->musicBrainz->refuseAfterCalls = null;
    $job->handle();

    Queue::assertPushed(EnrichRecording::class, 4);
});

it('releases instead of running past its time budget', function (): void {
    Queue::fake();
    $job = (new ResolveLibraryTracks([
        ...survivalBatch(),
        ['provider' => Provider::YouTubeMusic->value, 'externalId' => 'hysteria000', 'title' => 'Hysteria', 'artists' => 'Muse', 'durationSeconds' => 227],
    ], budgetSeconds: -1))->withFakeQueueInteractions();

    $job->handle();

    $job->assertReleased(1);
    expect(RecordingResolution::query()->where('status', ResolutionStatus::NotFound)->count())->toBe(1);
});

it('announces the library progress after every step', function (string $step): void {
    Queue::fake();
    Event::fake([LibraryEnrichmentUpdated::class]);
    $account = YouTubeMusicAccount::factory()->create();
    libraryTrack(Playlist::factory()->for($account, 'youtubeMusicAccount')->create(), 'UcOUJM08bYk');
    $recording = Recording::factory()->create(['isrc' => 'GBAHT1200434']);
    RecordingResolution::factory()->create(['external_id' => 'UcOUJM08bYk', 'recording_id' => $recording->id]);

    match ($step) {
        'resolution run' => (new ResolveLibraryTracks(survivalBatch()))->handle(),
        'resolution giving up' => (new ResolveLibraryTracks(survivalBatch()))->failed(new RuntimeException('gave up')),
        'description' => (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->handle(),
        'description giving up' => (new EnrichRecording($recording->id, MetadataSource::CreditsFm))->failed(new RuntimeException('gave up')),
        'projection' => (new ProjectRecordingMetadata($recording->id))->handle(),
        default => throw new LogicException("Unknown step [{$step}]."),
    };

    Event::assertDispatched(LibraryEnrichmentUpdated::class, fn (LibraryEnrichmentUpdated $event): bool => $event->userId === $account->user_id);
})->with(['resolution run', 'resolution giving up', 'description', 'description giving up', 'projection']);

it('reports how each resolution run went', function (): void {
    Queue::fake();
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);
    $batch = [
        ...survivalBatch(),
        ['provider' => Provider::YouTubeMusic->value, 'externalId' => 'hysteria000', 'title' => 'Hysteria', 'artists' => 'Muse', 'durationSeconds' => 227],
    ];

    (new ResolveLibraryTracks($batch, budgetSeconds: -1))->withFakeQueueInteractions()->handle();
    (new ResolveLibraryTracks($batch))->withFakeQueueInteractions()->handle();

    expect($telemetry->runs)->toBe([
        ['outcome' => 'budget', 'settled' => 1, 'remaining' => 1],
        ['outcome' => 'done', 'settled' => 1, 'remaining' => 0],
    ]);
});

it('reports which source held a resolution run back', function (): void {
    $telemetry = new FakeEnrichmentTelemetry();
    app()->instance(EnrichmentTelemetry::class, $telemetry);
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->refuseAfterCalls = 0;

    (new ResolveLibraryTracks(survivalBatch()))->withFakeQueueInteractions()->handle();

    expect($telemetry->runs)->toBe([['outcome' => 'rate_limited:musicbrainz', 'settled' => 0, 'remaining' => 1]]);
});

it('records a failure only on the endpoints left undone', function (): void {
    app()->instance(LastFmGateway::class, new FakeLastFmGateway());
    $recording = Recording::factory()->create(['title' => 'Loreley', 'artist_name' => 'Lord of the Lost']);
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::LastFm, Enrichment::INFO, EnrichmentStatus::Done, ['track' => []]);

    (new EnrichRecording($recording->id, MetadataSource::LastFm))->failed(new RuntimeException('gave up'));

    expect(Enrichment::query()->where('source', MetadataSource::LastFm)->pluck('status', 'endpoint')->all())
        ->toEqual(['info' => EnrichmentStatus::Done, 'top_tags' => EnrichmentStatus::Failed, 'similar' => EnrichmentStatus::Failed]);
});
