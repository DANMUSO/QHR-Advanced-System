<script setup>
import { ref, watch, onMounted } from 'vue';

const props = defineProps({ length: { type: Number, default: 6 }, error: Boolean, autofocus: Boolean });
const model = defineModel({ type: String, default: '' });
const emit = defineEmits(['complete']);

const digits = ref(Array.from({ length: props.length }, (_, i) => model.value[i] ?? ''));
const inputs = ref([]);

watch(model, (v) => {
    if (v === '') digits.value = digits.value.map(() => '');
});

function sync() {
    model.value = digits.value.join('');
    if (model.value.length === props.length) emit('complete', model.value);
}

function onInput(i, e) {
    const value = e.target.value.replace(/\D/g, '');
    if (value.length > 1) return fill(value, i);
    digits.value[i] = value;
    sync();
    if (value && i < props.length - 1) inputs.value[i + 1]?.focus();
}

function onKeydown(i, e) {
    if (e.key === 'Backspace' && !digits.value[i] && i > 0) inputs.value[i - 1]?.focus();
    if (e.key === 'ArrowLeft' && i > 0) inputs.value[i - 1]?.focus();
    if (e.key === 'ArrowRight' && i < props.length - 1) inputs.value[i + 1]?.focus();
}

function fill(text, start = 0) {
    const chars = text.replace(/\D/g, '').slice(0, props.length - start).split('');
    chars.forEach((c, k) => (digits.value[start + k] = c));
    sync();
    inputs.value[Math.min(start + chars.length, props.length - 1)]?.focus();
}

function onPaste(e) {
    e.preventDefault();
    fill(e.clipboardData.getData('text'));
}

onMounted(() => props.autofocus && inputs.value[0]?.focus());
</script>

<template>
    <div class="flex justify-center gap-2 sm:gap-3" @paste="onPaste">
        <input
            v-for="(d, i) in digits"
            :key="i"
            :ref="(el) => (inputs[i] = el)"
            :value="d"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            class="size-12 rounded-xl border bg-white text-center text-xl font-semibold text-slate-900 shadow-sm outline-none transition focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:size-14 dark:bg-slate-900 dark:text-white"
            :class="error ? 'border-rose-400' : 'border-slate-300 dark:border-slate-700'"
            :aria-label="`Digit ${i + 1}`"
            @input="onInput(i, $event)"
            @keydown="onKeydown(i, $event)"
            @focus="$event.target.select()"
        />
    </div>
</template>
