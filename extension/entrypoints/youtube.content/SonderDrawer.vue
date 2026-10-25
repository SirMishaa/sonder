<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { LookupBody, LookupResult } from '@/lib/api';
import { CONNECT_URL } from '@/lib/config';
import type { Result } from '@/lib/messages';
import type { Action } from '@/lib/youtube/actions';
import type { MixItem } from '@/lib/youtube/mix';
import type { Target } from '@/lib/youtube/targets';

const props = defineProps<{
    target: Target;
    deps: {
        lookup(body: LookupBody): Promise<Result<LookupResult>>;
        loadMix(listId: string, seedVideoId: string | null): Promise<MixItem[]>;
        run(action: Action, target: Target): Promise<void>;
        close(): void;
    };
}>();

type State =
    | { status: 'loading' }
    | { status: 'signed-out' }
    | { status: 'failed' }
    | { status: 'ready'; tracks: MixItem[]; result: LookupResult };

const state = ref<State>({ status: 'loading' });
const busy = ref(false);

const actions: { action: Action; label: string }[] = [
    { action: 'play_now', label: '▶ Lire' },
    { action: 'play_next', label: '⤵ Ensuite' },
    { action: 'enqueue', label: '＋ File' },
];

async function load(): Promise<void> {
    state.value = { status: 'loading' };

    try {
        const tracks: MixItem[] =
            props.target.kind === 'mix'
                ? await props.deps.loadMix(
                      props.target.listId,
                      props.target.seedVideoId,
                  )
                : [
                      {
                          videoId: props.target.videoId,
                          title: props.target.title,
                          artists: props.target.artists,
                          durationSeconds: 0,
                          thumbnailUrl: null,
                      },
                  ];
        const result = await props.deps.lookup({
            videoIds: [...new Set(tracks.map((track) => track.videoId))],
            listId: props.target.kind === 'mix' ? props.target.listId : null,
        });

        state.value = result.ok
            ? { status: 'ready', tracks, result: result.value }
            : {
                  status:
                      result.error === 'signed-out' ? 'signed-out' : 'failed',
              };
    } catch {
        state.value = { status: 'failed' };
    }
}

function knowledgeOf(videoId: string): App.Data.VideoKnowledgeData | undefined {
    return state.value.status === 'ready'
        ? state.value.result.videos[videoId]
        : undefined;
}

function statusOf(videoId: string): { label: string; tone: string } {
    const known = knowledgeOf(videoId);

    if (known?.inLibrary) {
        return { label: '✓ Biblio', tone: 'text-signal' };
    }

    return (known?.listenCount ?? 0) > 0
        ? { label: 'Déjà écouté', tone: 'text-dim' }
        : { label: 'Jamais joué', tone: 'text-amber' };
}

function since(timestamp: string | null): string {
    if (!timestamp) {
        return '';
    }

    const days = Math.round((Date.parse(timestamp) - Date.now()) / 86_400_000);

    return new Intl.RelativeTimeFormat('fr', { numeric: 'auto' }).format(
        days,
        'day',
    );
}

const video = computed(() =>
    props.target.kind === 'video'
        ? knowledgeOf(props.target.videoId)
        : undefined,
);

const inLibraryCount = computed(() =>
    state.value.status === 'ready'
        ? state.value.tracks.filter(
              (track) => knowledgeOf(track.videoId)?.inLibrary,
          ).length
        : 0,
);

