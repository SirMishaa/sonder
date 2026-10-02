<script setup lang="ts">
import { useEcho } from '@laravel/echo-vue';

type Props = {
    userId: string | number;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    update: [summary: App.Data.LibraryEnrichmentData];
}>();

useEcho<App.Data.LibraryEnrichmentData>(
    `library-enrichment.${props.userId}`,
    '.enrichment.updated',
    (payload) => {
        emit('update', payload);
    },
);
</script>

<template>
    <span hidden />
</template>
