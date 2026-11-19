<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

// PromptEdit mark: a video "frame" (4 corners) around a spark (the AI generation).
// Two colors from the theme tokens, so it follows light/dark mode automatically.
defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" :class="className" v-bind="$attrs" aria-hidden="true">
        <rect width="32" height="32" rx="8" class="fill-primary" />
        <path
            d="M9 13v-3a1 1 0 0 1 1-1h3M19 9h3a1 1 0 0 1 1 1v3M23 19v3a1 1 0 0 1-1 1h-3M13 23h-3a1 1 0 0 1-1-1v-3"
            fill="none"
            class="stroke-primary-foreground"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path d="M16 11.5c.4 2.3 2.2 4.1 4.5 4.5-2.3.4-4.1 2.2-4.5 4.5-.4-2.3-2.2-4.1-4.5-4.5 2.3-.4 4.1-2.2 4.5-4.5z" class="fill-primary-foreground" />
    </svg>
</template>
