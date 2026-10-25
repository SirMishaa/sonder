import { describe, expect, it } from 'vite-plus/test';
import { createPlayer } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import { applyPlayerCommand } from '@/lib/player/playerCommands';
import type { PlayerCommandPayload } from '@/lib/player/playerCommands';

const TRACE = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

function setup() {
    const data = new Map<string, string>();
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: {
            getItem: (key: string) => data.get(key) ?? null,
            setItem: (key: string, value: string) => {
                data.set(key, value);
            },
        },
        now: () => Date.now(),
        notify: () => undefined,
        sync: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();

    return player;
}

function command(
    action: App.Enums.PlayerCommandAction,
    kind: App.Enums.PlayerCommandKind,
    videoIds: string[],
): PlayerCommandPayload {
    return {
        id: 'cmd-1',
        action,
        kind,
        listId: kind === 'mix' ? 'RDmix' : null,
        title: kind === 'mix' ? 'Mix - Foxsky' : 'Muse - Sunburn',
        items: videoIds.map((videoId) => ({
            videoId,
            title: `Title ${videoId}`,
            artists: 'Artist',
            durationSeconds: 200,
            thumbnailUrl: null,
        })),
        traceparent: TRACE,
    };
}

function playing(player: ReturnType<typeof setup>) {
    applyPlayerCommand(player, command('play_now', 'mix', ['a', 'b']));

    return player;
}

describe('applyPlayerCommand', () => {
    it('plays a mix now in place of the queue', () => {
        const player = playing(setup());

        expect(player.current.value?.videoId).toBe('a');
        expect(player.state.queue.map((item) => item.videoId)).toEqual([
            'a',
            'b',
        ]);
        expect(player.state.source).toEqual({
            playlistId: 'RDmix',
            title: 'Mix - Foxsky',
        });
    });

    it('plays a video now without dropping the rest of the queue', () => {
        const player = playing(setup());

        applyPlayerCommand(player, command('play_now', 'video', ['v']));

        expect(player.current.value?.videoId).toBe('v');
        expect(player.upNext.value.map((item) => item.videoId)).toEqual(['b']);
    });

    it('plays next ahead of tracks already queued, keeping their order', () => {
        const player = playing(setup());
        applyPlayerCommand(player, command('enqueue', 'video', ['q']));

        applyPlayerCommand(player, command('play_next', 'mix', ['x', 'y']));

        expect(player.upNext.value.map((item) => item.videoId)).toEqual([
            'x',
            'y',
            'q',
            'b',
        ]);
    });

    it('enqueues behind tracks already queued', () => {
        const player = playing(setup());
        applyPlayerCommand(player, command('enqueue', 'video', ['q']));

        applyPlayerCommand(player, command('enqueue', 'mix', ['x', 'y']));

        expect(player.upNext.value.map((item) => item.videoId)).toEqual([
            'q',
            'x',
            'y',
            'b',
        ]);
    });

    it('marks every track it adds as coming from the extension', () => {
        const player = playing(setup());

        applyPlayerCommand(player, command('play_now', 'video', ['v']));
        applyPlayerCommand(player, command('play_next', 'video', ['n']));
        applyPlayerCommand(player, command('enqueue', 'video', ['q']));

        expect(
            player.state.queue.map((item) => [item.videoId, item.via ?? null]),
        ).toEqual([
            ['a', 'extension'],
            ['v', 'extension'],
            ['n', 'extension'],
            ['q', 'extension'],
            ['b', 'extension'],
        ]);
    });
});
