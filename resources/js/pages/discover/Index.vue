<script setup lang="ts">
import { Deferred, Head, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Play } from 'lucide-vue-next';
import { computed } from 'vue';
import PlaylistController from '@/actions/App/Http/Controllers/PlaylistController';
import Artwork from '@/components/music/Artwork.vue';
import TrackMiniRow from '@/components/music/TrackMiniRow.vue';
import { usePlayer } from '@/composables/usePlayer';
import { useQueuePick } from '@/composables/useQueuePick';
import { useTrackTap } from '@/composables/useTrackTap';
import AppLayout from '@/layouts/AppLayout.vue';
import { languageTag, formatNumber } from '@/lib/i18n';

defineOptions({ layout: AppLayout });

type Props = {
    stats?: App.Data.LibraryStatsData;
    freshFinds?: App.Data.SampledTrackData[];
};

defineProps<Props>();

const page = usePage();
const player = usePlayer();
const freshFindsSource = () => ({
    playlistId: null,
    title: trans('Fresh finds'),
});
const { tap, preventSelection } = useTrackTap(freshFindsSource);
const { pick } = useQueuePick();

const library = computed(() => page.props.library);

const PARTS_OF_DAY = [
    [5, ':day morning'],
    [12, ':day afternoon'],
    [18, ':day evening'],
    [23, ':day night'],
] as const;

/** "Tuesday evening" / "Mardi soir", from the user's own clock. */
const moment = computed(() => {
    const now = Temporal.Now.plainDateTimeISO();
    const key =
        [...PARTS_OF_DAY].reverse().find(([from]) => now.hour >= from)?.[1] ??
        ':day night';
    const sentence = trans(key, {
        day: now.toLocaleString(languageTag(), { weekday: 'long' }),
    });

    return (
        sentence.charAt(0).toLocaleUpperCase(languageTag()) + sentence.slice(1)
    );
});

const trackTotal = computed(() =>
    (library.value?.playlists ?? []).reduce(
        (total, playlist) => total + (playlist.trackCount ?? 0),
        0,
    ),
);

const JUMP_BACK_IN = 8;

const recentPlaylists = computed(() =>
    [...(library.value?.playlists ?? [])]
        .filter((playlist) => !playlist.isRemoved)
        .sort((a, b) =>
            (b.lastChangedAt ?? '').localeCompare(a.lastChangedAt ?? ''),
        )
        .slice(0, JUMP_BACK_IN),
);

function playFind(find: App.Data.SampledTrackData): void {
    player.playNext(find.track, freshFindsSource(), 'suggestion');
}
</script>

