import { reactive } from 'vue';

export type Toast = {
    id: number;
    message: string;
    emphasis?: string;
};

const toasts = reactive<Toast[]>([]);
let nextId = 1;

const DURATION_MS = 2600;

/**
 * Short, non-blocking confirmations. `message` is already translated and may
 * contain a `:title` placeholder, rendered in amber with `emphasis`. One at a
 * time; a new toast replaces the previous one so feedback never piles up.
 */
export function useToast() {
    function toast(message: string, emphasis?: string): void {
        const id = nextId++;

        toasts.splice(0, toasts.length, { id, message, emphasis });
        setTimeout(() => {
            const index = toasts.findIndex((item) => item.id === id);

            if (index !== -1) {
                toasts.splice(index, 1);
            }
        }, DURATION_MS);
    }

    return { toasts, toast };
}
