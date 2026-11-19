<script setup lang="ts" generic="T extends string">
// Accessible "pick one" control: real radio inputs (keyboard arrows work), styled as cards.
const model = defineModel<T>({ required: true });

defineProps<{
    name: string;
    legend: string;
    options: { value: T; label: string; description?: string }[];
}>();
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-2 text-sm font-medium">{{ legend }}</legend>
        <div class="grid grid-cols-3 gap-2">
            <label v-for="option in options" :key="option.value" class="cursor-pointer">
                <input v-model="model" type="radio" class="peer sr-only" :name="name" :value="option.value" />
                <span
                    class="flex h-full flex-col items-center gap-1.5 rounded-lg border bg-card px-2 py-3 text-center shadow-card transition-colors hover:border-primary/40 peer-checked:border-primary peer-checked:bg-primary/5 peer-checked:ring-1 peer-checked:ring-primary peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2 peer-focus-visible:ring-offset-background"
                >
                    <slot name="visual" :option="option" :selected="model === option.value" />
                    <span class="text-sm font-medium">{{ option.label }}</span>
                    <span v-if="option.description" class="text-xs text-muted-foreground">{{ option.description }}</span>
                </span>
            </label>
        </div>
    </fieldset>
</template>
