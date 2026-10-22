<script setup>
import { ShieldCheck, CalendarCheck2, Wallet, LineChart, Sun, Moon, Monitor } from 'lucide-vue-next';
import { useUiStore } from '@/stores/ui';

const ui = useUiStore();
const features = [
    { icon: CalendarCheck2, title: 'Leave & attendance', text: 'Apply, approve and track time off in a few clicks.' },
    { icon: Wallet, title: 'Payroll & claims', text: 'Accurate statutory payroll, payslips and medical claims.' },
    { icon: LineChart, title: 'Performance', text: 'Cascade goals, score KPIs and grow your people.' },
    { icon: ShieldCheck, title: 'Enterprise security', text: 'Two-factor sign-in, session control and a full audit trail.' },
];
const themeIcon = { light: Sun, dark: Moon, system: Monitor };
</script>

<template>
    <div class="flex min-h-full">
        <!-- Brand panel -->
        <aside class="relative hidden w-[46%] overflow-hidden bg-gradient-to-br from-brand-700 via-brand-600 to-violet-600 p-12 text-white lg:flex lg:flex-col">
            <div class="absolute -right-24 -top-24 size-96 rounded-full bg-white/10 blur-3xl" />
            <div class="absolute -bottom-32 -left-16 size-96 rounded-full bg-violet-400/20 blur-3xl" />
            <div class="relative flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-xl bg-white/15 text-lg font-bold backdrop-blur">HR</div>
                <span class="text-lg font-semibold tracking-tight">HR Advanced</span>
            </div>
            <div class="relative my-auto max-w-md">
                <h1 class="text-4xl font-bold leading-tight tracking-tight">Everything your people need, in one secure place.</h1>
                <p class="mt-4 text-base text-white/80">The complete HR platform for growing organisations across Kenya and beyond.</p>
                <ul class="mt-10 space-y-5">
                    <li v-for="f in features" :key="f.title" class="flex gap-4">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/15 backdrop-blur">
                            <component :is="f.icon" class="size-5" />
                        </div>
                        <div>
                            <p class="font-semibold">{{ f.title }}</p>
                            <p class="text-sm text-white/75">{{ f.text }}</p>
                        </div>
                    </li>
                </ul>
            </div>
            <p class="relative text-xs text-white/60">© {{ new Date().getFullYear() }} HR Advanced. All rights reserved.</p>
        </aside>

        <!-- Form panel -->
        <main class="relative flex flex-1 flex-col items-center justify-center px-4 py-12 sm:px-8">
            <button class="btn-ghost absolute right-4 top-4 !p-2" :title="`Theme: ${ui.theme}`" @click="ui.cycleTheme()">
                <component :is="themeIcon[ui.theme]" class="size-5" />
            </button>
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div class="flex size-10 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">HR</div>
                <span class="text-lg font-semibold">HR Advanced</span>
            </div>
            <div class="w-full max-w-md">
                <RouterView v-slot="{ Component }">
                    <Transition name="fade" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </RouterView>
            </div>
        </main>
    </div>
</template>
