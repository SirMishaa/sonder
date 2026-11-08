import { useLocalStorage } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import type { Ref } from 'vue';

export type AlbumGroupData = {
    albumId: string | null;
    title: string;
    tracks: App.Data.CatalogTrackData[];
};

/**
 * "List / By album" preference plus the grouping itself. Groups follow the
 * first appearance of each album in the (already sorted) list; tracks
 * without an album are collected under "Singles". An album the track does
 * not name is titled from `albums`, else "Unknown album".
 */
export function useAlbumGrouping(): {
    grouped: Ref<boolean>;
    group: (
        tracks: App.Data.CatalogTrackData[],
        albums?: App.Data.CatalogAlbumData[],
    ) => AlbumGroupData[];
} {
    const grouped = useLocalStorage('sonder.grouped', false);

    function group(
        tracks: App.Data.CatalogTrackData[],
        albums: App.Data.CatalogAlbumData[] = [],
    ): AlbumGroupData[] {
        const groups = new Map<string, AlbumGroupData>();

        for (const track of tracks) {
            const key = track.albumId ?? '';
            let entry = groups.get(key);

            if (!entry) {
                entry = {
                    albumId: track.albumId,
                    title: track.albumId
                        ? (track.album ??
                          albums.find(({ id }) => id === track.albumId)
                              ?.title ??
                          trans('Unknown album'))
                        : trans('Singles'),
                    tracks: [],
                };
                groups.set(key, entry);
            }

            entry.tracks.push(track);
        }

        return [...groups.values()];
    }

    return { grouped, group };
}
