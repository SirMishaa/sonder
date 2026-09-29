/**
 * Front-end fixtures for features that do not exist yet.
 *
 * Genre tags would come from Last.fm artist tags, and the "near" artist from
 * the discovery engine. Until then these helpers derive stable, plausible
 * values from real library data so the interface renders as it will, and
 * every caller can be found by searching for this module.
 */

const GENRES = [
    'alt rock',
    'post-punk',
    'industrial metal',
    'synth-pop',
    'electronic',
    'indie pop',
    'hip hop',
    'dance',
    'metalcore',
    'french rap',
    'eurodance',
    'electro house',
    'soundtrack',
    'pop rock',
];

function hash(text: string): number {
    let value = 2166136261;

    for (const char of text) {
        value ^= char.charCodeAt(0);
        value = Math.imul(value, 16777619);
    }

    return value >>> 0;
}

export function leadArtist(artists: string): string {
    return artists.split(',')[0]?.trim() || artists;
}

/** Two stable genre tags for an artist. Fixture: will come from Last.fm. */
export function genreTagsFor(artists: string): string[] {
    const seed = hash(leadArtist(artists));
    const first = GENRES[seed % GENRES.length];
    const second = GENRES[(seed >>> 8) % GENRES.length];

    return first === second ? [first] : [first, second];
}

/** An artist from the playlist that a suggestion is "near". Fixture. */
export function nearArtistFrom(
    tracks: App.Data.TrackData[],
    salt: string,
): string | null {
    const artists = [
        ...new Set(tracks.map((track) => leadArtist(track.artists))),
    ].filter((artist) => artist !== 'Unknown artist');

    return artists.length ? artists[hash(salt) % artists.length] : null;
}

export function seededHash(text: string): number {
    return hash(text);
}
