<script setup lang="ts">
type Props = {
    playing?: boolean;
};

withDefaults(defineProps<Props>(), { playing: true });
</script>

<template>
    <span
        class="equalizer"
        :class="{ 'is-paused': !playing }"
        aria-hidden="true"
    >
        <i />
        <i />
        <i />
    </span>
</template>

<style scoped>
.equalizer {
    display: inline-flex;
    align-items: flex-end;
    gap: 2px;
    height: 12px;
}

.equalizer i {
    width: 2.5px;
    height: 7px;
    border-radius: 1px;
    background: var(--color-amber);
    animation: equalize 900ms ease-in-out infinite;
}

/* Static heights so the bars stay visible when motion is reduced. */
.equalizer i:nth-child(1) {
    height: 9px;
}

.equalizer i:nth-child(3) {
    height: 5px;
}

.equalizer i:nth-child(2) {
    animation-delay: -300ms;
}

.equalizer i:nth-child(3) {
    animation-delay: -600ms;
}

.is-paused i {
    animation-play-state: paused;
}
</style>
