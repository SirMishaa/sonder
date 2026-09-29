import { reactive } from 'vue';

export type Toast = {
    id: number;
    text: string;
    emphasis?: string;
    after?: string;
};

const toasts = reactive<Toast[]>([]);
let nextId = 1;

const DURATION_MS = 2600;

/**
 * Short, non-blocking confirmations ("Added X to Y"). One at a time; a new
 * toast replaces the previous one so feedback never piles up.
 */
export function useToast() {
    function toast(text: string, emphasis?: string, after?: string): void {
        const id = nextId++;

        toasts.splice(0, toasts.length, { id, text, emphasis, after });
        setTimeout(() => {
            const index = toasts.findIndex((item) => item.id === id);

            if (index !== -1) {
                toasts.splice(index, 1);
            }
        }, DURATION_MS);
    }

    return { toasts, toast };
}
