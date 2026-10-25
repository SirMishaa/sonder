import { describe, expect, it, vi } from 'vitest';
import next from './fixtures/next-mix.json';
import { fetchMix, parseNextResponse } from './mix';

describe('parseNextResponse', () => {
    it('parses mix items', () => {
        const items = parseNextResponse(next);

        expect(items).toHaveLength(25);
        expect(items[0]).toEqual({
            videoId: 'Su6YettpXzU',
            title: 'Souffrance ft. Vald - Voraces (Clip Officiel)',
            artists: 'Souffrance',
            durationSeconds: 239,
            thumbnailUrl: 'https://i.ytimg.com/vi/Su6YettpXzU/hqdefault.jpg',
        });
    });

    it('skips items without a video id', () => {
        const videoIds = parseNextResponse(next).map((item) => item.videoId);

        expect(videoIds.every((id) => /^[\w-]{11}$/.test(id))).toBe(true);
    });

    it('returns nothing for an unexpected shape', () => {
        expect(parseNextResponse({ contents: {} })).toEqual([]);
        expect(parseNextResponse(null)).toEqual([]);
    });
});

describe('fetchMix', () => {
    it('asks YouTube for the list with the page client version', async () => {
        document.head.innerHTML =
            '<script>ytcfg.set({"INNERTUBE_CLIENT_VERSION":"2.20261023.01.00"})</script>';
        const fetch = vi.fn(async () => Response.json(next));

        const items = await fetchMix(
            'RDSu6YettpXzU',
            'Su6YettpXzU',
            document,
            fetch,
        );

        expect(items).toHaveLength(25);
        expect(fetch).toHaveBeenCalledWith(
            '/youtubei/v1/next?prettyPrint=false',
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                body: JSON.stringify({
                    context: {
                        client: {
                            clientName: 'WEB',
                            clientVersion: '2.20261023.01.00',
                        },
                    },
                    playlistId: 'RDSu6YettpXzU',
                    videoId: 'Su6YettpXzU',
                }),
            }),
        );
    });
});
