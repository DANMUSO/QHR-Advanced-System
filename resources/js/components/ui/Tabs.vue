<script setup>
/** tabs: [{ key, label, count?, icon? }] */
defineProps({ tabs: { type: Array, required: true } });
const model = defineModel({ type: String });
</script>

<template>
    <div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200 dark:border-slate-800">
        <button
            v-for="t in tabs"
            :key="t.key"
            class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition"
            :class="model === t.key ? 'border-brand-600 text-brand-700 dark:text-brand-300' : 'border-transparent text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'"
            @click="model = t.key"
        >
            <component :is="t.icon" v-if="t.icon" class="size-4" />
            {{ t.label }}
            <span v-if="t.count" class="rounded-full bg-slate-100 px-1.5 text-xs dark:bg-slate-800">{{ t.count }}</span>
        </button>
    </div>
</template>
