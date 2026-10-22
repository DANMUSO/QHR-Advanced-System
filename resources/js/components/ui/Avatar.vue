<script setup>
import { computed } from 'vue';
import { initials } from '@/lib/format';

const props = defineProps({
    name: { type: String, default: '' },
    src: { type: String, default: null },
    size: { type: String, default: 'md' }, // xs | sm | md | lg | xl
});

const sizes = { xs: 'size-6 text-[10px]', sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-14 text-base', xl: 'size-20 text-xl' };
const palette = ['bg-indigo-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500', 'bg-fuchsia-500'];
const color = computed(() => palette[[...props.name].reduce((a, c) => a + c.charCodeAt(0), 0) % palette.length]);
</script>

<template>
    <img v-if="src" :src="src" :alt="name" class="shrink-0 rounded-full object-cover" :class="sizes[size]" />
    <span v-else class="inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white" :class="[sizes[size], color]" :title="name">
        {{ initials(name) }}
    </span>
</template>
