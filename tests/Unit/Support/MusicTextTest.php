<?php

declare(strict_types=1);

use App\Services\Metadata\Data\TrackQuery;
use App\Support\MusicText;

it('cleans the noise YouTube adds to titles and keeps version markers', function (string $title, string $clean): void {
    expect(MusicText::cleanTitle($title))->toBe($clean);
})->with([
    ['Rammstein - Pussy OFFICIAL MUSIC VIDEO', 'Rammstein - Pussy'],
    ['Survival (Official Video)', 'Survival'],
    ['Survival [Lyrics]', 'Survival'],
    ['Helden (feat. Till Lindemann)', 'Helden'],
    ['Royalty (ft. Neoni) - Extended Version', 'Royalty - Extended Version'],
    ['Sing Me to Sleep (Marshmello Remix)', 'Sing Me to Sleep (Marshmello Remix)'],
    ['Sing Me to Sleep (Instrumental)', 'Sing Me to Sleep (Instrumental)'],
    ['No Beef [Vocal Mix] (feat. Miss Palmer)', 'No Beef [Vocal Mix]'],
]);

it('keeps the first credited artist and drops topic channels', function (string $artists, string $first): void {
    expect(MusicText::firstArtist($artists))->toBe($first);
})->with([
    ['Afrojack, Steve Aoki', 'Afrojack'],
    ['Egzod & Maestro Chives', 'Egzod'],
    ['Muse - Topic', 'Muse'],
    ['Apocalyptica', 'Apocalyptica'],
    ['Feather Lane', 'Feather Lane'],
]);

it('reads "Artist - Title" both ways unless the artist already matches', function (string $title, string $artists, array $readings): void {
    expect(array_map(fn (TrackQuery $query): array => [$query->artist, $query->title], MusicText::queries($title, $artists)))
        ->toBe($readings);
})->with([
    'artist repeated in the title' => ['Apashe - Renaissance 2.0', 'Apashe', [['Apashe', 'Renaissance 2.0']]],
    'fan upload' => ['Rammstein - Pussy OFFICIAL MUSIC VIDEO', 'Alına Lındemann', [['Rammstein', 'Pussy'], ['Alına Lındemann', 'Rammstein - Pussy']]],
    'real title with a dash' => ['Bara Bara - Bere Bere (Remix)', 'Alex Ferrari', [['Bara Bara', 'Bere Bere (Remix)'], ['Alex Ferrari', 'Bara Bara - Bere Bere (Remix)']]],
    'plain' => ['Survival', 'Muse', [['Muse', 'Survival']]],
]);

it('compares artists and titles loosely', function (): void {
    expect(MusicText::sameArtist('Alına Lındemann', 'alina lindemann'))->toBeTrue()
        ->and(MusicText::sameArtist('The Prodigy', 'Prodigy'))->toBeTrue()
        ->and(MusicText::sameArtist('Muse', 'Metallica'))->toBeFalse()
        ->and(MusicText::sameTitle('Survival (Official Video)', 'survival'))->toBeTrue()
        ->and(MusicText::sameTitle('Survival (Live)', 'Survival'))->toBeFalse();
});

it('gives one slug to spellings of the same tag', function (): void {
    expect(MusicText::tagSlug('Hip-Hop'))->toBe('hip hop')
        ->and(MusicText::tagSlug(' hip hop '))->toBe('hip hop')
        ->and(MusicText::tagSlug('Drum & Bass'))->toBe('drum and bass');
});

it('builds the title match key without noise but with versions', function (string $title, string $key): void {
    expect(MusicText::matchTitle($title))->toBe($key);
})->with([
    ['Survival (Official Video)', 'survival'],
    ['Mr. Loverman', 'mr loverman'],
    ['Was ist Heir Los (Live)', 'was ist heir los live'],
    ['NICOLE KIDMAN', 'nicole kidman'],
]);

it('builds the artist match key from the first artist without its article', function (string $artists, string $key): void {
    expect(MusicText::matchArtist($artists))->toBe($key);
})->with([
    ['The Bloodhound Gang', 'bloodhound gang'],
    ['Egzod & Maestro Chives', 'egzod'],
    ['ADÉLA', 'adela'],
    ['Muse - Topic', 'muse'],
]);
