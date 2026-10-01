import { reactive } from 'vue';
import type { StyleValue } from 'vue';

/**
 * Pointer-driven reordering of a vertical list of equal-height rows. The
 * grabbed row follows the pointer while the rows it passes slide out of its
 * way; on release `commit(from, to)` reorders the data. Rows carry
 * `data-sort-row`; the grip calls `grab` on pointerdown.
 */
export function useDragSort(commit: (from: number, to: number) => void) {
    const drag = reactive({ from: -1, to: -1, offset: 0, size: 0 });

    function grab(event: PointerEvent, index: number): void {
        if (event.button !== 0) {
            return;
        }

        const handle = event.currentTarget as HTMLElement;
        const row = handle.closest<HTMLElement>('[data-sort-row]');
        const count =
            row?.parentElement?.querySelectorAll('[data-sort-row]').length ?? 0;

        if (!row || count < 2) {
            return;
        }

        event.preventDefault();
        handle.setPointerCapture(event.pointerId);

        const startY = event.clientY;
        drag.from = index;
        drag.to = index;
        drag.offset = 0;
        drag.size = row.offsetHeight;

        const move = (moved: PointerEvent): void => {
            drag.offset = moved.clientY - startY;
            drag.to = Math.max(
                0,
                Math.min(
                    count - 1,
                    drag.from + Math.round(drag.offset / drag.size),
                ),
            );
        };

        const release = (): void => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', release);
            handle.removeEventListener('pointercancel', release);

            const { from, to } = drag;
            drag.from = -1;
            drag.offset = 0;

            // Same tick as the reset: the list's move transition starts from
            // where the rows are drawn now, so the grabbed one glides home.
            if (from !== to) {
                commit(from, to);
            }
        };

        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', release);
        handle.addEventListener('pointercancel', release);
    }

    function styleFor(index: number): StyleValue | undefined {
        if (drag.from < 0) {
            return undefined;
        }

        if (index === drag.from) {
            return { transform: `translateY(${drag.offset}px)` };
        }

        if (drag.from < index && index <= drag.to) {
            return { transform: `translateY(${-drag.size}px)` };
        }

        if (drag.to <= index && index < drag.from) {
            return { transform: `translateY(${drag.size}px)` };
        }

        return undefined;
    }

    return {
        grab,
        styleFor,
        isSorting: () => drag.from >= 0,
        isGrabbed: (index: number) => drag.from === index,
    };
}
