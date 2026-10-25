import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { tokenStore } from '@/lib/token';
import App from './App.vue';

describe('popup', () => {
    beforeEach(() => {
        fakeBrowser.reset();
    });

    it('says when the extension is connected', async () => {
        await tokenStore().set('1|secret');
        const popup = mount(App);
        await flushPromises();

        expect(popup.text()).toContain('Connecté à Sonder');
    });

    it('links to the connection page when signed out', async () => {
        const popup = mount(App);
        await flushPromises();

        expect(popup.text()).toContain('Non connecté');
        expect(popup.get('a').attributes('href')).toMatch(
            /\/settings\/extension$/,
        );
    });
});
