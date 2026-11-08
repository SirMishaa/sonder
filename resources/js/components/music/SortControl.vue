<script setup lang="ts">
import { ArrowDownUp } from 'lucide-vue-next';
import { CATALOG_SORTS } from '@/composables/useCatalogSort';
import type { CatalogSort } from '@/composables/useCatalogSort';

const sort = defineModel<CatalogSort>({ required: true });

const labels: Record<CatalogSort, string> = {
    recent: 'Newest',
    oldest: 'Oldest',
    popular: 'Popular',
    az: 'A → Z',
};
</script>

<template>
    <label
        class="relative inline-flex cursor-pointer items-center gap-1.5 rounded-[7px] border border-line py-0.5 pr-2 pl-[7px] text-xs font-semibold text-dim transition-colors duration-150 focus-within:border-[oklch(0.38_0.014_60)] focus-within:text-paper focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-amber hover:border-[oklch(0.38_0.014_60)] hover:text-paper"
        :title="$t('Sort')"
    >
        <ArrowDownUp class="size-3.5" aria-hidden="true" />
        <select
            v-model="sort"
            :aria-label="$t('Sort')"
            class="sort-select cursor-pointer appearance-none border-0 bg-transparent pr-3 text-inherit outline-none"
        >
            <option
                v-for="option in CATALOG_SORTS"
                :key="option"
                :value="option"
            >
                {{ $t(labels[option]) }}
            </option>
        </select>
    </label>
</template>

<style scoped>
.sort-select {
    font: inherit;
    background-image:
        linear-gradient(45deg, transparent 50%, currentColor 50%),
        linear-gradient(135deg, currentColor 50%, transparent 50%);
    background-size: 4px 4px;
    background-position:
        right 3px center,
        right 0 center;
    background-repeat: no-repeat;
}

.sort-select option {
    background: var(--color-panel);
    color: var(--color-paper);
}
</style>
