<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Link } from '@inertiajs/vue3';
import { CircleDollarSign, Eye, ShieldCheck } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();

// The three promises of the product, shown next to every auth form.
const promises = [
    { icon: Eye, title: 'See the price first', text: 'A live estimate before you click Generate.' },
    { icon: CircleDollarSign, title: 'Pay only for success', text: 'Failed or blocked images always cost $0.' },
    { icon: ShieldCheck, title: 'Exact to the nanodollar', text: 'Every charge matches the tokens actually used.' },
];
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-2">
        <!-- Brand panel (desktop only) -->
        <aside class="relative hidden overflow-hidden bg-[#16141F] p-10 text-white lg:flex lg:flex-col">
            <div
                class="pointer-events-none absolute -right-32 -top-32 size-[28rem] rounded-full bg-[#6C3BEA] opacity-40 blur-3xl"
                aria-hidden="true"
            />
            <div class="pointer-events-none absolute -bottom-40 -left-24 size-[24rem] rounded-full bg-[#A78BFA] opacity-20 blur-3xl" aria-hidden="true" />

            <Link :href="route('home')" class="relative flex items-center gap-3 text-lg font-semibold tracking-tight">
                <AppLogoIcon class="size-9" />
                PromptEdit
            </Link>

            <div class="relative mt-auto max-w-md space-y-8">
                <h2 class="text-3xl font-semibold leading-tight tracking-tight">AI images, priced to the cent.</h2>
                <ul class="space-y-5">
                    <li v-for="item in promises" :key="item.title" class="flex gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-white/10">
                            <component :is="item.icon" class="size-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="font-medium">{{ item.title }}</p>
                            <p class="text-sm text-white/70">{{ item.text }}</p>
                        </div>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Form -->
        <main class="flex flex-col items-center justify-center bg-background p-6 md:p-10">
            <div class="w-full max-w-sm space-y-8">
                <div class="flex flex-col items-center gap-4 text-center lg:items-start lg:text-left">
                    <Link :href="route('home')" class="lg:hidden" aria-label="PromptEdit home">
                        <AppLogoIcon class="size-10" />
                    </Link>
                    <div class="space-y-1.5">
                        <h1 class="text-2xl font-semibold tracking-tight">{{ title }}</h1>
                        <p class="text-sm text-muted-foreground">{{ description }}</p>
                    </div>
                </div>
                <slot />
            </div>
        </main>
    </div>
</template>
