<script setup lang="ts">
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { ACTIVE_STATUSES, errorTitle, FAILED_STATUSES, STATUS, type GenerationStatus } from '@/lib/generationStatus';
import { formatUsd } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { CircleX, ExternalLink, ImageOff, Images, Info, Plus, RotateCcw } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface GenerationRow {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    reference_count: number;
    status: GenerationStatus;
    error_code: string | null;
    error_message: string | null;
    estimated_min: string;
    estimated_max: string;
    price: string | null;
    usage: Record<string, number> | null;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
    completed_at: string | null;
}

const props = defineProps<{ generations: GenerationRow[]; markupPercent?: number }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

const isActive = (g: GenerationRow) => ACTIVE_STATUSES.includes(g.status);
const isFailed = (g: GenerationRow) => FAILED_STATUSES.includes(g.status);

// Live status: reload the list (and the credit balance) every 3 s while something is still running.
const hasActive = computed(() => props.generations.some(isActive));
const { start, stop } = usePoll(3000, { only: ['generations', 'billing'] }, { autoStart: false });
watch(hasActive, (active) => (active ? start() : stop()), { immediate: true });

// ---- Filters -------------------------------------------------------------
type Filter = 'all' | 'active' | 'succeeded' | 'failed';
const filter = ref<Filter>('all');
const matches: Record<Filter, (g: GenerationRow) => boolean> = {
    all: () => true,
    active: isActive,
    succeeded: (g) => g.status === 'succeeded',
    failed: isFailed,
};
const filters = computed(() =>
    (
        [
            ['all', 'All'],
            ['active', 'In progress'],
            ['succeeded', 'Done'],
            ['failed', 'Failed'],
        ] as [Filter, string][]
    ).map(([value, label]) => ({ value, label, count: props.generations.filter(matches[value]).length })),
);
const visible = computed(() => props.generations.filter(matches[filter.value]));

// ---- Display helpers -----------------------------------------------------
const sizeLabel: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
const timeAgo = (iso: string) => {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];
    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit);
    }
    return 'just now';
};

