<?php

declare(strict_types=1);

use App\Actions\ResolveRecordings;
use App\Enums\Provider;
use App\Enums\ResolutionMethod;
use App\Enums\ResolutionStatus;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Services\Metadata\CreditsFm\CreditsFmGateway;
use App\Services\Metadata\Data\TrackToResolve;
use App\Services\Metadata\EnrichmentTelemetry;
use App\Services\Metadata\MusicBrainz\MusicBrainzGateway;
use Tests\Support\FakeCreditsFmGateway;
use Tests\Support\FakeEnrichmentTelemetry;
use Tests\Support\FakeMusicBrainzGateway;

beforeEach(function (): void {
    $this->creditsFm = new FakeCreditsFmGateway();
    $this->musicBrainz = new FakeMusicBrainzGateway();
    $this->telemetry = new FakeEnrichmentTelemetry();
    app()->instance(CreditsFmGateway::class, $this->creditsFm);
    app()->instance(MusicBrainzGateway::class, $this->musicBrainz);
    app()->instance(EnrichmentTelemetry::class, $this->telemetry);
});

function survival(?int $duration = 258, string $title = 'Survival (Official Video)', string $artists = 'Muse'): TrackToResolve
{
    return new TrackToResolve(Provider::YouTubeMusic, 'UcOUJM08bYk', $title, $artists, $duration);
}

/**
 * The search fixture with only its second hit, the 257 s studio length,
 * and without the "instrumental" disambiguation.
 *
 * @return array<string, mixed>
 */
function studioSearchHit(int $score = 100): array
{
    $search = metadataFixture('musicbrainz-recording-search');
    $recordings = is_array($search['recordings'] ?? null) ? $search['recordings'] : [];
    $hit = is_array($recordings[1] ?? null) ? $recordings[1] : [];
    unset($hit['disambiguation']);
    $hit['score'] = $score;

    return ['recordings' => [$hit]];
}

it('resolves through a credits.fm isrc that MusicBrainz confirms', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);

    expect($recordings)->toHaveCount(1)
        ->and($recordings[0]->mbid)->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454')
        ->and($recordings[0]->isrc)->toBe('GBAHT1200434')
        ->and($recordings[0]->duration_seconds)->toBe(257);

    $resolution = RecordingResolution::query()->sole();
    expect($resolution->status)->toBe(ResolutionStatus::Resolved)
        ->and($resolution->method)->toBe(ResolutionMethod::CreditsFm)
        ->and($resolution->confidence)->toBe(1.0)
        ->and($resolution->recording_id)->toBe($recordings[0]->id)
        ->and($resolution->next_attempt_at?->toIso8601String())->toBe(now()->addDays(90)->toIso8601String());
});

it('keeps the isrc path when the source duration is unknown', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    resolve(ResolveRecordings::class)->handle([survival(duration: null)]);

    expect(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::Resolved)
        ->and(RecordingResolution::query()->sole()->confidence)->toBe(1.0);
});

it('rejects a MusicBrainz recording whose length is too far from the source', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    resolve(ResolveRecordings::class)->handle([survival(duration: 300)]);

    expect(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound);
});

it('keeps an isrc MusicBrainz lacks when credits.fm details match', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->creditsFm->details['GBAHT1200434'] = metadataFixture('credits-fm-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);

    expect($recordings[0]->mbid)->toBeNull()
        ->and($recordings[0]->isrc)->toBe('GBAHT1200434')
        ->and(RecordingResolution::query()->sole()->confidence)->toBe(0.6);
});

it('rejects an isrc credits.fm made up', function (): void {
    $this->creditsFm->isrcs['Nobody Atall|zzzz nothing here qqq'] = 'USJKL0700030';
    $this->creditsFm->details['USJKL0700030'] = ['isrc' => 'USJKL0700030', 'recording_title' => 'Something Else', 'artist_names' => ['Someone']];

    $recordings = resolve(ResolveRecordings::class)->handle([
        new TrackToResolve(Provider::YouTubeMusic, 'aaaaaaaaaaa', 'zzzz nothing here qqq', 'Nobody Atall', 200),
    ]);

    expect($recordings)->toBe([])
        ->and(Recording::query()->count())->toBe(0)
        ->and(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound)
        ->and(RecordingResolution::query()->sole()->next_attempt_at?->toIso8601String())->toBe(now()->addDays(30)->toIso8601String());
});

