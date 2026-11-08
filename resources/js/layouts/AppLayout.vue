<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import AppSidebar from '@/components/shell/AppSidebar.vue';
import CommandPalette from '@/components/shell/CommandPalette.vue';
import MiniPlayer from '@/components/shell/MiniPlayer.vue';
import MobileTabBar from '@/components/shell/MobileTabBar.vue';
import NowPlayingSheet from '@/components/shell/NowPlayingSheet.vue';
import PlayerBar from '@/components/shell/PlayerBar.vue';
import PlayerCommandListener from '@/components/shell/PlayerCommandListener.vue';
import PlayerHost from '@/components/shell/PlayerHost.vue';
import QueuePanel from '@/components/shell/QueuePanel.vue';
import QueueSheet from '@/components/shell/QueueSheet.vue';
import ToastHost from '@/components/shell/ToastHost.vue';
import YouTubeMusicCookieAlert from '@/components/YouTubeMusicCookieAlert.vue';
import { useMobileSheets } from '@/composables/useMobileSheets';
import { usePalette } from '@/composables/usePalette';
import { usePlayer } from '@/composables/usePlayer';
import { useShortcuts } from '@/composables/useShortcuts';

/**
 * The Sonder shell: sidebar, content, queue and the floating player. Under
 * the lg breakpoint the sidebar gives way to a tab bar, the player to a mini
 * player that opens full screen, and the queue panel to a sheet.
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
const { closeAll } = useMobileSheets();

/** Re-enter the view on a new page, not on a query change (typing a search). */
const viewKey = computed(() => page.url.split('?')[0]);

let stopClosingSheets: (() => void) | null = null;

onMounted(() => {
    stopClosingSheets = router.on('navigate', closeAll);
});

onBeforeUnmount(() => stopClosingSheets?.());

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
        <AppSidebar class="max-lg:hidden" />

        <div class="relative min-w-0 flex-1">
            <main
                class="h-full overflow-y-auto pb-32 max-lg:pb-[calc(152px+env(safe-area-inset-bottom))]"
                scroll-region
            >
                <YouTubeMusicCookieAlert />
                <div :key="viewKey" class="view-enter">
                    <slot />
                </div>
            </main>
            <ToastHost />
            <PlayerBar
                class="absolute inset-x-4 bottom-3.5 z-30 max-lg:hidden"
            />
        </div>

        <QueuePanel class="max-lg:hidden" />

        <div class="fixed inset-x-0 bottom-0 z-30 lg:hidden">
            <MiniPlayer class="mx-2 mb-2" />
            <MobileTabBar />
        </div>
        <NowPlayingSheet class="lg:hidden" />
        <QueueSheet class="lg:hidden" />

        <CommandPalette />
        <PlayerHost :saved-queue="page.props.playerQueue" />
        <PlayerCommandListener
            v-if="page.props.auth.user"
            :user-id="String(page.props.auth.user.id)"
        />
    </div>
</template>
