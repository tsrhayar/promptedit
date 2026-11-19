<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { formatUsd } from '@/lib/pricing';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { ChevronDown, LoaderCircle, Plus, Wallet } from 'lucide-vue-next';
import { computed } from 'vue';

// Always-visible credit balance (top bar) + the one place to buy credits.
const page = usePage<SharedData>();
const billing = computed(() => page.props.billing);

// Must match CreditController::PACKS_USD.
const packs = [5, 10, 25];

const buyForm = useForm({ amount: 5 });
const buy = (amount: number) => {
    buyForm.amount = amount;
    buyForm.post(route('credits.store'), { preserveScroll: true });
};
</script>

<template>
    <div v-if="billing" class="flex items-center gap-2">
        <Tooltip>
            <TooltipTrigger as-child>
                <div class="flex h-8 items-center gap-1.5 rounded-full border bg-card px-3 text-sm" tabindex="0">
                    <Wallet class="size-4 text-muted-foreground" aria-hidden="true" />
                    <span class="sr-only">Credit balance:</span>
                    <span class="money">{{ formatUsd(billing.balance, 4) }}</span>
                </div>
            </TooltipTrigger>
            <TooltipContent>Exact balance: <span class="money">{{ formatUsd(billing.balance, 9) }}</span></TooltipContent>
        </Tooltip>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button size="sm" variant="outline" class="rounded-full" :disabled="buyForm.processing">
                    <LoaderCircle v-if="buyForm.processing" class="animate-spin" aria-hidden="true" />
                    <Plus v-else aria-hidden="true" />
                    Buy credits
                    <ChevronDown class="size-3 opacity-60" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuLabel class="font-normal">
                    <div class="text-sm font-medium">Add credits</div>
                    <div class="text-xs text-muted-foreground">Simulated payment, no card required.</div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem v-for="usd in packs" :key="usd" @select="buy(usd)">
                    <span>${{ usd }}.00 of credits</span>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>