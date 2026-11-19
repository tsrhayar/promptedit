<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import SegmentedControl from '@/components/SegmentedControl.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/AppLayout.vue';
import { estimate, formatNano, nanoPerToken, withMarkup, type Pricing, type Quality, type Size, type TokenCounts } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CircleAlert, ImageIcon, Info, LoaderCircle, Plus, Sparkles, TriangleAlert, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pricing: Pricing | null;
    markupPercent: number;
    balanceNano: number;
    configured: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const MAX_REFERENCES = 4;
const MAX_PROMPT = 4000;

// Width/height drive the little shape preview in each size option.
const sizes: { value: Size; label: string; description: string; w: number; h: number }[] = [
    { value: '1024x1024', label: 'Square', description: '1024 × 1024', w: 22, h: 22 },
    { value: '1536x1024', label: 'Landscape', description: '1536 × 1024', w: 30, h: 20 },
    { value: '1024x1536', label: 'Portrait', description: '1024 × 1536', w: 20, h: 30 },
];
const shapeStyle = (value: Size) => {
    const size = sizes.find((s) => s.value === value)!;
    return { width: `${size.w}px`, height: `${size.h}px` };
};
const qualities: { value: Quality; label: string; description: string }[] = [
    { value: 'low', label: 'Low', description: 'Fast draft' },
    { value: 'medium', label: 'Medium', description: 'Balanced' },
    { value: 'high', label: 'High', description: 'Most detail' },
];

// Pixelforge sandbox keywords: they force an outcome so every edge case can be tried.
const testTags = ['succeed', 'fail', 'slow', 'duplicate', 'blocked', 'overloaded'];

const form = useForm({
    prompt: '',
    size: '1024x1024' as Size,
    quality: 'medium' as Quality,
    reference_images: [] as string[],
});

const promptLength = computed(() => [...form.prompt].length);

// ---- Reference images ----------------------------------------------------
const isHttpUrl = (value: string) => {
    try {
        return ['http:', 'https:'].includes(new URL(value.trim()).protocol);
    } catch {
        return false;
    }
};
const filledReferences = computed(() => form.reference_images.filter((url) => url.trim() !== ''));
const referenceCount = computed(() => filledReferences.value.length);
const invalidReferenceCount = computed(() => filledReferences.value.filter((url) => !isHttpUrl(url)).length);

const addReference = () => {
    if (form.reference_images.length < MAX_REFERENCES) form.reference_images.push('');
};
const removeReference = (index: number) => form.reference_images.splice(index, 1);

/** Error for one URL: the server's message wins, otherwise our instant check. */
const referenceError = (index: number) => {
    const serverError = (form.errors as Record<string, string>)[`reference_images.${index}`];
    const url = form.reference_images[index];
    if (serverError) return serverError;
    return url.trim() !== '' && !isHttpUrl(url) ? 'Enter a full http(s) link, e.g. https://example.com/photo.jpg' : undefined;
};

// ---- Test tags -----------------------------------------------------------
const hasTag = (tag: string) => form.prompt.includes(`[${tag}]`);
const toggleTag = (tag: string) => {
    form.prompt = hasTag(tag) ? form.prompt.replace(`[${tag}]`, '').replace(/\s{2,}/g, ' ').trim() : `${form.prompt.trim()} [${tag}]`.trim();
};

// ---- Price ---------------------------------------------------------------
// Recomputed on every keystroke from the pricing tables, no request needed.
// The server recomputes it on submit: the browser never decides a price.
const cost = computed(() =>
    props.pricing ? estimate(props.pricing, props.markupPercent, form.prompt, form.size, form.quality, referenceCount.value) : null,
);

/** Price of one line of the breakdown (tokens × rate, + markup). Display only. */
const linePrice = (type: keyof TokenCounts, tokens: number) =>
    props.pricing ? withMarkup(tokens * nanoPerToken(props.pricing.rates[type]), props.markupPercent) : 0;

