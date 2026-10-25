import { createApp } from 'vue';
import type { App as VueApp } from 'vue';
import type { ContentScriptContext } from 'wxt/utils/content-script-context';
import type { ShadowRootContentScriptUi } from 'wxt/utils/content-script-ui/shadow-root';
import type { LookupBody } from '@/lib/api';
import { ask } from '@/lib/messages';
import type { Action } from '@/lib/youtube/actions';
import { fetchMix } from '@/lib/youtube/mix';
import type { Target } from '@/lib/youtube/targets';
import SonderDrawer from './SonderDrawer.vue';

let current: ShadowRootContentScriptUi<VueApp> | null = null;

export function closeDrawer(): void {
    current?.remove();
    current = null;
}

/** Opens the Sonder drawer for a video or a mix, replacing any open one. */
export async function openDrawer(
    ctx: ContentScriptContext,
    target: Target,
    run: (action: Action, target: Target) => Promise<void>,
): Promise<void> {
    closeDrawer();

    const ui = await createShadowRootUi(ctx, {
        name: 'sonder-drawer',
        position: 'inline',
        anchor: 'body',
        append: 'last',
        onMount(container) {
            const app = createApp(SonderDrawer, {
                target,
                deps: {
                    lookup: (body: LookupBody) => ask({ type: 'lookup', body }),
                    loadMix: (listId: string, seedVideoId: string | null) =>
                        fetchMix(listId, seedVideoId, document),
                    run,
                    close: closeDrawer,
                },
            });
            app.mount(container);

            return app;
        },
        onRemove: (app) => app?.unmount(),
    });

    ui.mount();
    current = ui;
    ui.uiContainer.querySelector<HTMLElement>('aside')?.focus();
}
