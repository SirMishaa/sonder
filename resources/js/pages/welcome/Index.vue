<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import SessionController from '@/actions/App/Http/Controllers/SessionController';
import UserController from '@/actions/App/Http/Controllers/UserController';
import SonderMark from '@/components/brand/SonderMark.vue';
import SonderWordmark from '@/components/brand/SonderWordmark.vue';
import DiscoveryFragment from '@/components/landing/DiscoveryFragment.vue';
import HeroScene from '@/components/landing/HeroScene.vue';
import LibraryFragment from '@/components/landing/LibraryFragment.vue';
import RevealOnScroll from '@/components/landing/RevealOnScroll.vue';
import TerrainFragment from '@/components/landing/TerrainFragment.vue';

const REPOSITORY = 'https://github.com/SirMishaa/sonder';
</script>

<template>
    <div class="landing">
        <Head :title="$t('A listening room for your YouTube Music library')">
            <meta
                name="description"
                :content="
                    $t(
                        'Sonder keeps your YouTube Music playlists in sync, shows them the way a record collector would, and finds the tracks you have never played.',
                    )
                "
            />
        </Head>

        <header class="shell flex items-center justify-between gap-4 py-5">
            <Link
                href="/"
                class="flex items-center gap-2"
                :aria-label="$t('Sonder, home')"
            >
                <SonderMark spinning class="size-9" />
                <SonderWordmark />
            </Link>
            <nav
                class="flex items-center gap-1 text-[14px] font-semibold whitespace-nowrap"
                :aria-label="$t('Main')"
            >
                <a
                    :href="REPOSITORY"
                    class="hidden rounded-lg px-3 py-2 text-dim transition-colors hover:text-paper sm:block"
                    >GitHub</a
                >
                <Link
                    :href="SessionController.create()"
                    class="hidden rounded-lg px-3 py-2 text-dim transition-colors hover:text-paper sm:block"
                    >{{ $t('Log in') }}</Link
                >
                <Link :href="UserController.create()" class="button-primary">{{
                    $t('Create an account')
                }}</Link>
            </nav>
        </header>

        <main>
            <section class="shell hero">
                <div class="hero-copy">
                    <p class="kicker reveal" style="--i: 1">
                        {{ $t('For your YouTube Music library') }}
                    </p>
                    <h1 class="display reveal" style="--i: 2">
                        {{
                            $t(
                                'A listening room for the music you already love.',
                            )
                        }}
                    </h1>
                    <p class="lede reveal" style="--i: 4">
                        {{
                            $t(
                                'Sonder keeps your playlists in sync, shows them the way a record collector would, and finds the tracks you have never played.',
                            )
                        }}
                    </p>
                    <div
                        class="reveal mt-9 flex flex-wrap items-center gap-3"
                        style="--i: 6"
                    >
                        <Link
                            :href="UserController.create()"
                            class="button-primary is-large"
                        >
                            {{ $t('Create an account') }}
                            <ArrowRight class="size-4" />
                        </Link>
                        <Link
                            :href="SessionController.create()"
                            class="button-secondary is-large"
                            >{{ $t('Log in') }}</Link
                        >
                    </div>
                    <p
                        class="reveal mt-5 text-[13px] font-medium text-faint"
                        style="--i: 7"
                    >
                        {{
                            $t(
                                'Free and open source. Bring your own YouTube Music account.',
                            )
                        }}
                    </p>
                </div>

                <HeroScene class="hero-scene" />
            </section>

            <RevealOnScroll>
                <section class="shell moment">
                    <div class="moment-copy">
                        <p class="kicker">{{ $t('Your library') }}</p>
                        <h2 class="headline">
                            {{ $t('Every playlist, finally easy to read.') }}
                        </h2>
                        <p class="body">
                            {{
                                $t(
                                    'Sonder syncs your library in the background and shows you what changed and when it was last checked. A playlist that left YouTube Music stays here, flagged, with its tracks intact.',
                                )
                            }}
                        </p>
                    </div>
                    <LibraryFragment style="--i: 1" />
                </section>
            </RevealOnScroll>

            <RevealOnScroll>
                <section class="shell moment is-flipped">
                    <div class="moment-copy">
                        <p class="kicker">{{ $t('Discovery') }}</p>
                        <h2 class="headline">
                            {{ $t('The tracks you have never played.') }}
                        </h2>
                        <p class="body">
                            {{
                                $t(
                                    'Suggestions come with their reason written next to them, and you sort them without touching the mouse. Try it: click the list, then J and K to move, A to add, X to skip.',
                                )
                            }}
                        </p>
                        <p class="mt-4 text-[13px] font-medium text-faint">
                            {{
                                $t(
                                    'The discovery engine is being built; for now suggestions are drawn from your own library.',
                                )
                            }}
                        </p>
                    </div>
                    <DiscoveryFragment style="--i: 1" />
                </section>
            </RevealOnScroll>

            <RevealOnScroll>
                <section class="shell moment">
                    <div class="moment-copy">
                        <p class="kicker">
                            {{ $t('Honest about the terrain') }}
                        </p>
                        <h2 class="headline">
                            {{ $t('No mystery when something breaks.') }}
                        </h2>
                        <p class="body">
                            {{
                                $t(
                                    'YouTube Music has no public API for your library, so Sonder reads it with a cookie you paste once. When that cookie stops working, it tells you straight away and shows you how to replace it.',
                                )
                            }}
                        </p>
                        <a
                            :href="REPOSITORY"
                            class="mt-5 inline-flex items-center gap-1.5 text-[14px] font-semibold text-paper underline decoration-line underline-offset-4 transition-colors hover:decoration-amber"
                        >
                            {{ $t('Read the code on GitHub') }}
                            <ArrowRight class="size-4" />
                        </a>
                    </div>
                    <TerrainFragment style="--i: 1" />
                </section>
            </RevealOnScroll>

            <RevealOnScroll>
                <section class="shell finale">
                    <SonderMark spinning class="finale-mark" />
                    <h2 class="display" style="--i: 1">
                        {{ $t('Pull up a chair.') }}
                    </h2>
                    <div
                        class="flex flex-wrap items-center justify-center gap-3"
                        style="--i: 2"
                    >
                        <Link
                            :href="UserController.create()"
                            class="button-primary is-large"
                        >
                            {{ $t('Create an account') }}
                            <ArrowRight class="size-4" />
                        </Link>
                        <Link
                            :href="SessionController.create()"
                            class="button-secondary is-large"
                            >{{ $t('Log in') }}</Link
                        >
                    </div>
                </section>
            </RevealOnScroll>
        </main>

        <footer
            class="shell flex flex-wrap items-center justify-between gap-3 border-t border-line/60 py-6 text-[13px] text-faint"
        >
            <SonderWordmark class="!text-[18px] opacity-80" />
            <p>
                {{ $t('Not affiliated with YouTube or Google.') }}
                <a
                    :href="REPOSITORY"
                    class="ml-2 font-semibold text-dim hover:text-paper"
                    >GitHub</a
                >
            </p>
        </footer>
    </div>
