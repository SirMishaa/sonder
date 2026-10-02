<?php

declare(strict_types=1);

use App\Actions\SummarizeLibraryEnrichment;
use App\Enums\CreditType;
use App\Enums\LibraryEnrichmentState;
use App\Enums\MetadataSource;
use App\Enums\ResolutionStatus;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Playlist;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Tag;
use App\Models\YouTubeMusicAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->account = YouTubeMusicAccount::factory()->create();
    $this->playlist = Playlist::factory()->for($this->account, 'youtubeMusicAccount')->create();
});

/**
 * A library track settled without a recording (pending, not found, failed).
 */
function settledTrack(Playlist $playlist, string $videoId, ResolutionStatus $status): void
{
    libraryTrack($playlist, $videoId);
    RecordingResolution::factory()->create(['external_id' => $videoId, 'status' => $status, 'recording_id' => null]);
}

function resolvedTrack(Playlist $playlist, string $videoId): Recording
{
    libraryTrack($playlist, $videoId);
    $recording = Recording::factory()->create();
    RecordingResolution::factory()->create(['external_id' => $videoId, 'recording_id' => $recording->id]);

    return $recording;
}

function describeWith(Recording $recording, MetadataSource ...$sources): void
{
    foreach ($sources as $source) {
        Enrichment::factory()->create(['subject_key' => $recording->id, 'source' => $source]);
    }
}

it('counts where each library track stands', function (): void {
    $described = resolvedTrack($this->playlist, 'video-aaaa1');
    $halfDescribed = resolvedTrack($this->playlist, 'video-aaaa2');
    settledTrack($this->playlist, 'video-aaaa3', ResolutionStatus::NotFound);
    settledTrack($this->playlist, 'video-aaaa4', ResolutionStatus::Pending);
    libraryTrack($this->playlist, 'video-aaaa5');
    libraryTrack(Playlist::factory()->for($this->account, 'youtubeMusicAccount')->create(), 'video-aaaa1');
    describeWith($described, MetadataSource::CreditsFm, MetadataSource::MusicBrainz);
    describeWith($halfDescribed, MetadataSource::MusicBrainz);
    $described->tags()->attach(Tag::named('Rock', isGenre: true), ['source' => 'musicbrainz', 'weight' => 80]);
    $halfDescribed->tags()->attach(Tag::named('90s', isGenre: false), ['source' => 'musicbrainz', 'weight' => 40]);

    $summary = resolve(SummarizeLibraryEnrichment::class)->handle($this->account);

    expect($summary->state)->toBe(LibraryEnrichmentState::Running)
        ->and($summary->total)->toBe(5)
        ->and($summary->resolved)->toBe(2)
        ->and($summary->notFound)->toBe(1)
        ->and($summary->failed)->toBe(0)
        ->and($summary->pending)->toBe(2)
        ->and($summary->described)->toBe(1)
        ->and($summary->withGenre)->toBe(1);
});

it('leaves other libraries out', function (): void {
    resolvedTrack(Playlist::factory()->create(), 'video-other');

    $summary = resolve(SummarizeLibraryEnrichment::class)->handle($this->account);

    expect($summary->total)->toBe(0)
        ->and($summary->resolved)->toBe(0)
        ->and($summary->state)->toBe(LibraryEnrichmentState::Idle);
});

it('is done once every track is settled and described', function (): void {
    describeWith(resolvedTrack($this->playlist, 'video-aaaa1'), MetadataSource::CreditsFm, MetadataSource::MusicBrainz);
    settledTrack($this->playlist, 'video-aaaa2', ResolutionStatus::NotFound);

    expect(resolve(SummarizeLibraryEnrichment::class)->handle($this->account)->state)->toBe(LibraryEnrichmentState::Done);
});

it('asks for attention when settled tracks failed', function (): void {
    settledTrack($this->playlist, 'video-aaaa1', ResolutionStatus::Failed);

    $summary = resolve(SummarizeLibraryEnrichment::class)->handle($this->account);

    expect($summary->state)->toBe(LibraryEnrichmentState::Attention)
        ->and($summary->failed)->toBe(1);
});

it('reports a paused enrichment queue while work remains', function (): void {
    settledTrack($this->playlist, 'video-aaaa1', ResolutionStatus::Pending);
    Queue::pause(config()->string('queue.default'), 'enrichment');

    expect(resolve(SummarizeLibraryEnrichment::class)->handle($this->account)->state)->toBe(LibraryEnrichmentState::Paused);
});

it('lists the library tracks described last, with what was learned', function (): void {
    $older = resolvedTrack($this->playlist, 'video-aaaa1');
    $latest = resolvedTrack($this->playlist, 'video-aaaa2');
    $latest->update(['release_date' => '2012-06-01']);
    resolvedTrack(Playlist::factory()->create(), 'video-other');
    Enrichment::factory()->create(['subject_key' => $older->id, 'fetched_at' => now()->subMinutes(5)]);
    Enrichment::factory()->create(['subject_key' => $latest->id, 'fetched_at' => now()->subMinute()]);
    $latest->tags()->attach(Tag::named('Alternative Rock', isGenre: true), ['source' => 'musicbrainz', 'weight' => 90]);
    $latest->tags()->attach(Tag::named('Rock', isGenre: true), ['source' => 'musicbrainz', 'weight' => 100]);
    $latest->tags()->attach(Tag::named('Stadium', isGenre: false), ['source' => 'musicbrainz', 'weight' => 100]);
    $latest->credits()->create(['contributor_id' => Contributor::factory()->create()->id, 'credit_type' => CreditType::Artist, 'role' => '', 'credit_attributes' => [], 'source' => 'credits_fm']);

    $recent = resolve(SummarizeLibraryEnrichment::class)->handle($this->account)->recent;

    expect($recent)->toHaveCount(2)
        ->and($recent[0]->videoId)->toBe('video-aaaa2')
        ->and($recent[0]->title)->toBe('Survival')
        ->and($recent[0]->genres)->toBe(['rock', 'alternative rock'])
        ->and($recent[0]->creditCount)->toBe(1)
        ->and($recent[0]->year)->toBe(2012)
        ->and($recent[1]->videoId)->toBe('video-aaaa1');
});
