import { ref } from 'vue';

const CLOSE_DISTANCE = 110;
const CLOSE_VELOCITY = 0.6;
const LEAVE_MS = 320;

/**
 * Pulling a full-screen sheet down to dismiss it. The handle area calls
 * `grab` on pointerdown; `offset` follows the finger (downwards only) and
 * `close` runs when the pull is long or fast enough, otherwise the sheet
 * springs back. Bind `offset` to a `--drag` CSS variable, not to `transform`,
 * so the leave transition still applies.
 */
export function useSheetDrag(close: () => void) {
    const offset = ref(0);
    const dragging = ref(false);

    function grab(event: PointerEvent): void {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        if ((event.target as HTMLElement).closest('button, a, input')) {
            return;
        }

        const handle = event.currentTarget as HTMLElement;
        handle.setPointerCapture(event.pointerId);

        const startY = event.clientY;
        let lastY = startY;
        let lastAt = event.timeStamp;
        let velocity = 0;
        dragging.value = true;

        const move = (moved: PointerEvent): void => {
            const elapsed = Math.max(1, moved.timeStamp - lastAt);
            velocity = (moved.clientY - lastY) / elapsed;
            lastY = moved.clientY;
            lastAt = moved.timeStamp;
            offset.value = Math.max(0, moved.clientY - startY);
        };

        const release = (): void => {
            handle.removeEventListener('pointermove', move);
            handle.removeEventListener('pointerup', release);
            handle.removeEventListener('pointercancel', release);
            dragging.value = false;

            const shouldClose =
                offset.value > CLOSE_DISTANCE ||
                (offset.value > 24 && velocity > CLOSE_VELOCITY);

            if (!shouldClose) {
                offset.value = 0;

                return;
            }

            // The sheet leaves from where the finger let go; the offset is
            // cleared once it is gone, ready for the next opening.
            close();
            setTimeout(() => {
                offset.value = 0;
            }, LEAVE_MS);
        };

        handle.addEventListener('pointermove', move);
        handle.addEventListener('pointerup', release);
        handle.addEventListener('pointercancel', release);
    }

    return { offset, dragging, grab };
}
