<script setup lang="ts">
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const flash = computed(() => page.props.flash ?? { success: null, error: null });
</script>

<template>
    <div v-if="flash.success || flash.error" class="px-4 pt-4 md:px-6" aria-live="polite">
        <p v-if="flash.success" role="status" class="flex items-center gap-2 rounded-lg border border-success/25 bg-success/10 px-3 py-2 text-sm text-success">
            <CircleCheck class="size-4 shrink-0" aria-hidden="true" />
            {{ flash.success }}
        </p>
        <p v-if="flash.error" role="alert" class="flex items-center gap-2 rounded-lg border border-destructive/25 bg-destructive/10 px-3 py-2 text-sm text-destructive">
            <CircleAlert class="size-4 shrink-0" aria-hidden="true" />
            {{ flash.error }}
        </p>
    </div>
</template>
