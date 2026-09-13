<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type Props = {
    sync: App.Data.YouTubeMusicSyncData;
};

defineProps<Props>();
</script>

<template>
    <Head title="Syncing YouTube Music" />

    <AppLayout>
        <div class="mx-auto w-full max-w-3xl space-y-8 p-4">
            <Heading title="Syncing YouTube Music" description="Sonder is importing your playlists." />
        </div>
    </AppLayout>
</template>
</content>