<template>
    <Head :title="$t('Discover')" />

    <div
        class="grid max-w-[1480px] gap-10 px-8 pt-8 xl:grid-cols-[minmax(0,1fr)_320px]"
        :class="{ 'xl:grid-cols-1': player.state.queueOpen }"
    >
        <section class="min-w-0">
            <p class="reveal text-[13px] font-bold text-amber">
                {{
                    freshFinds?.length
                        ? $tChoice(
                              ':moment, :count new for you|:moment, :count new for you',
                              freshFinds.length,
                              { moment },
                          )
                        : moment
                }}
            </p>
            <h1
                class="reveal mt-1.5 mb-1 text-[30px] leading-[1.1] font-extrabold tracking-[-0.03em]"
                style="--i: 1"
            >
                {{ $t('Fresh records, picked from what you already love.') }}
            </h1>
            <p class="reveal text-dim" style="--i: 2">
                {{
                    $t('Built from :playlists playlists and :tracks tracks.', {
                        playlists: formatNumber(library?.playlists.length ?? 0),
                        tracks: formatNumber(trackTotal),
                    })
                }}
            </p>

            <Deferred data="freshFinds">
                <template #fallback>
                    <div
                        class="mt-6 mb-2 grid grid-cols-3 gap-4 lg:grid-cols-6"
                    >
                        <div v-for="card in 6" :key="card">
                            <div class="skeleton aspect-square" />
                            <div class="skeleton mt-2.5 h-3 w-4/5" />
                            <div class="skeleton mt-1.5 h-3 w-1/2" />
                        </div>
                    </div>
                </template>

                <div
                    v-if="freshFinds?.length"
                    class="mt-6 mb-2 grid grid-cols-3 gap-4 lg:grid-cols-6"
                >
                    <button
                        v-for="(find, index) in freshFinds"
                        :key="find.track.videoId ?? find.track.title"
                        type="button"
                        class="card reveal group min-w-0 text-left"
                        :style="{ '--i': index + 2 }"
                        @mousedown="preventSelection"
                        @click="
                            tap($event, find.track, index, () => playFind(find))
                        "
                    >
                        <div class="relative">
                            <Artwork
                                data-track-artwork
                                :src="find.track.thumbnailUrl"
                                :alt="find.track.title"
                                class="aspect-square rounded-md shadow-card"
                            />
                            <span
                                class="play-hint accent-fill"
                                aria-hidden="true"
                                ><Play class="size-4 fill-current"
                            /></span>
                        </div>
                        <p class="mt-2.5 truncate text-sm font-semibold">
                            {{ find.track.title }}
                        </p>
                        <p class="truncate text-[13px] text-dim">
                            {{ find.track.artists }}
                        </p>
                        <p class="mt-1 truncate text-[12.5px] text-faint">
                            {{ $t('because of') }}
                            <em class="font-semibold text-amber not-italic">{{
                                find.playlistTitle
                            }}</em>
                        </p>
                    </button>
                </div>
            </Deferred>
            <p
                v-if="freshFinds === undefined || freshFinds.length"
                class="mb-10 text-xs text-faint italic"
            >
                {{
                    $t(
                        'Preview: sampled from your own library until discovery ships.',
                    )
                }}
            </p>
            <div v-else class="mb-10" />

            <div
                class="reveal mb-3.5 flex items-baseline gap-2.5"
                style="--i: 8"
            >
                <h2 class="text-sm font-bold">{{ $t('Jump back in') }}</h2>
                <span class="text-[12.5px] font-medium text-faint">{{
                    $t('recently changed')
                }}</span>
                <Link
                    :href="PlaylistController.index()"
                    class="ml-auto text-[12.5px] font-semibold text-dim hover:text-paper"
                >
                    {{ $t('All playlists →') }}
                </Link>
            </div>
            <div
                class="grid grid-cols-[repeat(auto-fill,minmax(156px,1fr))] gap-x-4 gap-y-6"
            >
                <Link
                    v-for="(playlist, index) in recentPlaylists"
                    :key="playlist.id"
                    :href="PlaylistController.show(playlist.id)"
                    class="card reveal min-w-0"
                    :style="{ '--i': index + 9 }"
                >
                    <Artwork
                        :src="playlist.thumbnailUrl"
                        :alt="playlist.title"
                        class="aspect-square rounded-[5px] shadow-card"
                    />
                    <p class="mt-2.5 truncate text-sm font-semibold">
                        {{ playlist.title }}
                    </p>
                    <p class="text-[12.5px] font-medium text-faint">
                        {{
                            playlist.trackCount
                                ? $tChoice(
                                      ':count track|:count tracks',
                                      playlist.trackCount,
                                  )
                                : $t('Auto playlist')
                        }}
                    </p>
                </Link>
            </div>
        </section>

        <aside
            v-if="!player.state.queueOpen"
            class="min-w-0 xl:border-l xl:border-line xl:pl-7"
        >
            <div
                class="reveal mb-3.5 flex items-baseline gap-2.5"
                style="--i: 3"
            >
                <h2 class="text-sm font-bold">{{ $t('Up next') }}</h2>
                <span
                    v-if="player.state.source"
                    class="truncate text-[12.5px] font-medium text-faint"
                    >{{ player.state.source.title }}</span
                >
            </div>
            <TransitionGroup tag="div" name="list" class="relative">
                <TrackMiniRow
                    v-for="(track, offset) in player.upNext.value.slice(0, 6)"
                    :key="track.key"
                    :track="track"
                    :number="offset + 1"
                    @select="(event) => pick(event, offset)"
                />
            </TransitionGroup>
            <p
                v-if="player.upNext.value.length === 0"
                class="rounded-lg border border-dashed border-line px-4 py-5 text-sm text-faint"
            >
                {{
                    $t(
                        'Nothing queued. Open a playlist and pick a track to start.',
                    )
                }}
            </p>

            <h2 class="reveal mt-8 mb-2 text-sm font-bold" style="--i: 10">
                {{ $t('Your library') }}
            </h2>
            <Deferred data="stats">
                <template #fallback>
                    <div
                        v-for="row in 4"
                        :key="row"
                        class="flex justify-between border-b border-dashed border-line py-2.5"
                    >
                        <div class="skeleton h-3 w-24" />
                        <div class="skeleton h-3 w-10" />
                    </div>
                </template>
                <dl v-if="stats">
                    <div class="stat">
                        <dt>{{ $t('Tracks') }}</dt>
                        <dd>{{ formatNumber(stats.trackCount) }}</dd>
                    </div>
                    <div class="stat">
                        <dt>{{ $t('Artists') }}</dt>
                        <dd>{{ formatNumber(stats.artistCount) }}</dd>
                    </div>
                    <div class="stat">
                        <dt>{{ $t('Hours of music') }}</dt>
                        <dd>
                            {{
                                $t(':hours h', {
                                    hours: formatNumber(stats.totalHours),
                                })
                            }}
                        </dd>
                    </div>
                </dl>
                <template v-if="stats?.topArtists.length">
                    <h3 class="mt-5 mb-1.5 text-[12.5px] font-bold text-dim">
                        {{ $t('Most collected artists') }}
                    </h3>
                    <ol>
                        <li
                            v-for="(artist, index) in stats.topArtists"
                            :key="artist.name"
                            class="flex items-baseline gap-2.5 py-1 text-sm"
                        >
                            <span
                                class="w-4 text-right text-[12.5px] font-medium text-faint"
                                >{{ index + 1 }}</span
                            >
                            <span
                                class="min-w-0 flex-1 truncate font-semibold"
                                >{{ artist.name }}</span
                            >
                            <span
                                class="text-[12.5px] font-medium text-faint"
                                >{{ artist.trackCount }}</span
                            >
                        </li>
                    </ol>
                </template>
            </Deferred>
        </aside>
    </div>
</template>

<style scoped>
.shadow-card {
    box-shadow:
        0 18px 30px -20px oklch(0 0 0 / 0.8),
        inset 0 0 0 1px oklch(1 0 0 / 0.06);
}

.card :deep(img) {
    transition:
        opacity 420ms var(--ease-out-quint),
        transform 420ms var(--ease-out-quint);
}

.card:hover :deep(img) {
    transform: scale(1.045);
}

.play-hint {
    position: absolute;
    right: 8px;
    bottom: 8px;
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    opacity: 0;
    transform: translateY(6px);
    transition:
        opacity 240ms var(--ease-out-quint),
        transform 240ms var(--ease-out-quint);
}

.card:hover .play-hint,
.card:focus-visible .play-hint {
    opacity: 1;
    transform: none;
}

.stat {
    display: flex;
    justify-content: space-between;
    padding: 9px 0;
    border-bottom: 1px dashed var(--color-line);
}

.stat dd {
    font-weight: 700;
}
</style>
