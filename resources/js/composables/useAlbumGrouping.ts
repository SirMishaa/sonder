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
 * without an album are collected under "Singles".
 */
export function useAlbumGrouping(): {
    grouped: Ref<boolean>;
    group: (tracks: App.Data.CatalogTrackData[]) => AlbumGroupData[];
} {
    const grouped = useLocalStorage('sonder.grouped', false);

    function group(tracks: App.Data.CatalogTrackData[]): AlbumGroupData[] {
        const groups = new Map<string, AlbumGroupData>();

        for (const track of tracks) {
            const key = track.albumId ?? '';
            let entry = groups.get(key);

            if (!entry) {
                entry = {
                    albumId: track.albumId,
                    title: track.albumId
                        ? (track.album ?? trans('Singles'))
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
