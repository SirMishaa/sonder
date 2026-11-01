<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Search, Sparkles, SquareLibrary } from 'lucide-vue-next';
import { computed } from 'vue';
import DiscoverController from '@/actions/App/Http/Controllers/DiscoverController';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import SyncStatus from '@/components/shell/SyncStatus.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useInitials } from '@/composables/useInitials';
import { usePalette } from '@/composables/usePalette';

/**
 * The phone's navigation, in thumb reach: Discover, Library (which holds
 * the playlists), search and the account menu with the sync state.
 */
const page = usePage();
const palette = usePalette();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { getInitials } = useInitials();

const user = computed(() => page.props.auth.user);
const library = computed(() => page.props.library);

const isOnLibrary = computed(
    () =>
        isCurrentUrl(PlaylistController.index()) ||
        isCurrentOrParentUrl(PlaylistController.index()),
);
</script>

<template>
    <nav
        class="material-glass tabbar grid grid-cols-4 rounded-none border-x-0 border-b-0"
        :aria-label="$t('Main')"
    >
        <Link
            :href="DiscoverController.index()"
            class="tab"
            :data-active="isCurrentUrl(DiscoverController.index())"
        >
            <Sparkles class="size-[22px]" />
            <span>{{ $t('Discover') }}</span>
        </Link>
        <Link
            :href="PlaylistController.index()"
            class="tab"
            :data-active="isOnLibrary"
        >
            <SquareLibrary class="size-[22px]" />
            <span>{{ $t('Library') }}</span>
        </Link>
        <button type="button" class="tab" @click="palette.show">
            <Search class="size-[22px]" />
            <span>{{ $t('Search') }}</span>
        </button>
        <DropdownMenu>
            <DropdownMenuTrigger class="tab">
                <span
                    class="grid size-[24px] place-items-center rounded-full bg-raised text-[11px] font-bold text-paper"
                >
                    {{ getInitials(user.name) }}
                </span>
                <span>{{ $t('Account') }}</span>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                class="min-w-60 rounded-lg"
                side="top"
                align="end"
                :side-offset="10"
            >
                <template v-if="library">
                    <DropdownMenuLabel class="font-normal">
                        <span class="block text-[13.5px] font-semibold">{{
                            library.accountName ?? user.name
                        }}</span>
                        <SyncStatus />
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                </template>
                <UserMenuContent :user="user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </nav>
</template>

<style scoped>
.tabbar {
    padding-bottom: env(safe-area-inset-bottom);
    box-shadow: inset 0 1px 0 oklch(1 0 0 / 0.08);
}

.tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    min-height: 54px;
    color: var(--color-faint);
    font-size: 11px;
    font-weight: 600;
    transition: color 140ms;
    -webkit-tap-highlight-color: transparent;
}

.tab[data-active='true'] {
    color: var(--color-paper);
}

.tab[data-active='true'] svg {
    color: var(--color-amber);
}
</style>
