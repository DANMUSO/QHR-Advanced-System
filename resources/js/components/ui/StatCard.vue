<script setup>
defineProps({
    label: String,
    value: [String, Number],
    hint: String,
    icon: Object,
    tone: { type: String, default: 'brand' }, // brand | green | amber | red | sky | violet
});
const tones = {
    brand: 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
    green: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15',
    amber: 'bg-amber-50 text-amber-600 dark:bg-amber-500/15',
    red: 'bg-rose-50 text-rose-600 dark:bg-rose-500/15',
    sky: 'bg-sky-50 text-sky-600 dark:bg-sky-500/15',
    violet: 'bg-violet-50 text-violet-600 dark:bg-violet-500/15',
};
</script>

<template>
    <div class="card flex items-center gap-4 p-4">
        <div v-if="icon" class="rounded-xl p-2.5" :class="tones[tone]"><component :is="icon" class="size-5" /></div>
        <div class="min-w-0">
            <p class="text-xs font-medium text-slate-500">{{ label }}</p>
            <p class="truncate text-xl font-bold tracking-tight text-slate-900 dark:text-white">{{ value }}</p>
            <p v-if="hint" class="truncate text-xs text-slate-400">{{ hint }}</p>
        </div>
    </div>
</template>
