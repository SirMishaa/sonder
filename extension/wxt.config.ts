import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'wxt';
import { matchPattern, sonderUrlFor } from './lib/sonderUrl';

export default defineConfig({
    modules: ['@wxt-dev/module-vue'],
    vite: () => ({ plugins: [tailwindcss()] }),
    manifest: ({ mode }) => ({
        name: 'Sonder',
        description: 'Send YouTube videos and mixes to Sonder.',
        permissions: ['storage', 'tabs'],
        host_permissions: [
            '*://*.youtube.com/*',
            matchPattern(sonderUrlFor(mode, process.env.WXT_SONDER_URL)),
        ],
    }),
});
