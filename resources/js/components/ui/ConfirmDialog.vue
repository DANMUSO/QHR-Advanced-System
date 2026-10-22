<script setup>
import { ref, watch } from 'vue';
import Modal from './Modal.vue';
import Spinner from './Spinner.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: String,
    message: String,
    confirmLabel: { type: String, default: 'Confirm' },
    confirmClass: String,
    danger: Boolean,
    reasonLabel: String,
    reasonRequired: { type: Boolean, default: true },
    loading: Boolean,
    error: String,
});
const emit = defineEmits(['confirm', 'close', 'update:open']);
const reason = ref('');

watch(() => props.open, (v) => v && (reason.value = ''));

function close() {
    emit('close');
    emit('update:open', false);
}
</script>

<template>
    <Modal :open="open" :title="title" size="sm" @close="close">
        <p v-if="message" class="text-sm text-slate-600 dark:text-slate-400">{{ message }}</p>
        <slot />
        <div v-if="reasonLabel" class="mt-4">
            <label class="label">{{ reasonLabel }}</label>
            <textarea v-model="reason" rows="3" class="input" />
        </div>
        <p v-if="error" class="field-error">{{ error }}</p>
        <template #footer>
            <button class="btn-secondary" @click="close">Cancel</button>
            <button
                :class="confirmClass || (danger ? 'btn-danger' : 'btn-primary')"
                :disabled="loading || (reasonLabel && reasonRequired && !reason.trim())"
                @click="emit('confirm', reason)"
            >
                <Spinner v-if="loading" /> {{ confirmLabel }}
            </button>
        </template>
    </Modal>
</template>
