<script setup lang="ts">
import { computed } from 'vue';

type Props = {
    genres: string[];
    /** Show at most this many tags; all of them when omitted. */
    limit?: number;
};

const props = defineProps<Props>();

const visible = computed(() => props.genres.slice(0, props.limit));
</script>

<template>
    <template v-if="visible.length">
        <span v-for="genre in visible" :key="genre" class="genre">{{
            genre
        }}</span>
    </template>
</template>

<style scoped>
.genre {
    flex-shrink: 0;
    padding: 0 8px;
    border: 1px solid var(--color-line);
    border-radius: 999px;
    font-size: 11.5px;
    line-height: 19px;
    white-space: nowrap;
    color: var(--color-dim);
}
</style>
