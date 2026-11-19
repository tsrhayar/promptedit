<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatUsd } from '@/lib/pricing';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Minus, TriangleAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Money {
    generations: number;
    charged: string;
    provider_cost: string;
    profit: string;
}
interface Row extends Money {
    day: string;
    email: string;
}
interface Day extends Money {
    day: string;
    pixelforge_cost: string | null;
    pixelforge_generations: number | null;
    matches: boolean | null;
}

const props = defineProps<{ start: string; end: string; rows: Row[]; days: Day[]; totals: Money; usageError: string | null }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];

// ---- Date range ----------------------------------------------------------
const start = ref(props.start);
const end = ref(props.end);
const apply = () => router.get(route('admin.report'), { start: start.value, end: end.value }, { preserveState: true });

const isoDay = (date: Date) => date.toISOString().slice(0, 10); // UTC, like the report
const presets = [
    { label: 'Today', days: 0 },
    { label: 'Last 7 days', days: 6 },
    { label: 'Last 30 days', days: 29 },
];
const usePreset = (days: number) => {
    const today = new Date();
    end.value = isoDay(today);
    start.value = isoDay(new Date(today.getTime() - days * 86_400_000));
    apply();
};

// ---- Display -------------------------------------------------------------
const usd = (value: string | null) => formatUsd(value, 9); // admin sees every digit
const short = (value: string | null) => formatUsd(value, 4);

/** Display only: margin = profit / charged. */
const margin = computed(() => {
    const charged = Number(props.totals.charged);
    return charged > 0 ? `${((Number(props.totals.profit) / charged) * 100).toFixed(1)}%` : '—';
});
const mismatchedDays = computed(() => props.days.filter((d) => d.matches === false).length);

const stats = computed(() => [
    { label: 'Generations billed', value: String(props.totals.generations), exact: null },
    { label: 'Charged to users', value: short(props.totals.charged), exact: usd(props.totals.charged) },
    { label: 'Provider cost', value: short(props.totals.provider_cost), exact: usd(props.totals.provider_cost) },
    { label: `Profit · ${margin.value} margin`, value: short(props.totals.profit), exact: usd(props.totals.profit) },
]);
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Revenue report</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Succeeded generations, grouped by the UTC day they <strong>completed</strong> (the day Pixelforge bills).
                </p>
            </div>

            <!-- Range -->
            <form class="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-4 shadow-card" @submit.prevent="apply">
                <div class="grid gap-1.5"><Label for="start">From (UTC)</Label><Input id="start" v-model="start" type="date" class="w-40" /></div>
                <div class="grid gap-1.5"><Label for="end">To (UTC)</Label><Input id="end" v-model="end" type="date" class="w-40" /></div>
                <Button type="submit">Apply</Button>
                <div class="flex flex-wrap gap-1 sm:ml-auto">
                    <Button v-for="preset in presets" :key="preset.label" type="button" variant="ghost" size="sm" @click="usePreset(preset.days)">
                        {{ preset.label }}
                    </Button>
                </div>
            </form>

            <!-- Totals -->
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label" class="rounded-xl border bg-card p-4 shadow-card">
                    <dt class="text-sm text-muted-foreground">{{ stat.label }}</dt>
                    <dd class="money mt-1 text-2xl font-semibold" :title="stat.exact ?? undefined">{{ stat.value }}</dd>
                </div>
            </dl>

            <p v-if="usageError" role="alert" class="flex gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-warning">
                <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" /> {{ usageError }}
            </p>
            <p
                v-else-if="mismatchedDays > 0"
                role="alert"
                class="flex gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive"
            >
                <CircleX class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                {{ mismatchedDays }} day(s) don't match Pixelforge's usage. Check them below.
            </p>

            <!-- Per day -->
            <section class="space-y-2">
                <h2 class="font-semibold">Per day: our records vs Pixelforge <code class="text-sm">/v1/usage</code></h2>
                <div class="overflow-x-auto rounded-xl border bg-card shadow-card">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-muted/60 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-3 py-2.5 font-medium">Day</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Generations</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Charged</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Provider cost</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Profit</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Pixelforge says</th>
                                <th scope="col" class="px-3 py-2.5 text-center font-medium">Match</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="d in days" :key="d.day" class="hover:bg-muted/40" :class="{ 'bg-destructive/5': d.matches === false }">
                                <th scope="row" class="px-3 py-2 text-left font-medium">{{ d.day }}</th>
                                <td class="px-3 py-2 text-right">{{ d.generations }}</td>
                                <td class="px-3 py-2 text-right">{{ usd(d.charged) }}</td>
                                <td class="px-3 py-2 text-right">{{ usd(d.provider_cost) }}</td>
                                <td class="px-3 py-2 text-right font-medium text-success">{{ usd(d.profit) }}</td>
                                <td class="px-3 py-2 text-right text-muted-foreground">
                                    <template v-if="d.pixelforge_cost !== null">{{ usd(d.pixelforge_cost) }} ({{ d.pixelforge_generations }})</template>
                                    <template v-else>—</template>
                                </td>
                                <td class="px-3 py-2">
                                    <span v-if="d.matches === true" class="flex justify-center text-success" title="Matches Pixelforge">
                                        <CircleCheck class="size-4" aria-hidden="true" /><span class="sr-only">Matches</span>
                                    </span>
                                    <span v-else-if="d.matches === false" class="flex justify-center text-destructive" title="Does not match Pixelforge">
                                        <CircleX class="size-4" aria-hidden="true" /><span class="sr-only">Does not match</span>
                                    </span>
                                    <span v-else class="flex justify-center text-muted-foreground" title="No data from Pixelforge">
                                        <Minus class="size-4" aria-hidden="true" /><span class="sr-only">Unknown</span>
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="days.length === 0">
                                <td colspan="7" class="px-3 py-8 text-center text-muted-foreground">No completed generations in this range.</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t-2 bg-muted/40 font-semibold">
                            <tr>
                                <th scope="row" class="px-3 py-2.5 text-left">Total</th>
                                <td class="px-3 py-2.5 text-right">{{ totals.generations }}</td>
                                <td class="px-3 py-2.5 text-right">{{ usd(totals.charged) }}</td>
                                <td class="px-3 py-2.5 text-right">{{ usd(totals.provider_cost) }}</td>
                                <td class="px-3 py-2.5 text-right text-success">{{ usd(totals.profit) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            <!-- Per day and user -->
            <section class="space-y-2">
                <h2 class="font-semibold">Per day and user</h2>
                <div class="overflow-x-auto rounded-xl border bg-card shadow-card">
                    <table class="w-full text-sm tabular-nums">
                        <thead class="bg-muted/60 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th scope="col" class="px-3 py-2.5 font-medium">Day</th>
                                <th scope="col" class="px-3 py-2.5 font-medium">User</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Generations</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Charged</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Provider cost</th>
                                <th scope="col" class="px-3 py-2.5 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in rows" :key="`${r.day}-${r.email}`" class="hover:bg-muted/40">
                                <td class="px-3 py-2">{{ r.day }}</td>
                                <td class="px-3 py-2">{{ r.email }}</td>
                                <td class="px-3 py-2 text-right">{{ r.generations }}</td>
                                <td class="px-3 py-2 text-right">{{ usd(r.charged) }}</td>
                                <td class="px-3 py-2 text-right">{{ usd(r.provider_cost) }}</td>
                                <td class="px-3 py-2 text-right font-medium text-success">{{ usd(r.profit) }}</td>
                            </tr>
                            <tr v-if="rows.length === 0">
                                <td colspan="6" class="px-3 py-8 text-center text-muted-foreground">No billed generations in this range.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
