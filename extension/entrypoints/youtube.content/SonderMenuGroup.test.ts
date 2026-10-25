import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { Target } from '@/lib/youtube/targets';
import SonderMenuGroup from './SonderMenuGroup.vue';

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

function setup(target: Target) {
    const actions = {
        run: vi.fn(async () => undefined),
        openPanel: vi.fn(),
    };

    return {
        group: mount(SonderMenuGroup, { props: { target, actions } }),
        actions,
    };
}

describe('SonderMenuGroup', () => {
    it('offers the four Sonder actions', () => {
        const { group } = setup(VIDEO);

        expect(
            group.findAll('[data-test=label]').map((label) => label.text()),
        ).toEqual([
            'Lire maintenant',
            'Lire ensuite',
            'Ajouter à la file',
            'Ouvrir le panneau',
        ]);
    });

    it('runs the chosen action on its target', async () => {
        const { group, actions } = setup(MIX);

        await group.findAll('button')[1]!.trigger('click');
        await flushPromises();

        expect(actions.run).toHaveBeenCalledWith('play_next', MIX);
    });

    it('opens the panel for its target', async () => {
        const { group, actions } = setup(VIDEO);

        await group.findAll('button')[3]!.trigger('click');

        expect(actions.openPanel).toHaveBeenCalledWith(VIDEO);
    });

    it('disables the actions while one runs', async () => {
        const { group, actions } = setup(VIDEO);
        actions.run.mockReturnValue(new Promise(() => undefined));

        await group.findAll('button')[0]!.trigger('click');

        expect(
            group.findAll('button')[0]!.attributes('disabled'),
        ).toBeDefined();
    });
});
