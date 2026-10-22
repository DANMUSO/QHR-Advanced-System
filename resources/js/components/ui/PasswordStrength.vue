<script setup>
import { computed } from 'vue';
import { Check, X } from 'lucide-vue-next';

const props = defineProps({ password: { type: String, default: '' } });

// Mirrors the server policy: min 10, upper + lower case, number, symbol.
const rules = computed(() => [
    { label: 'At least 10 characters', ok: props.password.length >= 10 },
    { label: 'Upper and lower case letters', ok: /[a-z]/.test(props.password) && /[A-Z]/.test(props.password) },
    { label: 'A number', ok: /\d/.test(props.password) },
    { label: 'A symbol (e.g. ! @ # ?)', ok: /[^A-Za-z0-9]/.test(props.password) },
]);

const score = computed(() => {
    if (!props.password) return 0;
    let s = rules.value.filter((r) => r.ok).length;
    if (props.password.length >= 14) s += 1;
    return Math.min(s, 5);
});
const meta = computed(() => [
    { label: '', color: 'bg-slate-200 dark:bg-slate-700' },
    { label: 'Very weak', color: 'bg-rose-500' },
    { label: 'Weak', color: 'bg-orange-500' },
    { label: 'Fair', color: 'bg-amber-500' },
    { label: 'Strong', color: 'bg-emerald-500' },
    { label: 'Very strong', color: 'bg-emerald-600' },
][score.value]);
</script>

<template>
    <div class="mt-2 space-y-2">
        <div class="flex items-center gap-2">
            <div class="flex flex-1 gap-1">
                <span v-for="i in 5" :key="i" class="h-1.5 flex-1 rounded-full transition-colors" :class="i <= score ? meta.color : 'bg-slate-200 dark:bg-slate-700'" />
            </div>
            <span class="w-20 text-right text-xs font-medium text-slate-500">{{ meta.label }}</span>
        </div>
        <ul class="grid grid-cols-1 gap-1 sm:grid-cols-2">
            <li v-for="r in rules" :key="r.label" class="flex items-center gap-1.5 text-xs" :class="r.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-500'">
                <Check v-if="r.ok" class="size-3.5" />
                <X v-else class="size-3.5" />
                {{ r.label }}
            </li>
        </ul>
    </div>
</template>
