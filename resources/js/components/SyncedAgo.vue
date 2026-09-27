<script setup lang="ts">
import { useTimeAgo } from '@vueuse/core';
import { History } from 'lucide-vue-next';
import { computed } from 'vue';

type Props = {
    at: string;
    label?: string;
};

const props = withDefaults(defineProps<Props>(), {
    label: 'Synced',
});

const timeAgo = useTimeAgo(() => props.at);
const exactDate = computed(() => new Date(props.at).toLocaleString());
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
        :title="exactDate"
    >
        <History class="size-3.5" />
        {{ label }} {{ timeAgo }}
    </span>
</template>
