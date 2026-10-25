import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import App from './App.vue';

describe('popup', () => {
    it('names Sonder', () => {
        expect(mount(App).text()).toContain('Sonder');
    });
});
