<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import AppSidebar from '@/components/shell/AppSidebar.vue';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import PlayerBar from '@/components/shell/PlayerBar.vue';
import PlayerHost from '@/components/shell/PlayerHost.vue';
import QueuePanel from '@/components/shell/QueuePanel.vue';
import ToastHost from '@/components/shell/ToastHost.vue';
import YouTubeMusicCookieAlert from '@/components/YouTubeMusicCookieAlert.vue';
import { usePalette } from '@/composables/usePalette';
import { usePlayer } from '@/composables/usePlayer';
import { useShortcuts } from '@/composables/useShortcuts';

/**
 * The Sonder shell: sidebar, content, queue and the floating player.
 *
 * Pages use it as a persistent layout (`defineOptions({ layout: AppLayout })`)
 * so the sidebar, player and queue survive navigation. The `breadcrumbs` prop
 * is accepted for pages that still wrap themselves in it and is not rendered.
 */
defineProps<{
    breadcrumbs?: unknown[];
}>();

const page = usePage();
const player = usePlayer();
const palette = usePalette();

useShortcuts({
    'mod+k': palette.toggle,
    ' ': player.toggle,
    n: player.next,
    p: player.previous,
    q: player.toggleQueue,
    g: () => router.visit(DiscoverController.index()),
    l: () => router.visit(PlaylistController.index()),
});
</script>

<template>
    <div class="flex h-full">
        <AppSidebar />

        <div class="relative min-w-0 flex-1">
            <main class="h-full overflow-y-auto pb-32" scroll-region>
                <YouTubeMusicCookieAlert />
                <div :key="page.url" class="view-enter">
                    <slot />
                </div>
            </main>
            <ToastHost />
            <PlayerBar class="absolute inset-x-4 bottom-3.5 z-30" />
        </div>

        <QueuePanel />
        <CommandPalette />
        <PlayerHost />
    </div>
</template>
