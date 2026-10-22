<script setup>
import { Building2 } from 'lucide-vue-next';
import { useCompanyContext } from '../company';

/** Company switcher for super admins without a global company scope. Emits `change` after switching. */
const emit = defineEmits(['change']);
const { picked, needsPicker, companies } = useCompanyContext();

function onChange(e) {
    picked.value = e.target.value;
    emit('change', picked.value);
}
</script>

<template>
    <label v-if="needsPicker" class="flex items-center gap-2 text-sm text-slate-500">
        <Building2 class="size-4 shrink-0" />
        <span class="hidden sm:inline">Company</span>
        <select :value="picked" class="input !w-56 !py-2" @change="onChange">
            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
        </select>
    </label>
</template>
