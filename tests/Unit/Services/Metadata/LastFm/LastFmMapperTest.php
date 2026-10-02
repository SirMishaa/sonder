<?php

declare(strict_types=1);

use App\Services\Metadata\Data\ChartPosition;
use App\Services\Metadata\Data\SimilarArtist;
use App\Services\Metadata\Data\SimilarTrack;
use App\Services\Metadata\Data\WeightedTag;
use App\Services\Metadata\LastFm\LastFmMapper;

/**
 * @param  list<WeightedTag>  $tags
 * @return list<string>
 */
function tagNames(array $tags): array
{
    return array_map(fn (WeightedTag $tag): string => $tag->name, $tags);
}

it('reads the corrected track and its duration in milliseconds', function (): void {
    $track = LastFmMapper::track(metadataFixture('lastfm-track-info'));

    expect($track?->title)->toBe('Mr. Loverman')
        ->and($track?->artist)->toBe('Ricky Montgomery')
        ->and($track?->durationSeconds)->toBe(216)
        ->and(LastFmMapper::track(metadataFixture('lastfm-track-info-without-duration'))?->durationSeconds)->toBeNull()
        ->and(LastFmMapper::track(metadataFixture('lastfm-not-found')))->toBeNull();
});

it('reads the popularity of a track and of an artist', function (): void {
    $track = LastFmMapper::popularity(metadataFixture('lastfm-track-info'));
    $artist = LastFmMapper::popularity(metadataFixture('lastfm-artist-info'));

    expect([$track?->listeners, $track?->playcount])->toBe([833437, 10346073])
        ->and([$artist?->listeners, $artist?->playcount])->toBe([171227, 8932613]);
});

it('keeps the strongest tags, weight five and over', function (): void {
    expect(tagNames(LastFmMapper::tags(metadataFixture('lastfm-track-top-tags'))))->toBe(['banana fish', 'indie', 'pop', 'indie pop', 'ash', 'indie rock', 'eiji'])
        ->and(tagNames(LastFmMapper::tags(metadataFixture('lastfm-artist-top-tags'))))->toBe(['gothic rock', 'gothic metal', 'german', 'glam rock', 'industrial metal', 'gothic'])
        ->and(LastFmMapper::tags(metadataFixture('lastfm-artist-top-tags'))[1]->weight)->toBe(79)
        ->and(LastFmMapper::tags(metadataFixture('lastfm-artist-top-tags'))[0]->isGenre)->toBeFalse()
        ->and(LastFmMapper::tags(metadataFixture('lastfm-track-top-tags-empty')))->toBe([]);
});

it('caps the tags at twenty and folds spellings of the same tag', function (): void {
    $tags = array_map(fn (int $index): array => ['name' => "tag {$index}", 'count' => 100 - $index], range(1, 30));
    $tags[] = ['name' => 'Hip-Hop', 'count' => 99];
    array_unshift($tags, ['name' => 'hip hop', 'count' => 100]);

    $mapped = LastFmMapper::tags(['toptags' => ['tag' => $tags]]);

    expect($mapped)->toHaveCount(20)
        ->and(array_filter($mapped, fn (WeightedTag $tag): bool => $tag->name === 'hip-hop'))->toBe([]);
});

it('reads similar tracks and artists with their match', function (): void {
    $tracks = LastFmMapper::similarTracks(metadataFixture('lastfm-track-similar'));
    $artists = LastFmMapper::similarArtists(metadataFixture('lastfm-artist-similar'));

    expect($tracks)->toHaveCount(5)
        ->and($tracks[0])->toEqual(new SimilarTrack('Six Feet Underground', 'Lord of the Lost', 1.0))
        ->and($artists)->toHaveCount(5)
        ->and($artists[0])->toEqual(new SimilarArtist('Chris Harms', 1.0));
});

it('ranks chart entries by their position', function (): void {
    $global = LastFmMapper::chart(metadataFixture('lastfm-chart-top-tracks'));
    $belgium = LastFmMapper::chart(metadataFixture('lastfm-geo-top-tracks'));

    expect($global)->toHaveCount(5)
        ->and($global[0])->toEqual(new ChartPosition(1, 'NICOLE KIDMAN', 'ADÉLA', 191737, 2056383))
        ->and($global[4]->rank)->toBe(5)
        ->and($belgium[0])->toEqual(new ChartPosition(1, "Ain't In LA", 'ADÉLA', 455, null));
});