const retry = (g: GenerationRow) => router.post(route('generations.retry', g.id), {}, { preserveScroll: true });
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-5 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Your generations</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Status updates live. Images stay available for 24 h after they are done.
                        <span v-if="hasActive" class="inline-flex items-center gap-1 text-info">
                            <span class="size-1.5 animate-pulse rounded-full bg-info" aria-hidden="true" /> Live
                        </span>
                    </p>
                </div>
                <Button as-child>
                    <Link :href="route('generations.create')"><Plus aria-hidden="true" /> New image</Link>
                </Button>
            </div>

            <!-- Filters -->
            <div v-if="generations.length" role="group" aria-label="Filter by status" class="inline-flex flex-wrap gap-1 rounded-lg border bg-muted/50 p-1">
                <button
                    v-for="f in filters"
                    :key="f.value"
                    type="button"
                    :aria-pressed="filter === f.value"
                    class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-pressed:bg-background aria-pressed:text-foreground aria-pressed:shadow-card"
                    @click="filter = f.value"
                >
                    {{ f.label }}
                    <span class="rounded-full bg-muted px-1.5 text-xs tabular-nums">{{ f.count }}</span>
                </button>
            </div>

            <!-- Empty state -->
            <div v-if="generations.length === 0" class="flex flex-col items-center rounded-xl border border-dashed px-6 py-16 text-center">
                <span class="flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                    <Images class="size-6" aria-hidden="true" />
                </span>
                <h2 class="mt-4 font-semibold">No images yet</h2>
                <p class="mt-1 max-w-sm text-sm text-muted-foreground">Write a prompt, check the price, and your images will show up here with exactly what they cost.</p>
                <Button as-child class="mt-5">
                    <Link :href="route('generations.create')"><Plus aria-hidden="true" /> Create your first image</Link>
                </Button>
            </div>

            <p v-else-if="visible.length === 0" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                Nothing here with this filter.
                <button type="button" class="font-medium text-primary underline-offset-4 hover:underline" @click="filter = 'all'">Show all</button>
            </p>

            <!-- Cards -->
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="g in visible" :key="g.id" class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-card">
                    <!-- Preview area: image, progress, expiry or error -->
                    <div class="relative flex aspect-square items-center justify-center bg-muted">
                        <a
                            v-if="g.image_url && !g.image_expired"
                            :href="g.image_url"
                            target="_blank"
                            rel="noopener"
                            class="group size-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring"
                            aria-label="Open the full image in a new tab"
                        >
                            <img :src="g.image_url" :alt="g.prompt" loading="lazy" class="size-full object-contain" />
                            <span
                                class="absolute right-2 top-2 rounded-md bg-black/60 p-1.5 text-white opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                                aria-hidden="true"
                            >
                                <ExternalLink class="size-4" />
                            </span>
                        </a>

                        <div v-else-if="g.status === 'succeeded' && g.image_expired" class="flex flex-col items-center gap-2 p-6 text-center">
                            <ImageOff class="size-8 text-muted-foreground" aria-hidden="true" />
                            <p class="text-sm font-medium">Image link expired</p>
                            <p class="text-xs text-muted-foreground">The provider keeps images for 24 h. The charge below is still correct.</p>
                        </div>

                        <div v-else-if="isFailed(g)" class="flex flex-col items-center gap-2 p-6 text-center" role="status">
                            <CircleX class="size-8 text-destructive" aria-hidden="true" />
                            <p class="text-sm font-medium">{{ errorTitle(g.error_code) }}</p>
                            <p class="text-xs text-muted-foreground">{{ g.error_message }}</p>
                            <p class="text-xs font-medium text-success">Nothing was charged.</p>
                        </div>

                        <div
                            v-else
                            class="flex size-full motion-safe:animate-shimmer flex-col items-center justify-center gap-3 bg-[linear-gradient(110deg,transparent_30%,hsl(var(--primary)/0.10)_50%,transparent_70%)] bg-[length:200%_100%] p-6 text-center"
                            role="status"
                        >
                            <component
                                :is="STATUS[g.status].icon"
                                :class="['size-8 text-primary', STATUS[g.status].spin && 'animate-spin']"
                                aria-hidden="true"
                            />
                            <p class="text-sm text-muted-foreground">{{ STATUS[g.status].hint }}</p>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-4 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <StatusBadge :status="g.status" />
                            <span class="text-xs text-muted-foreground">
                                {{ sizeLabel[g.size] ?? g.size }} · <span class="capitalize">{{ g.quality }}</span>
                                <template v-if="g.reference_count"> · {{ g.reference_count }} ref.</template>
                            </span>
                        </div>

                        <p class="line-clamp-2" :title="g.prompt">{{ g.prompt }}</p>

                        <!-- Money: estimate vs what was actually charged -->
                        <dl class="mt-auto space-y-1 rounded-lg bg-muted/60 p-3 text-xs">
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted-foreground">Estimated</dt>
                                <dd class="money" :title="`${g.estimated_min} – ${g.estimated_max} USD`">
                                    {{ formatUsd(g.estimated_min, 4) }} – {{ formatUsd(g.estimated_max, 4) }}
                                </dd>
                            </div>
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <dt class="flex items-center gap-1 font-medium">
                                    {{ isActive(g) ? 'Reserved' : 'Charged' }}
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button type="button" class="rounded-full text-muted-foreground" aria-label="How is this price calculated?">
                                                <Info class="size-3.5" />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent class="max-w-72 text-xs">
                                            <template v-if="g.status === 'succeeded' && g.price">
                                                Exact charge: <span class="money">{{ formatUsd(g.price, 9) }}</span>. It is the provider's cost for the tokens
                                                actually used<template v-if="markupPercent">, plus our {{ markupPercent }}% service fee</template>, with the rates
                                                locked when you submitted.
                                            </template>
                                            <template v-else-if="isFailed(g)">Failed and refused generations are never charged.</template>
                                            <template v-else>
                                                The maximum price is put on hold while the image is generated. You are charged the exact price when it is done,
                                                and the rest is released.
                                            </template>
                                        </TooltipContent>
                                    </Tooltip>
                                </dt>
                                <dd class="money">
                                    <template v-if="isFailed(g)"><span class="text-success">$0.00</span></template>
                                    <template v-else-if="g.price">{{ formatUsd(g.price, 4) }}</template>
                                    <template v-else><span class="text-muted-foreground">up to</span> {{ formatUsd(g.estimated_max, 4) }}</template>
                                </dd>
                            </div>
                        </dl>

                        <details v-if="g.usage" class="text-xs text-muted-foreground">
                            <summary class="cursor-pointer select-none rounded hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                Token usage
                            </summary>
                            <dl class="mt-1 grid grid-cols-2 gap-x-3 tabular-nums">
                                <dt>Prompt text</dt>
                                <dd class="text-right">{{ g.usage.input_text_tokens }}</dd>
                                <dt>Reference images</dt>
                                <dd class="text-right">{{ g.usage.input_image_tokens }}</dd>
                                <dt>Image output</dt>
                                <dd class="text-right">{{ g.usage.output_tokens }}</dd>
                                <dt>Reasoning</dt>
                                <dd class="text-right">{{ g.usage.reasoning_tokens }}</dd>
                            </dl>
                        </details>

                        <div class="flex items-center justify-between text-xs text-muted-foreground">
                            <time :datetime="g.created_at" :title="new Date(g.created_at).toLocaleString()">{{ timeAgo(g.created_at) }}</time>
                            <Button v-if="isFailed(g)" size="sm" variant="outline" @click="retry(g)"><RotateCcw aria-hidden="true" /> Try again</Button>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
