<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import EnrichmentListener from '@/components/shell/EnrichmentListener.vue';
import EnrichmentPopover from '@/components/shell/EnrichmentPopover.vue';

/**
 * The library's metadata enrichment, read once at app load and kept current
 * by the websocket.
 */
const page = usePage();

const summary = ref<App.Data.LibraryEnrichmentData | null>(null);

watch(
    () => page.props.libraryEnrichment ?? null,
    (initial) => {
        summary.value = initial ? { ...initial } : null;
    },
    { immediate: true },
);
</script>

<template>
    <div v-if="summary && page.props.auth.user" class="contents">
        <EnrichmentListener
            :user-id="page.props.auth.user.id"
            @update="summary = $event"
        />
        <EnrichmentPopover :summary="summary" />
    </div>
</template>
