<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import { computed, reactive, watch } from 'vue';
import SyncListener from '@/components/shell/SyncListener.vue';

const page = usePage();
const library = computed(() => page.props.library);

const live = reactive<{ sync: App.Data.YouTubeMusicSyncData | null }>({
    sync: null,
});

watch(
    () => library.value?.activeSync ?? null,
    (sync) => {
        live.sync = sync ? { ...sync } : null;
    },
    { immediate: true },
);

const checkedAgo = useTimeAgo(() => library.value?.lastCheckedAt ?? new Date());

const syncing = computed(
    () =>
        live.sync !== null && ['pending', 'syncing'].includes(live.sync.status),
);

function onUpdate(sync: App.Data.YouTubeMusicSyncData): void {
    live.sync = sync;

    if (sync.status === 'completed' || sync.status === 'failed') {
        router.reload({ only: ['library'] });
    }
}
</script>

<template>
    <div class="flex items-center gap-1.5 text-xs font-medium text-faint">
        <SyncListener
            v-if="syncing && live.sync"
            :sync-id="live.sync.id"
            @update="onUpdate"
        />
        <span
            class="size-[7px] shrink-0 rounded-full"
            :class="syncing ? 'animate-pulse bg-amber' : 'bg-signal'"
        />
        <span v-if="syncing" class="truncate">
            Syncing {{ live.sync?.syncedPlaylists ?? 0 }}
            <template v-if="live.sync?.totalPlaylists">
                of {{ live.sync.totalPlaylists }}</template
            >…
        </span>
        <span v-else-if="library?.lastCheckedAt" class="truncate"
            >Synced {{ checkedAgo }}</span
        >
        <span v-else class="truncate">Not synced yet</span>
    </div>
</template>
