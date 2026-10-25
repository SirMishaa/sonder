import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { fakeBrowser } from 'wxt/testing/fake-browser';
import { CONNECT_URL, SONDER_URL } from '@/lib/config';
import { tokenStore } from '@/lib/token';
import App from './App.vue';

describe('popup', () => {
    beforeEach(() => {
        fakeBrowser.reset();
    });

    it('says which Sonder the extension is connected to', async () => {
        await tokenStore().set('1|secret');
        const popup = mount(App);
        await flushPromises();

        expect(popup.text()).toContain('Connecté à Sonder');
        expect(popup.text()).toContain(new URL(SONDER_URL).host);
        expect(popup.get('[data-test=open-sonder]').attributes('href')).toBe(
            `${SONDER_URL}/dashboard`,
        );
    });

    it('explains where the Sonder actions live on YouTube', async () => {
        await tokenStore().set('1|secret');
        const popup = mount(App);
        await flushPromises();

        expect(popup.text()).toContain('⋮');
    });

    it('links to the connection page when signed out', async () => {
        const popup = mount(App);
        await flushPromises();

        expect(popup.text()).toContain('Non connecté');
        expect(popup.get('[data-test=connect]').attributes('href')).toBe(
            CONNECT_URL,
        );
    });
});