async function run(action: Action): Promise<void> {
    busy.value = true;

    try {
        await props.deps.run(action, props.target);
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <aside
        class="fixed top-[72px] right-4 bottom-4 z-[2250] flex w-[360px] flex-col rounded-2xl bg-[linear-gradient(180deg,oklch(0.21_0.014_60/0.86),oklch(0.17_0.012_60/0.82))] p-4 font-sans text-paper shadow-[inset_0_1px_0_oklch(1_0_0/0.08),0_30px_60px_-24px_oklch(0_0_0/0.85)] backdrop-blur-[14px] backdrop-saturate-[1.4]"
        aria-label="Sonder"
        tabindex="-1"
        @keydown.esc="deps.close()"
    >
        <header class="flex items-center gap-2">
            <span class="relative size-4 rounded-full border-2 border-amber">
                <span class="absolute inset-0.5 rounded-full bg-amber" />
            </span>
            <span class="text-[15px] font-extrabold tracking-[-0.01em]"
                >sonder</span
            >
            <span
                class="ml-auto size-[7px] rounded-full"
                :class="
                    state.status === 'signed-out' ? 'bg-faint' : 'bg-signal'
                "
            />
            <button
                type="button"
                class="ml-2 grid size-7 place-items-center rounded-md text-dim transition-colors duration-150 hover:bg-raised hover:text-paper"
                aria-label="Fermer"
                @click="deps.close()"
            >
                ×
            </button>
        </header>

        <div class="mt-3">
            <p class="text-[12.5px] font-bold text-amber">
                {{ target.kind === 'mix' ? 'Mix' : 'Vidéo' }}
            </p>
            <p class="text-[14px] font-bold">{{ target.title }}</p>
        </div>

        <div
            v-if="state.status === 'loading'"
            data-test="skeleton"
            class="mt-4 space-y-2"
        >
            <div
                v-for="row in 5"
                :key="row"
                class="h-8 animate-pulse rounded-[7px] bg-hover motion-reduce:animate-none"
            />
        </div>

        <div
            v-else-if="state.status === 'signed-out'"
            class="mt-4 text-[13.5px] text-dim"
        >
            Connecte l'extension à Sonder pour voir ce que tu as déjà.
            <a
                :href="CONNECT_URL"
                target="_blank"
                class="mt-2 block font-bold text-amber hover:underline"
                >Connecter l'extension</a
            >
        </div>

        <div
            v-else-if="state.status === 'failed'"
            class="mt-4 text-[13.5px] text-dim"
        >
            Sonder n'a pas répondu.
            <button
                type="button"
                class="mt-2 block font-bold text-amber hover:underline"
                @click="load"
            >
                Réessayer
            </button>
        </div>

        <template v-else>
            <dl
                v-if="target.kind === 'video'"
                class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px] tabular-nums"
            >
                <dt class="font-medium text-faint">Statut</dt>
                <dd>
                    {{
                        video?.inLibrary
                            ? 'Dans ta bibliothèque'
                            : 'Pas dans ta bibliothèque'
                    }}
                </dd>
                <template v-if="video?.playlists.length">
                    <dt class="font-medium text-faint">Playlists</dt>
                    <dd>{{ video.playlists.join(', ') }}</dd>
                </template>
                <dt class="font-medium text-faint">Écoutes</dt>
                <dd>
                    {{ video?.listenCount ?? 0 }} écoutes<template
                        v-if="video?.lastListenedAt"
                    >
                        · {{ since(video.lastListenedAt) }}</template
                    >
                </dd>
            </dl>

            <template v-else>
                <p
                    class="mt-1 text-[12.5px] font-medium text-faint tabular-nums"
                >
                    {{ state.tracks.length }} titres · {{ inLibraryCount }} dans
                    ta bibliothèque
                    <template v-if="state.result.list?.sentBefore">
                        · Déjà envoyé</template
                    >
                </p>
                <ol class="-mx-1 mt-2 flex-1 overflow-y-auto">
                    <li
                        v-for="(track, position) in state.tracks"
                        :key="position"
                        class="grid grid-cols-[1fr_auto] items-center gap-2 rounded-[7px] px-1 py-1.5 text-[13px] hover:bg-hover"
                    >
                        <span class="min-w-0">
                            <span class="block truncate">{{
                                track.title
                            }}</span>
                            <span class="block truncate text-[12px] text-dim">{{
                                track.artists
                            }}</span>
                        </span>
                        <span
                            data-test="track-status"
                            class="text-[11.5px] font-semibold"
                            :class="statusOf(track.videoId).tone"
                            >{{ statusOf(track.videoId).label }}</span
                        >
                    </li>
                </ol>
            </template>
        </template>

        <footer class="mt-auto flex gap-1.5 border-t border-line pt-3">
            <button
                v-for="(entry, index) in actions"
                :key="entry.action"
                type="button"
                :data-test="`action-${entry.action}`"
                :disabled="busy"
                class="rounded-[9px] px-3 py-1.5 text-[13px] font-bold transition-[filter,transform] duration-150 active:scale-[0.97] disabled:opacity-60"
                :class="
                    index === 0
                        ? 'bg-[linear-gradient(135deg,var(--color-amber-light),var(--color-amber-deep))] text-amber-ink hover:brightness-105'
                        : 'border border-line bg-panel/80 text-paper hover:border-dim'
                "
                @click="run(entry.action)"
            >
                {{ entry.label }}
            </button>
        </footer>
    </aside>
</template>
