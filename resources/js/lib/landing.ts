/**
 * The showcase library drawn on the landing page. It stands in for a real
 * account, so visitors see Sonder at work without anyone's library being
 * exposed. Covers are generated from `hue`, never fetched.
 */

export type ShowcaseTrack = {
    title: string;
    artists: string;
    album: string;
    duration: string;
    hue: number;
};

export type ShowcasePlaylist = {
    title: string;
    count: number;
    hue: number;
    playing?: boolean;
    changed?: boolean;
};

export type ShowcaseSuggestion = ShowcaseTrack & {
    near: string;
    genres: string[];
};

export const playlists: ShowcasePlaylist[] = [
    { title: 'Late focus', count: 48, hue: 250, playing: true },
    { title: 'Night drive', count: 112, hue: 18, changed: true },
    { title: 'Sunday records', count: 64, hue: 95 },
    { title: 'Loud & heavy', count: 203, hue: 350 },
    { title: 'Found in 2025', count: 37, hue: 160 },
];

export const tracks: ShowcaseTrack[] = [
    {
        title: 'Teardrop',
        artists: 'Massive Attack',
        album: 'Mezzanine',
        duration: '5:29',
        hue: 262,
    },
    {
        title: 'Roads',
        artists: 'Portishead',
        album: 'Dummy',
        duration: '5:05',
        hue: 205,
    },
    {
        title: 'Hyperballad',
        artists: 'Björk',
        album: 'Post',
        duration: '5:21',
        hue: 330,
    },
    {
        title: 'Angel',
        artists: 'Massive Attack',
        album: 'Mezzanine',
        duration: '6:18',
        hue: 262,
    },
    {
        title: 'Glory Box',
        artists: 'Portishead',
        album: 'Dummy',
        duration: '5:06',
        hue: 205,
    },
];

/** Index in `tracks` of the one playing in the showcase. */
export const playingIndex = 1;

/** Length of the playing track, in seconds (5:05). */
export const playingSeconds = 305;

export const suggestions: ShowcaseSuggestion[] = [
    {
        title: 'Dissolved Girl',
        artists: 'Massive Attack',
        album: 'Mezzanine',
        duration: '6:07',
        hue: 262,
        near: 'Portishead',
        genres: ['trip hop', 'downtempo'],
    },
    {
        title: 'Kiara',
        artists: 'Bonobo',
        album: 'Black Sands',
        duration: '3:49',
        hue: 40,
        near: 'Massive Attack',
        genres: ['downtempo', 'electronic'],
    },
    {
        title: 'Ghosts',
        artists: 'Japan',
        album: 'Tin Drum',
        duration: '4:33',
        hue: 12,
        near: 'Björk',
        genres: ['art pop', 'new wave'],
    },
    {
        title: 'Six Underground',
        artists: 'Sneaker Pimps',
        album: 'Becoming X',
        duration: '4:08',
        hue: 300,
        near: 'Portishead',
        genres: ['trip hop'],
    },
];
