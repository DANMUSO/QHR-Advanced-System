<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
    open: Boolean,
    title: String,
    size: { type: String, default: 'md' }, // sm | md | lg | xl
    dismissible: { type: Boolean, default: true },
    // Ask before Esc / backdrop click throws away what the user typed in the dialog.
    confirmDiscard: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);
const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };

/** Open modals, newest last: Esc only reaches the top one (a dialog opened from a dialog). */
const stack = (window.__hrModalStack ??= []);
const id = Symbol('modal');
const dirty = ref(false);
const askDiscard = ref(false);

watch(() => props.open, (open) => {
    const i = stack.indexOf(id);
    if (i !== -1) stack.splice(i, 1);
    if (open) stack.push(id);
    dirty.value = false;
    askDiscard.value = false;
}, { immediate: true });

function markDirty(e) {
    if (e.target?.closest?.('[data-modal-ignore-dirty]')) return;
    dirty.value = true;
}

/** Esc / backdrop: close straight away when nothing was typed, otherwise ask first. */
function requestClose() {
    if (!props.dismissible) return;
    if (dirty.value && props.confirmDiscard) {
        askDiscard.value = true;
        return;
    }
    emit('close');
}

function onKey(e) {
    if (e.key !== 'Escape' || !props.open || stack[stack.length - 1] !== id) return;
    // A dropdown inside the dialog (LookupSelect, combobox, open menu) handles Esc itself first.
    if (e.defaultPrevented || e.target?.closest?.('[aria-expanded="true"]')) return;
    if (askDiscard.value) {
        askDiscard.value = false;
        return;
    }
    requestClose();
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    const i = stack.indexOf(id);
    if (i !== -1) stack.splice(i, 1);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
                <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="requestClose" />
                <div class="card relative max-h-[90vh] w-full overflow-y-auto p-6 shadow-xl" :class="widths[size]" role="dialog" aria-modal="true" @input="markDirty" @change="markDirty">
                    <div v-if="title || dismissible" class="mb-4 flex items-start justify-between gap-4">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ title }}</h2>
                        <button v-if="dismissible" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" @click="emit('close')" aria-label="Close">
                            <X class="size-5" />
                        </button>
                    </div>
                    <div v-if="askDiscard" class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200" role="alertdialog" aria-label="Discard changes?">
                        <span class="flex-1">Discard the changes you made in this form?</span>
                        <button type="button" class="btn-secondary !py-1.5" @click="askDiscard = false">Keep editing</button>
                        <button type="button" class="btn-danger !py-1.5" @click="emit('close')">Discard</button>
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
