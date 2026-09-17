<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCircle2, XCircle } from 'lucide-vue-next';
import { onMounted, onUnmounted, reactive, ref } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import YouTubeMusicConnectionController from '@/actions/App/Http/Controllers/YouTubeMusicConnectionController';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { subscribeToPrivateChannel } from '@/lib/mercure';
import type { BreadcrumbItem } from '@/types';

type Props = {
    sync: App.Data.YouTubeMusicSyncData;
};

const props = defineProps<Props>();

const state = reactive({ ...props.sync });
const completedTitles = ref<string[]>([]);

let unsubscribe: (() => void) | null = null;

function goToPlaylists() {
    router.visit(PlaylistController.index().url);
}

onMounted(() => {
    if (state.status === 'completed') {
        goToPlaylists();
        return;
    }

    if (state.status === 'failed') {
        return;
    }

    unsubscribe = subscribeToPrivateChannel(
        `youtube-music-sync.${props.sync.id}`,
        (message) => {
            if (message.event !== 'sync.updated') {
                return;
            }

            const payload = message.payload as App.Data.YouTubeMusicSyncData;

            if (
                payload.syncedPlaylists > state.syncedPlaylists &&
                state.currentPlaylistTitle
            ) {
                completedTitles.value.push(state.currentPlaylistTitle);
            }

            Object.assign(state, payload);

            if (payload.status === 'completed') {
                setTimeout(goToPlaylists, 600);
            }
        },
    );
});

onUnmounted(() => unsubscribe?.());

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Syncing your library', href: '#' },
];

const progress = () =>
    state.totalPlaylists && state.totalPlaylists > 0
        ? Math.round((state.syncedPlaylists / state.totalPlaylists) * 100)
        : 0;
</script>

<template>
    <Head title="Syncing your library" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex w-full max-w-xl flex-col items-center gap-8 p-4 py-16 text-center"
        >
            <template v-if="state.status === 'failed'">
                <XCircle class="size-10 text-destructive" />
                <Heading
                    title="The sync failed"
                    :description="state.errorMessage ?? undefined"
                />
                <div class="flex items-center gap-3">
                    <Button as-child>
                        <Link :href="YouTubeMusicConnectionController.create()"
                            >Reconnect</Link
                        >
                    </Button>
                    <Button as-child variant="outline">
                        <Link :href="PlaylistController.index()">Retry</Link>
                    </Button>
                </div>
            </template>

            <template v-else>
                <Heading
                    title="Syncing your library"
                    description="This only takes a moment — we're reading your playlists straight from YouTube Music."
                />

                <div class="w-full space-y-2">
                    <div
                        class="h-2 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-all duration-500 ease-out"
                            :style="{ width: `${progress()}%` }"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ state.syncedPlaylists }} /
                        {{ state.totalPlaylists ?? '…' }} playlists
                    </p>
                </div>

                <Transition
                    enter-active-class="transition duration-300 ease-out"
                    enter-from-class="opacity-0 -translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                    mode="out-in"
                >
                    <p
                        :key="state.currentPlaylistTitle ?? 'waiting'"
                        class="text-sm font-medium"
                    >
                        {{
                            state.currentPlaylistTitle
                                ? `Syncing “${state.currentPlaylistTitle}”…`
                                : 'Fetching your playlists…'
                        }}
                    </p>
                </Transition>

                <ul
                    v-if="completedTitles.length > 0"
                    class="w-full space-y-1.5 text-left text-sm text-muted-foreground"
                >
                    <TransitionGroup
                        enter-active-class="transition duration-300 ease-out"
                        enter-from-class="opacity-0 -translate-x-1"
                        enter-to-class="opacity-100 translate-x-0"
                    >
                        <li
                            v-for="title in completedTitles"
                            :key="title"
                            class="flex items-center gap-2"
                        >
                            <CheckCircle2
                                class="size-4 shrink-0 text-emerald-600"
                            />
                            <span class="truncate">{{ title }}</span>
                        </li>
                    </TransitionGroup>
                </ul>
            </template>
        </div>
    </AppLayout>
</template>
