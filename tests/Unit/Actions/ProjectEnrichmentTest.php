<?php

declare(strict_types=1);

use App\Actions\ProjectEnrichment;
use App\Enums\CreditType;
use App\Enums\EnrichmentStatus;
use App\Enums\MetadataSource;
use App\Models\Contributor;
use App\Models\Enrichment;
use App\Models\Recording;
use App\Models\RecordingContributor;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

function describedSurvival(): Recording
{
    $recording = Recording::factory()->create([
        'mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454',
        'isrc' => 'GBAHT1200434',
        'release_date' => null,
    ]);
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, metadataFixture('credits-fm-isrc'));
    Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::MusicBrainz, 'recording', EnrichmentStatus::Done, metadataFixture('musicbrainz-recording'));

    return $recording;
}

function recordingTagWeight(Recording $recording, string $slug): mixed
{
    return DB::table('recording_tags')
        ->join('tags', 'tags.id', '=', 'recording_tags.tag_id')
        ->where('recording_id', $recording->id)
        ->where('slug', $slug)
        ->value('weight');
}

it('projects credits from both sources and fills the identity', function (): void {
    $recording = describedSurvival();

    resolve(ProjectEnrichment::class)->handle($recording);

    $recording->refresh();
    expect($recording->iswc)->toBe('T-912674410-3')
        ->and($recording->release_date?->toDateString())->toBe('2012-01-01')
        ->and(RecordingContributor::query()->where('credit_type', CreditType::Songwriter)->count())->toBe(1)
        ->and(RecordingContributor::query()->where('credit_type', CreditType::Artist)->orderBy('source')->get()->map(fn (RecordingContributor $credit): string => $credit->source->value)->all())->toBe(['credits_fm', 'musicbrainz']);
});

it('shares one contributor between sources through its mbid', function (): void {
    resolve(ProjectEnrichment::class)->handle(describedSurvival());

    expect(Contributor::query()->where('mbid', '9c9f1380-2516-4fc9-a3e6-f9f61941d090')->count())->toBe(1);
});

it('projects MusicBrainz genres as weighted genre tags', function (): void {
    $recording = describedSurvival();

    resolve(ProjectEnrichment::class)->handle($recording);

    expect(recordingTagWeight($recording, 'symphonic rock'))->toBe(100)
        ->and(recordingTagWeight($recording, 'art rock'))->toBe(25)
        ->and(Tag::query()->where('slug', 'art rock')->value('is_genre'))->toBeTrue();
});

it('projects twice without duplicating', function (): void {
    $recording = describedSurvival();

    resolve(ProjectEnrichment::class)->handle($recording);
    $counts = [Contributor::query()->count(), RecordingContributor::query()->count(), DB::table('recording_tags')->count()];
    resolve(ProjectEnrichment::class)->handle($recording);

    expect([Contributor::query()->count(), RecordingContributor::query()->count(), DB::table('recording_tags')->count()])->toBe($counts);
});

it('reuses a name-only contributor instead of creating it again', function (): void {
    $session = ['isrc' => 'GBAHT1200999', 'recording_title' => 'X', 'artist_names' => ['Y'], 'performers' => [['name' => 'Session Player', 'role' => 'guitar', 'credit_type' => 'performer', 'attributes' => []]]];

    foreach (['GBAHT1200998', 'GBAHT1200999'] as $isrc) {
        $recording = Recording::factory()->create(['isrc' => $isrc, 'mbid' => null]);
        Enrichment::store(Enrichment::RECORDING, $recording->id, MetadataSource::CreditsFm, 'isrc', EnrichmentStatus::Done, $session);
        resolve(ProjectEnrichment::class)->handle($recording);
    }

    expect(Contributor::query()->where('normalized_name', 'session player')->count())->toBe(1)
        ->and(RecordingContributor::query()->count())->toBe(2);
});

it('names the main artists still to describe', function (): void {
    $toDescribe = resolve(ProjectEnrichment::class)->handle(describedSurvival());

    expect(array_map(fn (Contributor $contributor): ?string => $contributor->mbid, $toDescribe))->toBe(['9c9f1380-2516-4fc9-a3e6-f9f61941d090']);
});
