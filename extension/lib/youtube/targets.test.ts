import { beforeEach, describe, expect, it } from 'vitest';
import cards from './fixtures/cards.html?raw';
import { targetFromCard, targetFromWatchPage } from './targets';

function card(id: string): Element {
    return document.querySelector(`#${id}`)!.firstElementChild!;
}

describe('targetFromCard', () => {
    beforeEach(() => {
        document.body.innerHTML = cards;
    });

    it('reads a video card', () => {
        expect(targetFromCard(card('video-lockup'))).toEqual({
            kind: 'video',
            videoId: 'dbB-mICjkQM',
            title: 'Muse - Plug In Baby (Official Video)',
            artists: 'Muse',
        });
    });

    it('reads a video card whose link starts a radio', () => {
        expect(targetFromCard(card('search-video'))).toEqual({
            kind: 'video',
            videoId: 'N9SZaOJEWXU',
            title: 'Muse - Sunburn',
            artists: 'Muse',
        });
    });

    it('reads a mix card', () => {
        expect(targetFromCard(card('mix-lockup'))).toEqual({
            kind: 'mix',
            listId: 'RDN9SZaOJEWXU',
            seedVideoId: 'N9SZaOJEWXU',
            title: 'Mix - Muse - Sunburn',
        });
    });

    it('reads a playlist card as a list of tracks', () => {
        expect(targetFromCard(card('playlist-lockup'))).toMatchObject({
            kind: 'mix',
            listId: 'OLAK5uy_lWeI5Z3so8pcVqFvF_E44B1h5JO366qOc',
            title: 'Showbiz',
        });
    });

    it('returns null for an unknown card', () => {
        expect(targetFromCard(card('ad'))).toBeNull();
    });
});

describe('targetFromWatchPage', () => {
    it('reads the video being watched', () => {
        document.body.innerHTML = `
            <ytd-watch-metadata>
                <h1><yt-formatted-string>Muse - Sunburn</yt-formatted-string></h1>
                <ytd-channel-name><a href="/@muse">Muse</a></ytd-channel-name>
            </ytd-watch-metadata>`;

        expect(
            targetFromWatchPage(
                document,
                new URL(
                    'https://www.youtube.com/watch?v=N9SZaOJEWXU&list=RDN9SZaOJEWXU',
                ),
            ),
        ).toEqual({
            kind: 'video',
            videoId: 'N9SZaOJEWXU',
            title: 'Muse - Sunburn',
            artists: 'Muse',
        });
    });

    it('returns null away from a watch page', () => {
        expect(
            targetFromWatchPage(document, new URL('https://www.youtube.com/')),
        ).toBeNull();
    });
});
