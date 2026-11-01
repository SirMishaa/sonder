import { reactive } from 'vue';

/**
 * Which full-screen sheet is open on a phone: the now-playing view, and the
 * up-next list above it. Kept apart from the player's `queueOpen`, which is
 * the desktop side panel and is remembered between visits.
 */
const sheets = reactive({ nowPlaying: false, queue: false });

export function useMobileSheets() {
    return {
        sheets,
        openNowPlaying: () => {
            sheets.nowPlaying = true;
        },
        openQueue: () => {
            sheets.queue = true;
        },
        closeAll: () => {
            sheets.nowPlaying = false;
            sheets.queue = false;
        },
    };
}
