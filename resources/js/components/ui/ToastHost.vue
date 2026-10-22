<script setup>
import { CheckCircle2, AlertTriangle, XCircle, Info, X } from 'lucide-vue-next';
import { useUiStore } from '@/stores/ui';

const ui = useUiStore();
const icons = { success: CheckCircle2, error: XCircle, warning: AlertTriangle, info: Info };
const tones = {
    success: 'text-emerald-500',
    error: 'text-rose-500',
    warning: 'text-amber-500',
    info: 'text-sky-500',
};
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-4 z-[100] flex flex-col items-center gap-2 px-4 sm:items-end sm:pr-6">
        <TransitionGroup name="slide-up">
            <div
                v-for="t in ui.toasts"
                :key="t.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5 shadow-lg dark:border-slate-700 dark:bg-slate-900"
                role="status"
            >
                <component :is="icons[t.type] ?? Info" class="mt-0.5 size-5 shrink-0" :class="tones[t.type]" />
                <p class="flex-1 text-sm text-slate-700 dark:text-slate-200">{{ t.message }}</p>
                <button class="text-slate-400 hover:text-slate-600" @click="ui.dismiss(t.id)" aria-label="Dismiss">
                    <X class="size-4" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
