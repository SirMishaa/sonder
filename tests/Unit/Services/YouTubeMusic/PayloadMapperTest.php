<?php

declare(strict_types=1);

use App\Services\YouTubeMusic\PayloadMapper;

$mapper = fn (): PayloadMapper => new PayloadMapper();

it('maps a track', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'videoId' => 'xXp4GnC1Z3Q',
        'title' => 'The Mandalorian',
        'artists' => [(object) ['name' => 'Ludwig Göransson'], (object) ['name' => 'Joseph Shirley']],
        'album' => (object) ['name' => 'Season 1'],
        'duration' => '3:19',
        'duration_seconds' => 199,
        'isExplicit' => true,
        'isAvailable' => true,
    ]);

    expect($track->videoId)->toBe('xXp4GnC1Z3Q')
        ->and($track->title)->toBe('The Mandalorian')
        ->and($track->artists)->toBe('Ludwig Göransson, Joseph Shirley')
        ->and($track->album)->toBe('Season 1')
        ->and($track->durationSeconds)->toBe(199)
        ->and($track->isExplicit)->toBeTrue();
});

it('falls back when a track carries nothing usable', function () use ($mapper): void {
    $track = $mapper()->track((object) []);

    expect($track->title)->toBe('Untitled track')
        ->and($track->artists)->toBe('Unknown artist')
        ->and($track->videoId)->toBeNull()
        ->and($track->album)->toBeNull()
        ->and($track->durationSeconds)->toBeNull()
        ->and($track->isExplicit)->toBeFalse()
        ->and($track->isAvailable)->toBeTrue();
});

it('ignores artist entries that carry no name', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'artists' => [(object) ['name' => 'Hans Zimmer'], (object) ['name' => '  '], 'not an object'],
    ]);

    expect($track->artists)->toBe('Hans Zimmer');
});

it('treats a zero track count as unknown rather than empty', function () use ($mapper): void {
    // The package scrapes this number out of a subtitle string and silently
    // defaults to 0. Trusting it would pin a stale entry in the cache.
    $summary = $mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'count' => 0]);

    expect($summary->trackCount)->toBeNull();
});

it('reads a track count exposed as a numeric string', function () use ($mapper): void {
    $summary = $mapper()->playlistSummary((object) ['playlistId' => 'PL1', 'count' => '42']);

    expect($summary->trackCount)->toBe(42);
});

it('picks the smallest thumbnail wide enough to stay sharp', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'thumbnails' => [
            (object) ['url' => 'small.jpg', 'width' => 60],
            (object) ['url' => 'right.jpg', 'width' => 240],
            (object) ['url' => 'huge.jpg', 'width' => 1080],
        ],
    ]);

    expect($track->thumbnailUrl)->toBe('right.jpg');
});

it('falls back to the largest thumbnail when none is wide enough', function () use ($mapper): void {
    $track = $mapper()->track((object) [
        'thumbnails' => [
            (object) ['url' => 'tiny.jpg', 'width' => 32],
            (object) ['url' => 'biggest.jpg', 'width' => 120],
        ],
    ]);

    expect($track->thumbnailUrl)->toBe('biggest.jpg');
});

it('survives thumbnails that are missing or malformed', function () use ($mapper): void {
    expect($mapper()->track((object) ['thumbnails' => []])->thumbnailUrl)->toBeNull()
        ->and($mapper()->track((object) ['thumbnails' => 'nope'])->thumbnailUrl)->toBeNull()
        ->and($mapper()->track((object) ['thumbnails' => [(object) ['width' => 240]]])->thumbnailUrl)->toBeNull();
});

it('reads an author given either as an object or as a list', function () use ($mapper): void {
    $fromObject = $mapper()->playlistSummary((object) ['author' => (object) ['name' => 'Mishaa']]);
    $fromList = $mapper()->playlistSummary((object) ['author' => [(object) ['name' => 'Mishaa']]]);
    $fromNothing = $mapper()->playlistSummary((object) []);

    expect($fromObject->author)->toBe('Mishaa')
        ->and($fromList->author)->toBe('Mishaa')
        ->and($fromNothing->author)->toBeNull();
});

it('maps a playlist and its tracks', function () use ($mapper): void {
    $playlist = $mapper()->playlist((object) [
        'title' => 'Deep Focus',
        'description' => 'No lyrics',
        'trackCount' => 2,
        'duration' => '48 minutes',
        'tracks' => [
            (object) ['title' => 'One'],
            (object) ['title' => 'Two'],
            'not an object',
        ],
    ], 'PL1');

    expect($playlist->id)->toBe('PL1')
        ->and($playlist->title)->toBe('Deep Focus')
        ->and($playlist->trackCount)->toBe(2)
        ->and($playlist->duration)->toBe('48 minutes')
        ->and($playlist->tracks)->toHaveCount(2);
});

it('counts the tracks it received when the playlist reports no count', function () use ($mapper): void {
    $playlist = $mapper()->playlist((object) ['tracks' => [(object) ['title' => 'One']]], 'PL1');

    expect($playlist->trackCount)->toBe(1);
});

it('prefers a caller supplied count over counting the tracks', function () use ($mapper): void {
    $playlist = $mapper()->playlist((object) ['tracks' => []], 'PL1', fallbackTrackCount: 300);

    expect($playlist->trackCount)->toBe(300);
});

it('maps an account', function () use ($mapper): void {
    $account = $mapper()->account((object) [
        'name' => 'Mishaa',
        'channelId' => 'UC123',
        'is_premium' => true,
    ]);

    expect($account->name)->toBe('Mishaa')
        ->and($account->channelId)->toBe('UC123')
        ->and($account->isPremium)->toBeTrue();
});

it('names an account it cannot read', function () use ($mapper): void {
    $account = $mapper()->account((object) []);

    expect($account->name)->toBe('Unknown account')
        ->and($account->isPremium)->toBeFalse();
});
