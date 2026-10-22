<script setup>
import { ref } from 'vue';
import { Eye, EyeOff, Lock } from 'lucide-vue-next';

defineOptions({ inheritAttrs: false });
const model = defineModel({ type: String, default: '' });
defineProps({ error: String, id: String, autocomplete: { type: String, default: 'current-password' } });

const visible = ref(false);
const capsLock = ref(false);

function checkCaps(e) {
    capsLock.value = e.getModifierState?.('CapsLock') ?? false;
}
</script>

<template>
    <div>
        <div class="relative">
            <Lock class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <input
                :id="id"
                v-model="model"
                v-bind="$attrs"
                :type="visible ? 'text' : 'password'"
                :autocomplete="autocomplete"
                class="input pl-10 pr-11"
                :class="error && 'border-rose-400 focus:border-rose-500 focus:ring-rose-500/15'"
                @keydown="checkCaps"
                @keyup="checkCaps"
                @blur="capsLock = false"
            />
            <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200"
                :aria-label="visible ? 'Hide password' : 'Show password'"
                @click="visible = !visible"
            >
                <EyeOff v-if="visible" class="size-4" />
                <Eye v-else class="size-4" />
            </button>
        </div>
        <p v-if="capsLock" class="mt-1.5 text-xs font-medium text-amber-600">Caps Lock is on</p>
        <p v-if="error" class="field-error">{{ error }}</p>
    </div>
</template>