it('falls back to a confident MusicBrainz search', function (): void {
    $this->musicBrainz->searches['Muse|Survival'] = studioSearchHit();

    $recordings = resolve(ResolveRecordings::class)->handle([survival(duration: 257)]);

    expect($recordings[0]->mbid)->toBe('99a92728-88ba-4cae-a6fa-39a66d07fc03')
        ->and(RecordingResolution::query()->sole()->method)->toBe(ResolutionMethod::MusicBrainzSearch)
        ->and(RecordingResolution::query()->sole()->confidence)->toBe(0.9);
});

it('skips live and instrumental search hits', function (): void {
    $this->musicBrainz->searches['Muse|Survival'] = metadataFixture('musicbrainz-recording-search');

    resolve(ResolveRecordings::class)->handle([survival(duration: 257)]);

    expect(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound);
});

it('accepts a search hit without a duration only at full score', function (): void {
    $this->musicBrainz->searches['Muse|Survival'] = studioSearchHit(score: 95);

    resolve(ResolveRecordings::class)->handle([survival(duration: null)]);

    expect(RecordingResolution::query()->sole()->status)->toBe(ResolutionStatus::NotFound);
});

it('tries the title both ways', function (): void {
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival(title: 'Muse - Survival', artists: 'Some Fan Channel')]);

    expect($recordings)->toHaveCount(1)
        ->and(RecordingResolution::query()->sole()->query_artist)->toBe('Muse');
});

it('matches a band whose name holds an ampersand', function (): void {
    $this->creditsFm->isrcs['Simon|The Boxer'] = 'USSM16900001';
    $this->musicBrainz->isrcs['USSM16900001'] = ['recordings' => [[
        'id' => '11111111-1111-1111-1111-111111111111',
        'title' => 'The Boxer',
        'length' => 308000,
        'artist-credit' => [['name' => 'Simon & Garfunkel', 'artist' => ['id' => '5d02f264-e225-41ff-83f7-d9b1f0b1874a', 'name' => 'Simon & Garfunkel']]],
    ]]];

    $recordings = resolve(ResolveRecordings::class)->handle([
        new TrackToResolve(Provider::YouTubeMusic, 'bbbbbbbbbbb', 'The Boxer', 'Simon & Garfunkel', 308),
    ]);

    expect($recordings)->toHaveCount(1);
});

it('reuses the recording another source already resolved to', function (): void {
    $existing = Recording::factory()->create(['mbid' => '464d783d-1be7-4e1c-a75b-2b568eb20454', 'isrc' => null]);
    $this->creditsFm->isrcs['Muse|Survival'] = 'GBAHT1200434';
    $this->musicBrainz->isrcs['GBAHT1200434'] = metadataFixture('musicbrainz-isrc');

    $recordings = resolve(ResolveRecordings::class)->handle([survival()]);

    expect($recordings[0]->id)->toBe($existing->id)
        ->and($recordings[0]->isrc)->toBe('GBAHT1200434')
        ->and(Recording::query()->count())->toBe(1);
});

it('does not ask credits.fm again for readings it already answered', function (): void {
    resolve(ResolveRecordings::class)->handle([survival()]);
    resolve(ResolveRecordings::class)->handle([survival()]);

    expect(array_count_values($this->creditsFm->calls)['resolve_batch'] ?? 0)->toBe(1);
});

it('asks nothing for an empty batch', function (): void {
    expect(resolve(ResolveRecordings::class)->handle([]))->toBe([])
        ->and($this->creditsFm->calls)->toBe([]);
});

it('reports each resolution', function (): void {
    resolve(ResolveRecordings::class)->handle([survival()]);

    expect($this->telemetry->resolutions)->toBe([['status' => 'not_found', 'method' => null, 'confidence' => null]]);
});
