import { onBeforeUnmount, onMounted } from 'vue';

type Handler = (event: KeyboardEvent) => void;

const handlers = new Map<string, Set<Handler>>();
let listening = false;

function isTyping(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

function onKeydown(event: KeyboardEvent): void {
    const combo =
        (event.metaKey || event.ctrlKey ? 'mod+' : '') +
        event.key.toLowerCase();

    if (!combo.startsWith('mod+') && (isTyping(event.target) || event.altKey)) {
        return;
    }

    const registered = handlers.get(combo);

    if (!registered?.size) {
        return;
    }

    event.preventDefault();
    [...registered].at(-1)?.(event);
}

/**
 * Registers single-key shortcuts for as long as the calling component is
 * mounted. Keys are lower-case (`'q'`, `' '`, `'['`); `'mod+k'` means ⌘K or
 * Ctrl+K. When several components register the same key, the most recent wins.
 */
export function useShortcuts(bindings: Record<string, Handler>): void {
    onMounted(() => {
        if (!listening) {
            window.addEventListener('keydown', onKeydown);
            listening = true;
        }

        for (const [key, handler] of Object.entries(bindings)) {
            if (!handlers.has(key)) {
                handlers.set(key, new Set());
            }

            handlers.get(key)?.add(handler);
        }
    });

    onBeforeUnmount(() => {
        for (const [key, handler] of Object.entries(bindings)) {
            handlers.get(key)?.delete(handler);
        }
    });
}
