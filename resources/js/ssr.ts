import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { i18nVue } from 'laravel-vue-i18n';
import type { DefineComponent } from 'vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { resolveLanguage } from '@/lib/i18n';
import { ensureTemporal } from '@/lib/temporal';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

await ensureTemporal();

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: (title) => (title ? `${title} - ${appName}` : appName),
            resolve: (name) =>
                resolvePageComponent(
                    `./pages/${name}.vue`,
                    import.meta.glob<DefineComponent>('./pages/**/*.vue'),
                ),
            setup: ({ App, props, plugin }) =>
                createSSRApp({ render: () => h(App, props) })
                    .use(plugin)
                    .use(i18nVue, {
                        lang: String(props.initialPage.props.locale),
                        fallbackLang: 'en_US',
                        resolve: resolveLanguage,
                    }),
        }),
    { cluster: true },
);
