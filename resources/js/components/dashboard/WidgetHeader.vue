<script setup>
defineProps({
    title:     String,
    subtitle:  String,
    icon:      Object,
    iconClass: { type: String, default: 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' },
    to:        String,
    linkLabel: { type: String, default: 'View all' },
});
</script>

<template>
    <div class="flex items-center justify-between gap-3 px-5 py-4">
        <div class="flex items-center gap-3">
            <span v-if="icon" class="flex size-8 shrink-0 items-center justify-center rounded-lg" :class="iconClass">
                <component :is="icon" class="size-4" />
            </span>
            <div>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ title }}</h3>
                <p v-if="subtitle" class="text-xs text-slate-500">{{ subtitle }}</p>
            </div>
        </div>
        <slot name="actions">
            <RouterLink v-if="to" :to="to"
                class="shrink-0 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                {{ linkLabel }} →
            </RouterLink>
        </slot>
    </div>
    <div class="border-t border-slate-100 dark:border-slate-800" />
</template>
