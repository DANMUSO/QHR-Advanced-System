<script setup>
defineProps({ title: String, subtitle: String, back: String });
</script>

<template>
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <RouterLink v-if="back" :to="back" class="mb-1 inline-block text-sm text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">← Back</RouterLink>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-slate-500">{{ subtitle }}</p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.default || $slots.actions" class="flex flex-wrap items-center gap-2">
            <slot />
            <slot name="actions" />
        </div>
    </div>
</template>
