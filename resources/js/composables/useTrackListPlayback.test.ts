import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { useTrackListPlayback } from '@/composables/useTrackListPlayback';

const player = vi.hoisted(() => ({
    playTracks: vi.fn(),
    playShuffled: vi.fn(),
    isCurrentTrack: vi.fn(() => false),
}));
const tap = vi.hoisted(() => vi.fn());

vi.mock('@/composables/usePlayer', () => ({ usePlayer: () => player }));
vi.mock('@/composables/useTrackTap', () => ({
    useTrackTap: () => ({ tap, preventSelection: vi.fn() }),
}));

const SOURCE = { playlistId: null, title: 'Zeit' };

function track(videoId: string, isAvailable = true): App.Data.TrackData {
    return {
        videoId,
        title: `Title ${videoId}`,
        artists: 'Rammstein',
        album: 'Zeit',
        duration: '3:00',
        durationSeconds: 180,
        thumbnailUrl: null,
        isExplicit: false,
        isAvailable,
        genres: [],
    };
}

describe('useTrackListPlayback', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('plays the list from a track with the given origin', () => {
        const tracks = [track('a'), track('b')];
        const playback = useTrackListPlayback(
            () => tracks,
            () => SOURCE,
            'search',
        );

        playback.play(1);

        expect(player.playTracks).toHaveBeenCalledWith(
            tracks,
            1,
            SOURCE,
            'search',
        );
    });

    it('shuffles the whole list with the given origin', () => {
        const tracks = [track('a'), track('b'), track('c')];
        const playback = useTrackListPlayback(
            () => tracks,
            () => SOURCE,
            'search',
        );

        playback.shuffle();

        expect(player.playShuffled).toHaveBeenCalledWith(
            tracks,
            SOURCE,
            'search',
        );
    });

    it('ignores taps on unavailable tracks and is unplayable without any', () => {
        const tracks = [track('a', false)];
        const playback = useTrackListPlayback(
            () => tracks,
            () => SOURCE,
            'search',
        );

        playback.onTrackTap({ detail: 1 } as MouseEvent, 0);

        expect(tap).not.toHaveBeenCalled();
        expect(playback.playable.value).toBe(false);
    });
});