</template>

<style scoped>
.landing {
    min-height: 100%;
    overflow-x: clip;
}

.shell {
    width: min(1200px, 100% - 2 * clamp(16px, 4vw, 40px));
    margin-inline: auto;
}

.hero {
    display: grid;
    grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
    align-items: center;
    gap: clamp(32px, 5vw, 64px);
    padding-block: clamp(40px, 8vw, 104px) clamp(64px, 10vw, 140px);
}

.hero-scene {
    animation: view-in 900ms var(--ease-out-quint) 260ms both;
}

.kicker {
    font-size: 13px;
    font-weight: 700;
    color: var(--color-amber);
}

.display {
    margin-top: 14px;
    font-size: clamp(40px, 5.2vw, 68px);
    font-weight: 800;
    line-height: 0.98;
    letter-spacing: -0.045em;
    text-wrap: balance;
}

.lede {
    margin-top: 22px;
    max-width: 34rem;
    font-size: clamp(16px, 1.4vw, 18.5px);
    line-height: 1.6;
    color: var(--color-dim);
    text-wrap: pretty;
}

.moment {
    display: grid;
    grid-template-columns: minmax(0, 4fr) minmax(0, 6fr);
    align-items: center;
    gap: clamp(32px, 6vw, 96px);
    padding-block: clamp(56px, 9vw, 120px);
}

.moment.is-flipped {
    grid-template-columns: minmax(0, 6fr) minmax(0, 4fr);
}

.moment.is-flipped > .moment-copy {
    order: 2;
}

/* The one interactive moment sits in its own pool of amp light. */
.moment.is-flipped > :not(.moment-copy) {
    box-shadow:
        0 0 0 1px oklch(0.8 0.15 68 / 0.12),
        0 40px 120px -30px oklch(0.8 0.15 68 / 0.28);
}

.headline {
    margin-top: 10px;
    font-size: clamp(28px, 3.4vw, 44px);
    font-weight: 800;
    line-height: 1.05;
    letter-spacing: -0.035em;
    text-wrap: balance;
}

.body {
    margin-top: 16px;
    max-width: 30rem;
    font-size: 16px;
    line-height: 1.65;
    color: var(--color-dim);
    text-wrap: pretty;
}

.finale {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 28px;
    padding-block: clamp(72px, 12vw, 160px);
    text-align: center;
}

.finale .display {
    margin-top: 0;
}

.finale-mark {
    width: clamp(72px, 9vw, 112px);
    height: auto;
    filter: drop-shadow(0 0 40px oklch(0.8 0.15 68 / 0.35));
}

.button-primary,
.button-secondary {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border-radius: 9px;
    padding: 8px 14px;
    font-weight: 700;
    transition:
        filter 140ms,
        transform 140ms,
        border-color 140ms;
}

.button-primary {
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    box-shadow: 0 10px 24px -12px oklch(0.7 0.17 55 / 0.8);
}

.button-primary:hover {
    filter: brightness(1.06);
}

.button-secondary {
    border: 1px solid var(--color-line);
    background: oklch(0.19 0.013 60 / 0.8);
    color: var(--color-paper);
}

.button-secondary:hover {
    border-color: oklch(0.4 0.014 60);
}

.button-primary:active,
.button-secondary:active {
    transform: scale(0.97);
}

.is-large {
    padding: 12px 20px;
    font-size: 15px;
}

@media (max-width: 899px) {
    .hero,
    .moment {
        grid-template-columns: minmax(0, 1fr);
    }

    .moment.is-flipped {
        grid-template-columns: minmax(0, 1fr);
    }

    .moment.is-flipped > .moment-copy {
        order: 0;
    }
}
</style>
