<?php

declare(strict_types=1);

use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\MusicBrainz\MusicBrainzMapper;

it('reads the recordings of an isrc with their length in seconds', function (): void {
    $recordings = MusicBrainzMapper::recordings(metadataFixture('musicbrainz-isrc'));

    expect($recordings)->toHaveCount(1)
        ->and($recordings[0]->mbid)->toBe('464d783d-1be7-4e1c-a75b-2b568eb20454')
        ->and($recordings[0]->durationSeconds)->toBe(257)
        ->and($recordings[0]->artists)->toBe([['mbid' => '9c9f1380-2516-4fc9-a3e6-f9f61941d090', 'name' => 'Muse']]);
});

it('keeps the search score, the disambiguation and a full release date', function (): void {
    $recordings = MusicBrainzMapper::recordings(metadataFixture('musicbrainz-recording-search'));

    expect($recordings[0]->score)->toBe(100)
        ->and($recordings[1]->disambiguation)->toBe('instrumental')
        ->and($recordings[1]->firstReleaseDate)->toBe('2012-01-01');
});

it('reads a recording with its isrcs and first release date', function (): void {
    $recording = MusicBrainzMapper::recording(metadataFixture('musicbrainz-recording'));

    expect($recording->isrcs)->toContain('GBAHT1200434')
        ->and($recording->firstReleaseDate)->toBe('2012-06-27');
});

it('weighs tags against the most voted one and marks genres', function (): void {
    $tags = MusicBrainzMapper::tags(metadataFixture('musicbrainz-artist'));
    $byName = collect($tags)->keyBy(fn (WeightedTag $tag): string => $tag->name);

    expect($byName->get('alternative rock')?->weight)->toBe(100)
        ->and($byName->get('alternative rock')?->isGenre)->toBeTrue()
        ->and($byName->get('alternative dance')?->weight)->toBe((int) round(2 / 32 * 100))
        ->and($tags)->toHaveCount(count(array_unique(array_map(fn (WeightedTag $tag): string => $tag->name, $tags))));
});
