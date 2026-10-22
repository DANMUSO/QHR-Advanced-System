<script setup>
import { computed } from 'vue';
import { CalendarHeart, Clock3, UserRoundPen, Briefcase } from 'lucide-vue-next';
import { useAuthStore } from '@/stores/auth';
import { timeAgo } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';

const props = defineProps({ data: Object });
const auth  = useAuthStore();

const today = computed(() =>
    new Date(props.data.today).toLocaleDateString('en-KE', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    })
);
const daysToHoliday = computed(() => {
    if (!props.data.next_holiday) return null;
    return Math.round((new Date(props.data.next_holiday.date) - new Date(props.data.today)) / 86400000);
});
</script>

<template>
    <!-- ADMIN: horizontal greeting bar -->
    <div v-if="data.is_admin" class="flex flex-wrap items-center justify-between gap-4 px-6 py-5">
        <div class="flex items-center gap-4">
            <Avatar :name="auth.user?.name" :src="auth.user?.employee?.photo_url" size="md"
                class="ring-2 ring-brand-200 dark:ring-brand-500/30" />
            <div>
                <p class="text-xs font-medium uppercase tracking-widest text-slate-400">{{ today }}</p>
                <h2 class="mt-0.5 text-xl font-bold text-slate-900 dark:text-white">
                    {{ data.greeting }}, {{ data.first_name }} 👋
                </h2>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div v-if="data.next_holiday"
                class="flex items-center gap-2.5 rounded-xl bg-amber-50 px-3.5 py-2 dark:bg-amber-500/10">
                <CalendarHeart class="size-4 shrink-0 text-amber-500" />
                <div class="leading-tight">
                    <p class="text-[11px] text-amber-600/70 dark:text-amber-400/70">Next holiday</p>
                    <p class="text-sm font-semibold text-amber-700 dark:text-amber-300">
                        {{ daysToHoliday === 0 ? 'Today' : `${daysToHoliday} days` }}
                        <span class="font-normal">· {{ data.next_holiday.name }}</span>
                    </p>
                </div>
            </div>

            <div v-if="data.last_login_at"
                class="flex items-center gap-2.5 rounded-xl bg-slate-100 px-3.5 py-2 dark:bg-slate-800">
                <Clock3 class="size-4 shrink-0 text-slate-500" />
                <div class="leading-tight">
                    <p class="text-[11px] text-slate-400">Last sign-in</p>
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ timeAgo(data.last_login_at) }}</p>
                </div>
            </div>

            <RouterLink v-if="data.profile_completeness !== null" to="/profile"
                class="flex items-center gap-2.5 rounded-xl bg-slate-100 px-3.5 py-2 transition hover:bg-brand-50 dark:bg-slate-800 dark:hover:bg-brand-500/10">
                <UserRoundPen class="size-4 shrink-0 text-slate-500" />
                <div class="leading-tight">
                    <p class="text-[11px] text-slate-400">Profile</p>
                    <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                        {{ data.profile_completeness }}% complete
                    </p>
                </div>
            </RouterLink>
        </div>
    </div>

    <!-- STAFF: clean hero, no gradient -->
    <div v-else class="bg-slate-900 p-6 sm:p-8 dark:bg-slate-800">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-4">
                <Avatar :name="auth.user?.name" :src="auth.user?.employee?.photo_url" size="lg"
                    class="ring-4 ring-white/10" />
                <div>
                    <p class="text-sm text-slate-400">{{ today }}</p>
                    <h2 class="mt-0.5 text-2xl font-bold text-white">
                        {{ data.greeting }}, {{ data.first_name }} 👋
                    </h2>
                    <p v-if="data.position" class="mt-1 text-sm text-slate-400">
                        {{ data.position }}<span v-if="data.department"> · {{ data.department }}</span>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <div v-if="data.years_of_service !== null" class="rounded-xl bg-white/5 px-4 py-3">
                    <Briefcase class="mb-1.5 size-4 text-slate-400" />
                    <p class="text-xl font-bold text-white">{{ data.years_of_service }} yrs</p>
                    <p class="text-xs text-slate-400">Service</p>
                </div>
                <div v-if="data.next_holiday" class="rounded-xl bg-white/5 px-4 py-3">
                    <CalendarHeart class="mb-1.5 size-4 text-slate-400" />
                    <p class="text-xl font-bold text-white">
                        {{ daysToHoliday === 0 ? 'Today' : `${daysToHoliday}d` }}
                    </p>
                    <p class="truncate text-xs text-slate-400">to {{ data.next_holiday.name }}</p>
                </div>
                <div v-if="data.last_login_at" class="rounded-xl bg-white/5 px-4 py-3">
                    <Clock3 class="mb-1.5 size-4 text-slate-400" />
                    <p class="text-xl font-bold text-white">{{ timeAgo(data.last_login_at) }}</p>
                    <p class="text-xs text-slate-400">Last sign-in</p>
                </div>
                <RouterLink v-if="data.profile_completeness !== null" to="/profile"
                    class="rounded-xl bg-white/5 px-4 py-3 transition hover:bg-white/10">
                    <UserRoundPen class="mb-1.5 size-4 text-slate-400" />
                    <p class="text-xl font-bold text-white">{{ data.profile_completeness }}%</p>
                    <div class="mt-1.5 h-1 rounded-full bg-white/10">
                        <div class="h-1 rounded-full bg-brand-400 transition-all duration-700"
                            :style="{ width: data.profile_completeness + '%' }" />
                    </div>
                    <p class="mt-1 text-xs text-slate-400">Profile</p>
                </RouterLink>
            </div>
        </div>
    </div>
</template>
