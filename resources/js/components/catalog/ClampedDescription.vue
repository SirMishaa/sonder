<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { computed, ref, useTemplateRef, useId } from 'vue';

/**
 * A catalog description clamped to four lines, with "Read more" only when
 * the clamp hides something, and its source after it. Key it by the thing
 * it describes so it closes again on another page.
 */
type Props = {
    text: string;
    /** Where the text comes from, e.g. "YouTube Music". */
    source?: string | null;
};

const props = defineProps<Props>();

const id = useId();
const expanded = ref(false);
const overflowing = ref(false);

/** Line breaks only once expanded; clamped, the text runs on. */
const shownText = computed(() =>
    expanded.value ? props.text.trim() : props.text.replace(/\s+/g, ' ').trim(),
);

const paragraph = useTemplateRef<HTMLElement>('paragraph');

useResizeObserver(paragraph, () => {
    const element = paragraph.value;

    if (element && !expanded.value) {
        overflowing.value = element.scrollHeight > element.clientHeight + 1;
    }
});
</script>

<template>
    <div>
        <p
            :id="id"
            ref="paragraph"
            class="desc"
            :class="{ open: expanded }"
            v-text="shownText"
        />
        <div
            v-if="overflowing || source"
            class="mt-1.5 flex items-baseline gap-2.5"
        >
            <button
                v-if="overflowing"
                type="button"
                class="text-[12.5px] font-semibold text-paper transition-colors duration-150 hover:text-amber"
                :aria-controls="id"
                :aria-expanded="expanded"
                @click="expanded = !expanded"
            >
                {{ expanded ? $t('Show less') : $t('Read more') }}
            </button>
            <span v-if="source" class="text-xs text-faint">{{ source }}</span>
        </div>
    </div>
</template>

<style scoped>
.desc {
    display: -webkit-box;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
    color: var(--color-dim);
    font-size: 13px;
    line-height: 1.55;
}

.desc.open {
    display: block;
    white-space: pre-line;
}
</style>
