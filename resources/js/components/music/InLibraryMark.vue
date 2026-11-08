<script setup lang="ts">
import { LibraryBig } from 'lucide-vue-next';
</script>

<template>
    <span
        class="inline-flex shrink-0 text-faint"
        role="img"
        :title="$t('In your library')"
        :aria-label="$t('In your library')"
    >
        <LibraryBig class="size-3.5" aria-hidden="true" />
    </span>
</template>
