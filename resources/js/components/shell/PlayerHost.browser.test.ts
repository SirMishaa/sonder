import { expect, it } from 'vite-plus/test';
import { render } from 'vitest-browser-vue';
import PlayerHost from '@/components/shell/PlayerHost.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import type { ListenPayload } from '@/lib/player/listenTracker';

async function mountHost() {
    const unloaded: ListenPayload[] = [];
    const player = createPlayer({
        sender: {
            send: async () => true,
            sendOnUnload: (payload) => unloaded.push(payload),
        },
        storage: null,
    });
    const transport = new FakeTransport();
    let hostElement: HTMLElement | null = null;

    const screen = await render(PlayerHost, {
        props: {
            createTransport: (element: HTMLElement) => {
                hostElement = element;

                return transport;
            },
        },
        global: { provide: { [playerKey]: player } },
    });

    return { screen, player, transport, unloaded, host: () => hostElement };
}

it('keeps the iframe host in the page, invisible and out of reach', async () => {
    const { host } = await mountHost();

    const wrapper = host()?.parentElement as HTMLElement;
    const style = getComputedStyle(wrapper);
    const box = wrapper.getBoundingClientRect();

    expect(wrapper.getAttribute('aria-hidden')).toBe('true');
    expect(wrapper.inert).toBe(true);
    expect(style.display).not.toBe('none');
    expect(style.visibility).not.toBe('hidden');
    expect(style.opacity).toBe('0');
    expect(style.pointerEvents).toBe('none');
    expect(box.width).toBe(1);
    expect(box.height).toBe(1);
    expect(box.right).toBeLessThanOrEqual(0);
});

it('hands its transport to the player', async () => {
    const { player, transport } = await mountHost();

    transport.becomeReady();

    expect(player.state.ready).toBe(true);
});

it('sends the listen in progress when the page is hidden', async () => {
    const { player, transport, unloaded } = await mountHost();
    transport.becomeReady();
    player.playTracks(
        [
            {
                videoId: 'a',
                title: 'A',
                artists: 'X',
                album: null,
                duration: '3:00',
                durationSeconds: 180,
                thumbnailUrl: null,
                isExplicit: false,
                isAvailable: true,
                genres: [],
            },
        ],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    window.dispatchEvent(new PageTransitionEvent('pagehide'));

    expect(unloaded[0]).toMatchObject({
        youtube_video_id: 'a',
        end_reason: 'abandoned',
    });
});

it('destroys its transport when unmounted', async () => {
    const { screen, transport } = await mountHost();

    await screen.unmount();

    expect(transport.calls).toContain('destroy');
});
