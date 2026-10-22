<script setup>
import { computed, useSlots, Text } from 'vue';
import { label, isRawValue } from '@/lib/format';

/**
 * Status pill. Accepts `tone` or its alias `color` (both used across modules).
 * Plain-text content that is a raw code (`pending_approval`, `mpesa`) is shown via label().
 */
const props = defineProps({
    tone: { type: String, default: null },
    color: { type: String, default: null },
});

const tones = {
    slate: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
    brand: 'bg-brand-50 text-brand-700 ring-brand-200 dark:bg-brand-500/15 dark:text-brand-300 dark:ring-brand-500/30',
    blue: 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/15 dark:text-blue-300 dark:ring-blue-500/30',
    sky: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/30',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30',
    amber: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30',
    orange: 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-500/30',
    red: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/15 dark:text-rose-300 dark:ring-rose-500/30',
    purple: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/15 dark:text-violet-300 dark:ring-violet-500/30',
};
const slots = useSlots();

/** The slot text when it is a single raw code, else null (custom markup renders untouched). */
function rawText() {
    const nodes = slots.default?.() ?? [];
    if (!nodes.length || nodes.some((n) => n.type !== Text)) return null;
    const text = nodes.map((n) => n.children).join('').trim();
    return isRawValue(text) ? text : null;
}

const aliases = { gray: 'slate', grey: 'slate', emerald: 'green', yellow: 'amber', rose: 'red', violet: 'purple', indigo: 'brand', teal: 'green', cyan: 'sky' };

const cls = computed(() => {
    const name = props.tone ?? props.color ?? 'slate';
    return tones[aliases[name] ?? name] ?? tones.slate;
});
</script>

<template>
    <span v-if="rawText() !== null" class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset" :class="cls">{{ label(rawText()) }}</span>
    <span v-else class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset" :class="cls">
        <slot />
    </span>
</template>
