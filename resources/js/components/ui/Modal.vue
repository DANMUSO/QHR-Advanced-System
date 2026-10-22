<script setup>
import { onMounted, onBeforeUnmount } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
    open: Boolean,
    title: String,
    size: { type: String, default: 'md' }, // sm | md | lg | xl
    dismissible: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);
const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };

function onKey(e) {
    if (e.key === 'Escape' && props.open && props.dismissible) emit('close');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
                <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="dismissible && emit('close')" />
                <div class="card relative max-h-[90vh] w-full overflow-y-auto p-6 shadow-xl" :class="widths[size]" role="dialog" aria-modal="true">
                    <div v-if="title || dismissible" class="mb-4 flex items-start justify-between gap-4">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ title }}</h2>
                        <button v-if="dismissible" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" @click="emit('close')" aria-label="Close">
                            <X class="size-5" />
                        </button>
                    </div>
                    <slot />
                    <div v-if="$slots.footer" class="mt-6 flex flex-wrap justify-end gap-2">
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
