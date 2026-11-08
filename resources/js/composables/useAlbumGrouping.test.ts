// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import { useAlbumGrouping } from '@/composables/useAlbumGrouping';

function track(
    videoId: string,
    album: string | null,
    albumId: string | null,
): App.Data.CatalogTrackData {
    return {
        videoId,
        title: `Title ${videoId}`,
        artists: 'Artist',
        album,
        duration: '3:00',
        durationSeconds: 180,
        thumbnailUrl: null,
        isExplicit: false,
        isAvailable: true,
        genres: [],
        artistId: null,
        albumId,
        year: null,
        plays: null,
        inLibrary: false,
    };
}

describe('useAlbumGrouping', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('groups tracks by album in sorted order', () => {
        const { group } = useAlbumGrouping();

        const groups = group([
            track('1', 'B side', 'b'),
            track('2', 'A side', 'a'),
            track('3', 'B side', 'b'),
        ]);

        expect(groups.map((g) => [g.albumId, g.title])).toEqual([
            ['b', 'B side'],
            ['a', 'A side'],
        ]);
        expect(groups[0].tracks.map((t) => t.videoId)).toEqual(['1', '3']);
    });

    it('puts tracks without album under Singles', () => {
        const { group } = useAlbumGrouping();

        const groups = group([
            track('1', null, null),
            track('2', 'A', 'a'),
            track('3', null, null),
        ]);

        expect(groups).toHaveLength(2);
        expect(groups[0]).toMatchObject({ albumId: null, title: 'Singles' });
        expect(groups[0].tracks.map((t) => t.videoId)).toEqual(['1', '3']);
    });

    it('names an album without title from the albums lookup', () => {
        const { group } = useAlbumGrouping();
        const album = {
            id: 'a',
            title: 'Mutter',
            type: 'Album',
            year: 2001,
            artists: 'Rammstein',
            artistId: null,
            thumbnailUrl: null,
            trackCount: 11,
        };

        expect(group([track('1', null, 'a')], [album])[0]).toMatchObject({
            albumId: 'a',
            title: 'Mutter',
        });
        expect(group([track('2', null, 'b')])[0].title).not.toBe('Singles');
    });

    it('persists the grouped flag', async () => {
        expect(useAlbumGrouping().grouped.value).toBe(false);

        useAlbumGrouping().grouped.value = true;
        await nextTick();

        expect(useAlbumGrouping().grouped.value).toBe(true);
    });
});