const breakdown = computed(() => {
    if (!cost.value) return null;
    const { min, max } = cost.value.tokens;
    return {
        knownNow: [
            { label: `Image output (${form.quality})`, tokens: `${max.output_tokens}`, price: formatNano(linePrice('output_tokens', max.output_tokens), 4) },
            ...(referenceCount.value > 0
                ? [
                      {
                          label: `Reference images × ${referenceCount.value}`,
                          tokens: `${max.input_image_tokens}`,
                          price: formatNano(linePrice('input_image_tokens', max.input_image_tokens), 4),
                      },
                  ]
                : []),
        ],
        knownLater: [
            {
                label: 'Prompt text',
                tokens: `${min.input_text_tokens}–${max.input_text_tokens}`,
                price: `${formatNano(linePrice('input_text_tokens', min.input_text_tokens), 4)}–${formatNano(linePrice('input_text_tokens', max.input_text_tokens), 4)}`,
            },
            {
                label: 'Reasoning',
                tokens: `${min.reasoning_tokens}–${max.reasoning_tokens}`,
                price: `${formatNano(linePrice('reasoning_tokens', min.reasoning_tokens), 4)}–${formatNano(linePrice('reasoning_tokens', max.reasoning_tokens), 4)}`,
            },
        ],
    };
});

// ---- Credits & submit ----------------------------------------------------
const notEnoughCredits = computed(() => cost.value !== null && cost.value.maxNano > props.balanceNano);
const balanceAfterHold = computed(() => (cost.value ? props.balanceNano - cost.value.maxNano : props.balanceNano));

/** Why the Generate button is disabled, in plain words (null = ready). */
const blockedReason = computed<string | null>(() => {
    if (!props.pricing) return "Pricing is unavailable right now, so we can't estimate the cost. Reload in a moment.";
    if (form.prompt.trim() === '') return 'Describe the image you want to continue.';
    if (promptLength.value > MAX_PROMPT) return `Your prompt is too long (max ${MAX_PROMPT} characters).`;
    if (invalidReferenceCount.value > 0) return 'Fix the reference image links first.';
    if (notEnoughCredits.value) return `Not enough credits: up to ${formatNano(cost.value!.maxNano, 4)} is reserved while the image is generated.`;
    return null;
});
const canSubmit = computed(() => !form.processing && blockedReason.value === null);

const submit = () => {
    if (canSubmit.value) form.post(route('generations.store'));
};

