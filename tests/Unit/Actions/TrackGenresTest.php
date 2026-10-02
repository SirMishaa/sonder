<?php

declare(strict_types=1);

use App\Actions\TrackGenres;
use App\Enums\CreditType;
use App\Enums\ResolutionStatus;
use App\Models\Contributor;
use App\Models\Recording;
use App\Models\RecordingResolution;
use App\Models\Tag;

/**
 * A recording the given video resolves to.
 */
function recordingFor(string $videoId): Recording
{
    return RecordingResolution::factory()->create(['external_id' => $videoId])->recording()->firstOrFail();
}

it('gives each video its strongest genres, summed across sources', function (): void {
    $recording = recordingFor('video000001');
    $recording->tags()->attach(Tag::named('Rock', isGenre: true), ['source' => 'musicbrainz', 'weight' => 60]);
    $recording->tags()->attach(Tag::named('Rock', isGenre: true), ['source' => 'lastfm', 'weight' => 30]);
    $recording->tags()->attach(Tag::named('Alternative Rock', isGenre: true), ['source' => 'musicbrainz', 'weight' => 80]);
    $recording->tags()->attach(Tag::named('Britpop', isGenre: true), ['source' => 'musicbrainz', 'weight' => 10]);
    $recording->tags()->attach(Tag::named('seen live', isGenre: false), ['source' => 'lastfm', 'weight' => 100]);

    expect(resolve(TrackGenres::class)->handle(['video000001']))->toBe(['video000001' => ['rock', 'alternative rock']]);
});

it('falls back on the genres of the main artist', function (): void {
    $recording = recordingFor('video000001');
    $artist = Contributor::factory()->create();
    $recording->credits()->create(['contributor_id' => $artist->id, 'credit_type' => CreditType::Artist, 'role' => '', 'credit_attributes' => [], 'source' => 'lastfm']);
    $artist->tags()->attach(Tag::named('Gothic Rock', isGenre: true), ['source' => 'lastfm', 'weight' => 100]);

    expect(resolve(TrackGenres::class)->handle(['video000001']))->toBe(['video000001' => ['gothic rock']]);
});

it('leaves out videos without a recording or a genre', function (): void {
    recordingFor('video000002');
    RecordingResolution::factory()->create(['external_id' => 'video000003', 'status' => ResolutionStatus::NotFound, 'recording_id' => null]);

    expect(resolve(TrackGenres::class)->handle(['video000002', 'video000003', 'video000004']))->toBe([])
        ->and(resolve(TrackGenres::class)->handle([]))->toBe([]);
});
