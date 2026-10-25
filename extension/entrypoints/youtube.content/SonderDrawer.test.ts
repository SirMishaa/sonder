import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { LookupResult } from '@/lib/api';
import type { Result } from '@/lib/messages';
import type { MixItem } from '@/lib/youtube/mix';
import type { Target } from '@/lib/youtube/targets';
import SonderDrawer from './SonderDrawer.vue';

const VIDEO: Target = {
    kind: 'video',
    videoId: 'N9SZaOJEWXU',
    title: 'Muse - Sunburn',
    artists: 'Muse',
};

const MIX: Target = {
    kind: 'mix',
    listId: 'RDN9SZaOJEWXU',
    seedVideoId: 'N9SZaOJEWXU',
    title: 'Mix - Muse - Sunburn',
};

function item(videoId: string, title: string): MixItem {
    return {
        videoId,
        title,
        artists: 'Muse',
        durationSeconds: 200,
        thumbnailUrl: null,
    };
}

function knowledge(
    overrides: Partial<App.Data.VideoKnowledgeData> = {},
): App.Data.VideoKnowledgeData {
    return {
        inLibrary: false,
        playlists: [],
        listenCount: 0,
        lastListenedAt: null,
        ...overrides,
    };
}

function setup(target: Target, lookup: () => Promise<Result<LookupResult>>) {
    const deps = {
        lookup: vi.fn(lookup),
        loadMix: vi.fn(async () => [
            item('N9SZaOJEWXU', 'Sunburn'),
            item('dbB-mICjkQM', 'Plug In Baby'),
            item('3dm_5qWWDV8', 'Hysteria'),
        ]),
        run: vi.fn(async () => undefined),
        close: vi.fn(),
    };

    return { drawer: mount(SonderDrawer, { props: { target, deps } }), deps };
}

describe('SonderDrawer', () => {
    it('shows what Sonder knows about a video', async () => {
        const { drawer, deps } = setup(VIDEO, async () => ({
            ok: true,
            value: {
                videos: {
                    N9SZaOJEWXU: knowledge({
                        inLibrary: true,
                        playlists: ['Gaming', 'Rock FR'],
                        listenCount: 31,
                        lastListenedAt: '2026-10-23T10:00:00+00:00',
                    }),
                },
                list: null,
            },
        }));
        await flushPromises();

        expect(deps.lookup).toHaveBeenCalledWith({
            videoIds: ['N9SZaOJEWXU'],
            listId: null,
        });
        expect(drawer.text()).toContain('Muse - Sunburn');
        expect(drawer.text()).toContain('Dans ta bibliothèque');
        expect(drawer.text()).toContain('Gaming, Rock FR');
        expect(drawer.text()).toContain('31 écoutes');
    });

    it('lists mix tracks with their status', async () => {
        const { drawer, deps } = setup(MIX, async () => ({
            ok: true,
            value: {
                videos: {
                    N9SZaOJEWXU: knowledge({ inLibrary: true, listenCount: 3 }),
                    'dbB-mICjkQM': knowledge({ listenCount: 2 }),
                    '3dm_5qWWDV8': knowledge(),
                },
                list: {
                    sentBefore: true,
                    lastSentAt: '2026-10-24T10:00:00+00:00',
                },
            },
        }));
        await flushPromises();

        expect(deps.lookup).toHaveBeenCalledWith({
            videoIds: ['N9SZaOJEWXU', 'dbB-mICjkQM', '3dm_5qWWDV8'],
            listId: 'RDN9SZaOJEWXU',
        });
        expect(drawer.text()).toContain('3 titres · 1 dans ta bibliothèque');
        expect(drawer.text()).toContain('Déjà envoyé');
        expect(
            drawer
                .findAll('[data-test=track-status]')
                .map((status) => status.text()),
        ).toEqual(['✓ Biblio', 'Déjà écouté', 'Jamais joué']);
    });

    it('runs an action from the footer', async () => {
        const { drawer, deps } = setup(VIDEO, async () => ({
            ok: true,
            value: { videos: { N9SZaOJEWXU: knowledge() }, list: null },
        }));
        await flushPromises();

        await drawer.get('[data-test=action-play_now]').trigger('click');

        expect(deps.run).toHaveBeenCalledWith('play_now', VIDEO);
    });

    it('shows the signed-out state', async () => {
        const { drawer } = setup(VIDEO, async () => ({
            ok: false,
            error: 'signed-out',
        }));
        await flushPromises();

        expect(drawer.text()).toContain("Connecte l'extension");
        expect(drawer.get('a').attributes('href')).toMatch(
            /\/settings\/extension$/,
        );
    });

    it('shows a skeleton while loading', () => {
        const { drawer } = setup(VIDEO, () => new Promise(() => undefined));

        expect(drawer.find('[data-test=skeleton]').exists()).toBe(true);
    });

    it('closes on Escape', async () => {
        const { drawer, deps } = setup(
            VIDEO,
            () => new Promise(() => undefined),
        );

        await drawer.trigger('keydown', { key: 'Escape' });

        expect(deps.close).toHaveBeenCalledOnce();
    });
});
