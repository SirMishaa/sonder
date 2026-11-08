import { computed, ref, watch } from 'vue';
import type { ComputedRef, Ref } from 'vue';

/**
 * The selected row of a list whose rows change under it, such as palette
 * results arriving after the local ones. The selection follows the row's id,
 * so rows inserted before it never make Enter run something else. A change
 * of `reset` (the query) selects the first row again.
 */
export function useListSelection(
    items: Ref<{ id: string }[]>,
    reset: Ref<unknown>,
): {
    selected: ComputedRef<number>;
    move: (delta: number) => void;
    select: (index: number) => void;
} {
    const selectedId = ref<string | null>(null);

    watch(reset, () => {
        selectedId.value = items.value[0]?.id ?? null;
    });

    watch(
        items,
        (list) => {
            if (!list.some(({ id }) => id === selectedId.value)) {
                selectedId.value = list[0]?.id ?? null;
            }
        },
        { immediate: true },
    );

    const selected = computed(() =>
        Math.max(
            0,
            items.value.findIndex(({ id }) => id === selectedId.value),
        ),
    );

    function select(index: number): void {
        selectedId.value = items.value[index]?.id ?? null;
    }

    function move(delta: number): void {
        const count = items.value.length;

        if (count) {
            select((selected.value + delta + count) % count);
        }
    }

    return { selected, move, select };
}
