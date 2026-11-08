<script setup lang="ts">
const grouped = defineModel<boolean>({ required: true });
</script>

<template>
    <span
        class="inline-flex rounded-lg bg-panel p-0.5"
        role="group"
        :aria-label="$t('Display')"
    >
        <button
            v-for="option in [
                { value: false, label: $t('List') },
                { value: true, label: $t('By album') },
            ]"
            :key="String(option.value)"
            type="button"
            class="rounded-md px-[9px] py-0.5 text-xs font-semibold whitespace-nowrap transition-colors duration-150"
            :class="
                grouped === option.value
                    ? 'bg-raised text-ink shadow-[inset_0_0_0_1px_var(--color-line)]'
                    : 'text-dim hover:text-ink'
            "
            :aria-pressed="grouped === option.value"
            @click="grouped = option.value"
        >
            {{ option.label }}
        </button>
    </span>
</template>
