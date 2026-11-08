<script setup lang="ts">
import { LibraryBig } from 'lucide-vue-next';
</script>

<template>
    <LibraryBig
        class="size-3.5 shrink-0 text-faint"
        :title="$t('In your library')"
        :aria-label="$t('In your library')"
        role="img"
    />
</template>
