import { CARD_SELECTOR, targetFromCard } from './targets';
import type { Target } from './targets';

export type MenuWatcher = {
    /**
     * Click listener (capture phase) that remembers which card's ⋮ was
     * opened. Says whether a Sonder menu is expected.
     */
    onClick: (event: Event) => boolean;
    /** Called with the list of a menu that just opened. */
    onPopup: (list: Element) => void;
};

const MOUNTED = 'sonderGroup';

/**
 * Ties a YouTube menu popup to the card whose ⋮ button opened it, and asks
 * for the Sonder group to be mounted in it once. A card YouTube no longer
 * describes the way Sonder expects is reported, never thrown.
 */
export function createMenuWatcher(deps: {
    mount(list: Element, target: Target): void;
    report(name: string, attributes?: Record<string, string>): void;
    watchPage?: () => Target | null;
}): MenuWatcher {
    let pending: Target | null = null;
    let opened = 0;

    return {
        onClick(event) {
            const button = (event.target as Element | null)?.closest?.(
                'button',
            );
            const card = button?.closest(CARD_SELECTOR);
            pending = null;

            if (!button) {
                return false;
            }

            if (card) {
                pending = targetFromCard(card);
                opened++;

                if (pending === null) {
                    deps.report('menu.unknown-card', {
                        tag: card.tagName.toLowerCase(),
                    });
                }

                return pending !== null;
            }

            if (button.closest('ytd-watch-metadata')) {
                pending = deps.watchPage?.() ?? null;
                opened++;
            }

            return pending !== null;
        },
        onPopup(list) {
            if (pending === null) {
                return;
            }

            const html = list as HTMLElement;

            if (html.dataset[MOUNTED] === String(opened)) {
                return;
            }

            html.dataset[MOUNTED] = String(opened);
            list.querySelector('[data-sonder-group]')?.remove();
            deps.mount(list, pending);
        },
    };
}
