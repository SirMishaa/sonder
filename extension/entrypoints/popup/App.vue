<script setup lang="ts">
import { onMounted, ref } from 'vue';
import RippleMark from '@/components/RippleMark.vue';
import { CONNECT_URL, SONDER_URL } from '@/lib/config';
import { tokenStore } from '@/lib/token';

const signedIn = ref<boolean | null>(null);
const host = new URL(SONDER_URL).host;

onMounted(async () => {
    signedIn.value = (await tokenStore().get()) !== null;
});
</script>

<template>
    <main class="popup w-72 bg-ink p-4 font-sans text-paper">
        <header class="reveal flex items-center gap-3">
            <RippleMark
                class="size-10"
                :turning="signedIn === true"
                :dim="signedIn === false"
            />
            <div>
                <p
                    class="text-[22px] leading-none font-extrabold tracking-[-0.02em]"
                >
                    sonder
                </p>
                <p
                    class="mt-1 text-[12.5px] font-medium text-faint tabular-nums"
                >
                    {{ host }}
                </p>
            </div>
        </header>

        <template v-if="signedIn">
            <p
                class="reveal mt-4 flex items-center gap-2 text-[13.5px] font-semibold"
            >
                <span
                    class="size-2 rounded-full bg-signal shadow-[0_0_8px_var(--color-signal)]"
                />
                Connecté à Sonder
            </p>

            <section class="reveal mt-3 rounded-xl bg-panel p-3">
                <p class="text-[13px] font-bold text-amber">Sur YouTube</p>
                <ol class="mt-2 space-y-1.5 text-[13px] text-dim">
                    <li class="flex items-center gap-2">
                        <kbd class="keycap">⋮</kbd> sur une vidéo ou un mix
                    </li>
                    <li class="flex items-center gap-2">
                        <kbd class="keycap">Sonder</kbd> en bas du menu
                    </li>
                    <li class="flex items-center gap-2">
                        <kbd class="keycap">▶</kbd> lire, ⤵ ensuite, ＋ file ou
                        le panneau
                    </li>
                </ol>
            </section>

            <a
                :href="`${SONDER_URL}/dashboard`"
                target="_blank"
                data-test="open-sonder"
                class="reveal mt-4 flex cursor-pointer items-center justify-center rounded-[9px] bg-[linear-gradient(135deg,var(--color-amber-light),var(--color-amber-deep))] px-3.5 py-2 text-[14px] font-bold text-amber-ink transition-[filter,transform] duration-150 hover:brightness-105 active:scale-[0.97]"
                >Ouvrir Sonder</a
            >
        </template>

        <template v-else-if="signedIn === false">
            <p class="reveal mt-4 text-[13.5px] text-dim">
                Non connecté. Connecte l'extension depuis les réglages de Sonder
                pour envoyer tes vidéos et tes mix.
            </p>
            <a
                :href="CONNECT_URL"
                target="_blank"
                data-test="connect"
                class="reveal mt-4 flex cursor-pointer items-center justify-center rounded-[9px] bg-[linear-gradient(135deg,var(--color-amber-light),var(--color-amber-deep))] px-3.5 py-2 text-[14px] font-bold text-amber-ink transition-[filter,transform] duration-150 hover:brightness-105 active:scale-[0.97]"
                >Connecter l'extension</a
            >
        </template>
    </main>
</template>

<style scoped>
.keycap {
    display: inline-grid;
    min-width: 22px;
    place-items: center;
    border-radius: 6px;
    background: var(--color-ink);
    box-shadow:
        inset 0 -1px 0 oklch(1 0 0 / 0.06),
        0 0 0 1px var(--color-line);
    padding: 1px 6px;
    font-size: 11px;
    font-weight: 600;
    color: var(--color-paper);
}

@media (prefers-reduced-motion: no-preference) {
    .reveal {
        animation: reveal 420ms var(--ease-out-quint) both;
    }

    .reveal:nth-child(2) {
        animation-delay: 40ms;
    }

    .reveal:nth-child(3) {
        animation-delay: 80ms;
    }

    .reveal:nth-child(4) {
        animation-delay: 120ms;
    }
}

@keyframes reveal {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
}
</style>
