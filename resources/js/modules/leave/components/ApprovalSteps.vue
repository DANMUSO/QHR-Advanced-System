<script setup>
/** Configurable approval chain progress: [{ label, state: approved|current|rejected|waiting }]. */
import { Check, X, Clock } from 'lucide-vue-next';

defineProps({ steps: { type: Array, required: true } });

const styles = {
    approved: 'bg-green-100 text-green-700 dark:bg-green-500/15 dark:text-green-300',
    current: 'bg-blue-100 text-blue-700 ring-1 ring-blue-300 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/40',
    rejected: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    waiting: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
};
</script>

<template>
    <ol class="flex flex-wrap items-center gap-1.5 text-xs">
        <li v-for="(s, i) in steps" :key="i" class="flex items-center gap-1.5">
            <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium" :class="styles[s.state] ?? styles.waiting">
                <Check v-if="s.state === 'approved'" class="size-3" />
                <X v-else-if="s.state === 'rejected'" class="size-3" />
                <Clock v-else-if="s.state === 'current'" class="size-3" />
                {{ s.label }}
            </span>
            <span v-if="i < steps.length - 1" class="text-slate-300 dark:text-slate-600">→</span>
        </li>
    </ol>
</template>
