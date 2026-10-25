import { createApp, h, ref } from 'vue';
import { initFaro, reportIssue, trackAction } from '@/lib/faro';
import { ask } from '@/lib/messages';
import { runAction } from '@/lib/youtube/actions';
import type { Action } from '@/lib/youtube/actions';
import { closeMenu, createMenuWatcher, fitMenu } from '@/lib/youtube/menu';
import { fetchMix } from '@/lib/youtube/mix';
import { pauseYouTube } from '@/lib/youtube/player';
import { targetFromWatchPage } from '@/lib/youtube/targets';
import type { Target } from '@/lib/youtube/targets';
import { openDrawer } from './drawer';
import SonderMenuGroup from './SonderMenuGroup.vue';
import SonderToast from './SonderToast.vue';
import './style.css';

const POPUP_LIST =
    'ytd-popup-container tp-yt-iron-dropdown:not([aria-hidden="true"]) yt-list-view-model, ytd-popup-container tp-yt-iron-dropdown:not([aria-hidden="true"]) tp-yt-paper-listbox#items';

/** Waits, at most `timeout` ms, for the menu YouTube opens after a click. */
function nextPopupList(timeout = 1500): Promise<Element | null> {
    return new Promise((resolve) => {
        const find = () =>
            [...document.querySelectorAll(POPUP_LIST)].find(
                (list) =>
                    (list as HTMLElement).offsetParent !== null ||
                    list.isConnected,
            ) ?? null;
        const observer = new MutationObserver(() => {
            const list = find();

            if (list) {
                observer.disconnect();
                resolve(list);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['aria-hidden', 'style'],
        });
        setTimeout(() => {
            observer.disconnect();
            resolve(find());
        }, timeout);
    });
}

export default defineContentScript({
    matches: ['*://www.youtube.com/*'],
    cssInjectionMode: 'ui',
    async main(ctx) {
        initFaro({
            url: import.meta.env.WXT_FARO_URL,
            app: 'sonder-extension',
            version: browser.runtime.getManifest().version,
        });

        const toastMessage = ref<string | null>(null);
        let toastTimer: ReturnType<typeof setTimeout> | undefined;

        function notify(message: string): void {
            toastMessage.value = message;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => (toastMessage.value = null), 3500);
        }

        const toast = await createShadowRootUi(ctx, {
            name: 'sonder-toast',
            position: 'inline',
            anchor: 'body',
            append: 'last',
            onMount(container) {
                const app = createApp({
                    render: () =>
                        h(SonderToast, { message: toastMessage.value }),
                });
                app.mount(container);

                return app;
            },
            onRemove: (app) => app?.unmount(),
        });
        toast.mount();

        const actions = {
            run: (action: Action, target: Target) =>
                runAction(action, target, {
                    send: async (body) => {
                        const result = await ask({ type: 'command', body });

                        if (result.ok) {
                            trackAction({
                                action: body.action,
                                kind: body.kind,
                                items: body.items.length,
                                traceparent: result.value.traceparent,
                            });
                        } else {
                            reportIssue('action.failed', {
                                action: body.action,
                                error: result.error,
                            });
                        }

                        return result;
                    },
                    loadMix: (listId, seedVideoId) =>
                        fetchMix(listId, seedVideoId, document),
                    pause: () => pauseYouTube(),
                    notify,
                }),
            openPanel: (target: Target) =>
                void openDrawer(ctx, target, actions.run),
        };

        async function mount(list: Element, target: Target): Promise<void> {
            const ui = await createShadowRootUi(ctx, {
                name: 'sonder-menu-group',
                position: 'inline',
                anchor: list,
                append: 'last',
                onMount(container) {
                    const app = createApp(SonderMenuGroup, {
                        target,
                        actions: {
                            run: async (action: Action, chosen: Target) => {
                                closeMenu(list);
                                await actions.run(action, chosen);
                            },
                            openPanel: (chosen: Target) => {
                                closeMenu(list);
                                actions.openPanel(chosen);
                            },
                        },
                    });
                    app.mount(container);

                    return app;
                },
                onRemove: (app) => app?.unmount(),
            });
            ui.mount();
            ui.shadowHost.setAttribute('data-sonder-group', '');
            requestAnimationFrame(() => fitMenu(list));
        }

        const watcher = createMenuWatcher({
            mount: (list, target) => void mount(list, target),
            report: reportIssue,
            watchPage: () =>
                targetFromWatchPage(document, new URL(location.href)),
        });

        ctx.addEventListener(
            document,
            'click',
            async (event) => {
                if (!watcher.onClick(event)) {
                    return;
                }

                const list = await nextPopupList();

                if (list) {
                    watcher.onPopup(list);
                }
            },
            { capture: true },
        );
    },
});
