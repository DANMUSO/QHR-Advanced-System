<script setup>
defineProps({ icon: Object, title: String, description: String });
</script>

<template>
    <div class="flex flex-col items-center justify-center px-4 py-8 text-center">
        <div v-if="icon" class="mb-3 rounded-full bg-slate-100 p-3 text-slate-400 dark:bg-slate-800">
            <component :is="icon" class="size-6" />
        </div>
        <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-xs text-sm text-slate-500">{{ description }}</p>
        <div v-if="$slots.default" class="mt-4"><slot /></div>
    </div>
</template>
