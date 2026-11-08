<script setup lang="ts">
/**
 * The blurred artwork wash behind a page's header (playlist, album, artist).
 * Place it first inside a `relative` wrapper; it fades out downwards.
 */
type Props = {
    src: string | null;
};

defineProps<Props>();
</script>

<template>
    <div class="ambient" aria-hidden="true">
        <div :style="{ backgroundImage: src ? `url('${src}')` : undefined }" />
    </div>
</template>

<style scoped>
.ambient {
    position: absolute;
    inset: 0 0 auto 0;
    height: 520px;
    overflow: hidden;
    pointer-events: none;
    mask-image: linear-gradient(180deg, #000 45%, transparent 100%);
}

.ambient div {
    position: absolute;
    inset: -60px;
    background-size: cover;
    background-position: center;
    filter: blur(70px) saturate(1.35);
    opacity: 0.42;
    animation: fade-in 900ms var(--ease-out-quint) both;
}

.ambient::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        180deg,
        oklch(0.165 0.012 60 / 0.15),
        oklch(0.165 0.012 60 / 0.7) 55%,
        transparent 100%
    );
}

@keyframes fade-in {
    from {
        opacity: 0;
    }
}
</style>
