<script setup>
const props = defineProps({ modelValue: Array, catalogue: Object });
const emit = defineEmits(['update:modelValue']);

function toggle(perm) {
    const current = props.modelValue ?? [];
    const next = current.includes(perm) ? current.filter(p => p !== perm) : [...current, perm];
    emit('update:modelValue', next);
}
</script>

<template>
    <div class="space-y-4">
        <div v-for="(perms, group) in catalogue" :key="group">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">{{ group }}</p>
            <div class="flex flex-wrap gap-2">
                <label v-for="(label, perm) in perms" :key="perm"
                    class="flex cursor-pointer items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs transition"
                    :class="modelValue?.includes(perm) ? 'border-brand-300 bg-brand-50 text-brand-700 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300' : 'border-slate-200 text-slate-600 hover:border-slate-300 dark:border-slate-700 dark:text-slate-400'">
                    <input type="checkbox" class="sr-only" :checked="modelValue?.includes(perm)" @change="toggle(perm)" />
                    {{ label }}
                </label>
            </div>
        </div>
    </div>
</template>
