<?php

declare(strict_types=1);

use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Enums\Provider;
use App\Enums\ResolutionStatus;
use App\Exceptions\Metadata\MetadataSourceRateLimited;
use App\Exceptions\Metadata\MetadataSourceUnavailable;
use App\Jobs\EnrichContributor;
use App\Jobs\EnrichRecording;
use App\Jobs\ProjectRecordingMetadata;
use App\Jobs\ResolveLibraryTracks;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeCreditsFmGateway;
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