const buyCredits = useForm({});
const buy = () => buyCredits.post(route('credits.store'), { preserveScroll: true });
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start" @submit.prevent="submit">
            <!-- Left: what to generate -->
            <fieldset :disabled="form.processing" class="min-w-0 space-y-6">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Create an image</h1>
                    <p class="mt-1 text-sm text-muted-foreground">You see the price before you start, and you only pay for images that succeed.</p>
                </div>

                <div v-if="!configured" role="alert" class="flex gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>The image provider is not configured on this server (PIXELFORGE_API_KEY is empty). Generations will be refused, and not charged.</p>
                </div>

                <!-- Prompt -->
                <section class="space-y-3 rounded-xl border bg-card p-4 shadow-card md:p-5">
                    <div class="flex items-baseline justify-between">
                        <Label for="prompt" class="text-sm font-medium">Prompt</Label>
                        <span class="text-xs tabular-nums text-muted-foreground" :class="{ 'text-destructive': promptLength > MAX_PROMPT }" aria-live="polite">
                            {{ promptLength }} / {{ MAX_PROMPT }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        :maxlength="MAX_PROMPT"
                        placeholder="A lighthouse on a cliff at dawn, oil painting, warm light"
                        aria-describedby="prompt-help"
                        class="w-full resize-y rounded-lg border border-input bg-background px-3 py-2.5 text-sm leading-relaxed placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                        @keydown.enter.ctrl.prevent="submit"
                        @keydown.enter.meta.prevent="submit"
                    />
                    <p id="prompt-help" class="text-xs text-muted-foreground">
                        Describe the subject, style and lighting. Longer prompts cost a little more. Press <kbd class="rounded border px-1">Ctrl</kbd> +
                        <kbd class="rounded border px-1">Enter</kbd> to generate.
                    </p>
                    <InputError :message="form.errors.prompt" />

                    <div class="flex flex-wrap items-center gap-1.5 border-t pt-3">
                        <span class="mr-1 text-xs text-muted-foreground">Sandbox test tags:</span>
                        <button
                            v-for="tag in testTags"
                            :key="tag"
                            type="button"
                            :aria-pressed="hasTag(tag)"
                            class="rounded-full border px-2 py-0.5 font-mono text-xs transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-pressed:border-primary aria-pressed:bg-primary/10 aria-pressed:text-primary"
                            @click="toggleTag(tag)"
                        >
                            [{{ tag }}]
                        </button>
                    </div>
                </section>

                <!-- Format -->
                <section class="grid gap-6 rounded-xl border bg-card p-4 shadow-card md:p-5">
                    <SegmentedControl v-model="form.size" name="size" legend="Size" :options="sizes">
                        <template #visual="{ option, selected }">
                            <span class="flex h-8 items-center" aria-hidden="true">
                                <span
                                    class="block rounded-sm border-2"
                                    :class="selected ? 'border-primary bg-primary/15' : 'border-muted-foreground/50'"
                                    :style="shapeStyle(option.value)"
                                />
                            </span>
                        </template>
                    </SegmentedControl>
                    <InputError :message="form.errors.size" />

                    <SegmentedControl v-model="form.quality" name="quality" legend="Quality" :options="qualities" />
                    <InputError :message="form.errors.quality" />
                </section>

                <!-- Reference images -->
                <section class="space-y-3 rounded-xl border bg-card p-4 shadow-card md:p-5">
                    <div>
                        <h2 class="text-sm font-medium">Reference images <span class="font-normal text-muted-foreground">(optional)</span></h2>
                        <p class="text-xs text-muted-foreground">
                            Up to {{ MAX_REFERENCES }} public image links to guide the style. Each one adds
                            {{ pricing?.tokens.per_reference_image ?? 765 }} tokens
                            <template v-if="pricing">(≈ {{ formatNano(linePrice('input_image_tokens', pricing.tokens.per_reference_image), 4) }})</template>.
                        </p>
                    </div>

                    <ul v-if="form.reference_images.length" class="space-y-2">
                        <li v-for="(_, index) in form.reference_images" :key="index" class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted" aria-hidden="true">
                                    <img
                                        v-if="isHttpUrl(form.reference_images[index])"
                                        :key="form.reference_images[index]"
                                        :src="form.reference_images[index]"
                                        alt=""
                                        class="size-full object-cover"
                                        @error="($event.target as HTMLImageElement).style.display = 'none'"
                                    />
                                    <ImageIcon v-else class="size-4 text-muted-foreground" />
                                </span>
                                <Label :for="`reference-${index}`" class="sr-only">Reference image {{ index + 1 }} URL</Label>
                                <Input
                                    :id="`reference-${index}`"
                                    v-model="form.reference_images[index]"
                                    type="url"
                                    inputmode="url"
                                    placeholder="https://example.com/style.png"
                                    :aria-invalid="!!referenceError(index)"
                                    :class="{ 'border-destructive focus-visible:ring-destructive': referenceError(index) }"
                                />
                                <Button type="button" variant="ghost" size="icon" :aria-label="`Remove reference image ${index + 1}`" @click="removeReference(index)">
                                    <X />
                                </Button>
                            </div>
                            <InputError :message="referenceError(index)" class="pl-12" />
                        </li>
                    </ul>

                    <Button v-if="form.reference_images.length < MAX_REFERENCES" type="button" variant="outline" size="sm" @click="addReference">
                        <Plus /> Add reference image
                    </Button>
                    <InputError :message="form.errors.reference_images" />
                </section>
            </fieldset>

            <!-- Right: what it costs (sticky on desktop) -->
            <aside class="space-y-4 lg:sticky lg:top-6" aria-label="Cost summary">
                <section class="rounded-xl border bg-card p-5 shadow-elevated">
                    <h2 class="text-sm font-medium text-muted-foreground">Estimated price</h2>

                    <template v-if="cost && breakdown">
                        <p class="mt-1 text-3xl font-semibold tracking-tight">
                            <span class="money">{{ formatNano(cost.minNano, 4) }}</span>
                            <span class="text-muted-foreground"> – </span>
                            <span class="money">{{ formatNano(cost.maxNano, 4) }}</span>
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">Never more than {{ formatNano(cost.maxNano, 6) }}. Failed images cost $0.</p>

                        <dl class="mt-4 space-y-3 text-xs">
                            <div>
                                <dt class="mb-1 font-medium uppercase tracking-wide text-muted-foreground">Known now</dt>
                                <dd v-for="line in breakdown.knownNow" :key="line.label" class="flex justify-between gap-2 py-0.5">
                                    <span>{{ line.label }} <span class="text-muted-foreground">· {{ line.tokens }} tok</span></span>
                                    <span class="money">{{ line.price }}</span>
                                </dd>
                            </div>
                            <div>
                                <dt class="mb-1 flex items-center gap-1 font-medium uppercase tracking-wide text-muted-foreground">
                                    Known when it's done
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <button type="button" class="rounded-full" aria-label="Why is this a range?"><Info class="size-3.5" /></button>
                                        </TooltipTrigger>
                                        <TooltipContent class="max-w-64 text-xs">
                                            The provider counts prompt and reasoning tokens itself, so we only know the exact number after the image is
                                            generated. You are charged that exact amount.
                                        </TooltipContent>
                                    </Tooltip>
                                </dt>
                                <dd v-for="line in breakdown.knownLater" :key="line.label" class="flex justify-between gap-2 py-0.5">
                                    <span>{{ line.label }} <span class="text-muted-foreground">· {{ line.tokens }} tok</span></span>
                                    <span class="money">{{ line.price }}</span>
                                </dd>
                            </div>
                        </dl>
                        <p class="mt-3 text-xs text-muted-foreground">Prices include our {{ markupPercent }}% service fee on the provider's token cost.</p>
                    </template>

                    <p v-else role="alert" class="mt-2 flex gap-2 text-sm text-destructive">
                        <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        Pricing is unavailable right now, so we can't estimate the cost. Reload in a moment.
                    </p>

                    <!-- Credits -->
                    <dl class="mt-5 space-y-1.5 border-t pt-4 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Your credits</dt>
                            <dd class="money">{{ formatNano(balanceNano, 4) }}</dd>
                        </div>
                        <div v-if="cost" class="flex justify-between">
                            <dt class="text-muted-foreground">Reserved while generating</dt>
                            <dd class="money">−{{ formatNano(cost.maxNano, 4) }}</dd>
                        </div>
                        <div v-if="cost" class="flex justify-between font-medium">
                            <dt>Available after</dt>
                            <dd class="money" :class="{ 'text-destructive': notEnoughCredits }">{{ formatNano(balanceAfterHold, 4) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-muted-foreground">When the image is done we charge the exact price and give back the rest.</p>

                    <Button type="submit" size="lg" class="mt-5 w-full" :disabled="!canSubmit" :aria-describedby="blockedReason ? 'blocked-reason' : undefined">
                        <LoaderCircle v-if="form.processing" class="animate-spin" aria-hidden="true" />
                        <Sparkles v-else aria-hidden="true" />
                        {{ form.processing ? 'Submitting…' : 'Generate image' }}
                    </Button>
                    <p v-if="blockedReason && !form.processing" id="blocked-reason" class="mt-2 text-center text-xs text-muted-foreground" aria-live="polite">
                        {{ blockedReason }}
                    </p>
                    <InputError :message="(form.errors as Record<string, string>).credits" class="mt-2 text-center" />

                    <Button v-if="notEnoughCredits" type="button" variant="outline" class="mt-2 w-full" :disabled="buyCredits.processing" @click="buy">
                        <Plus aria-hidden="true" /> Buy $5 of credits <span class="text-xs text-muted-foreground">(demo, no payment)</span>
                    </Button>
                </section>

                <Link :href="route('generations.index')" class="block text-center text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline">
                    See your generations →
                </Link>
            </aside>
        </form>
    </AppLayout>
</template>
