<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { CONNECT_URL } from '@/lib/config';
import { tokenStore } from '@/lib/token';

const signedIn = ref<boolean | null>(null);

onMounted(async () => {
    signedIn.value = (await tokenStore().get()) !== null;
});
</script>

<template>
    <main class="w-64 space-y-2 bg-ink p-4 font-sans text-paper">
        <p class="flex items-center gap-2 font-bold">
            <span
                class="size-2 rounded-full"
                :class="signedIn ? 'bg-signal' : 'bg-faint'"
            />
            Sonder
        </p>
        <p v-if="signedIn" class="text-sm text-dim">Connecté à Sonder.</p>
        <p v-else-if="signedIn === false" class="text-sm text-dim">
            Non connecté.
            <a
                :href="CONNECT_URL"
                target="_blank"
                class="text-amber underline-offset-2 hover:underline"
                >Connecter l'extension</a
            >
        </p>
    </main>
</template>
