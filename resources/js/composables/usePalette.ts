import { ref } from 'vue';

const open = ref(false);

/** Open state of the ⌘K command palette, shared by the sidebar and the shell. */
export function usePalette() {
    return {
        open,
        show: () => {
            open.value = true;
        },
        hide: () => {
            open.value = false;
        },
        toggle: () => {
            open.value = !open.value;
        },
    };
}
