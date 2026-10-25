import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'wxt';

const sonderUrl = process.env.WXT_SONDER_URL ?? 'https://sonder.mishaa.dev';

export default defineConfig({
    modules: ['@wxt-dev/module-vue'],
    vite: () => ({ plugins: [tailwindcss()] }),
    manifest: {
        name: 'Sonder',
        description: 'Send YouTube videos and mixes to Sonder.',
        permissions: ['storage', 'tabs'],
        host_permissions: ['*://*.youtube.com/*', `${sonderUrl}/*`],
    },
});
