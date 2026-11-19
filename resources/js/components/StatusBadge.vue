<script setup lang="ts">
import { STATUS, type GenerationStatus, type StatusTone } from '@/lib/generationStatus';
import { cn } from '@/lib/utils';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus }>();

const meta = computed(() => STATUS[props.status]);

// Soft background + strong text: readable in light and dark mode (see docs/BRAND.md "Status badges").
const tones: Record<StatusTone, string> = {
    neutral: 'bg-muted text-muted-foreground ring-border',
    info: 'bg-info/10 text-info ring-info/25',
    success: 'bg-success/10 text-success ring-success/25',
    danger: 'bg-destructive/10 text-destructive ring-destructive/25',
};
</script>

<template>
    <span :class="cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[meta.tone])">
        <component :is="meta.icon" :class="cn('size-3.5', meta.spin && 'animate-spin')" aria-hidden="true" />
        {{ meta.label }}
    </span>
</template>
