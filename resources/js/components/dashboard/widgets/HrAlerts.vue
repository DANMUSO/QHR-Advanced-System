<script setup>
import { computed, ref } from 'vue';
import { BellRing, Hourglass, FileClock, MailWarning, UserX, UserRoundX, Send, Link2 } from 'lucide-vue-next';
import { formatDate } from '@/lib/format';
import { resendActivation, copyActivationLink } from '@/lib/activation';
import WidgetHeader from '../WidgetHeader.vue';
import Avatar from '@/components/ui/Avatar.vue';

const props = defineProps({ data: Object, title: String });

const counters = computed(() => [
    { label: 'Not activated',  value: props.data.not_activated,     icon: MailWarning, bg: 'bg-amber-50 dark:bg-amber-500/10',  text: 'text-amber-600 dark:text-amber-400', to: props.data.can_manage_users ? '/organization/users?status=not_activated' : '/employees?filter=not_activated' },
    { label: 'No supervisor',  value: props.data.without_supervisor, icon: UserX,       bg: 'bg-rose-50 dark:bg-rose-500/10',    text: 'text-rose-600 dark:text-rose-400',   to: '/employees?filter=no_supervisor' },
    { label: 'No login',       value: props.data.without_account,   icon: UserRoundX,  bg: 'bg-slate-100 dark:bg-slate-800',    text: 'text-slate-600 dark:text-slate-400', to: '/employees?filter=no_account' },
]);

const busy = ref(null);
async function act(fn, e) {
    busy.value = e.user_id;
    await fn({ id: e.user_id });
    busy.value = null;
}

function daysLeft(date) {
    const d = Math.ceil((new Date(date) - new Date()) / 86400000);
    return d < 0 ? `${-d}d overdue` : d === 0 ? 'today' : `in ${d}d`;
}
function urgency(date) {
    const d = Math.ceil((new Date(date) - new Date()) / 86400000);
    return d <= 7 ? 'text-rose-600 font-semibold' : d <= 30 ? 'text-amber-600' : 'text-slate-400';
}
</script>

<template>
    <div class="flex h-full flex-col">
        <WidgetHeader :title="title" :icon="BellRing"
            icon-class="bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400" />

        <div class="grid grid-cols-3 gap-3 p-5">
            <RouterLink v-for="c in counters" :key="c.label" :to="c.to"
                class="flex flex-col items-center rounded-xl p-3 text-center transition hover:opacity-80" :class="c.bg">
                <component :is="c.icon" class="mb-1.5 size-5" :class="c.text" />
                <p class="text-2xl font-bold text-slate-900 dark:text-white">{{ c.value }}</p>
                <p class="mt-0.5 text-[11px] leading-tight text-slate-500">{{ c.label }}</p>
            </RouterLink>
        </div>

        <div class="flex-1 space-y-4 border-t border-slate-100 px-5 py-4 dark:border-slate-800">
            <div v-if="data.not_activated && data.not_activated_items?.length" class="rounded-xl border border-orange-200 bg-orange-50/60 p-3 dark:border-orange-500/30 dark:bg-orange-500/10">
                <p class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-orange-700 dark:text-orange-300">
                    <MailWarning class="size-3.5" /> Not activated: cannot sign in yet ({{ data.not_activated }})
                </p>
                <div v-for="e in data.not_activated_items" :key="e.id" class="flex items-center gap-2.5 py-1.5">
                    <Avatar :name="`${e.first_name} ${e.last_name}`" :src="e.photo_url" size="xs" />
                    <RouterLink :to="`/employees/${e.id}`" class="min-w-0 flex-1 truncate text-sm text-slate-700 hover:underline dark:text-slate-300" :title="e.email">{{ e.first_name }} {{ e.last_name }}</RouterLink>
                    <template v-if="data.can_manage_users">
                        <button class="inline-flex items-center gap-1 text-xs font-medium text-orange-700 hover:underline disabled:opacity-50 dark:text-orange-300" :disabled="busy === e.user_id" @click="act(resendActivation, e)"><Send class="size-3" /> Resend</button>
                        <button v-if="data.can_copy_activation_link" class="inline-flex items-center gap-1 text-xs text-slate-500 hover:underline disabled:opacity-50" :disabled="busy === e.user_id" title="Local development only" @click="act(copyActivationLink, e)"><Link2 class="size-3" /> Link</button>
                    </template>
                </div>
            </div>
            <div v-if="data.contracts_expired?.count">
                <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    <FileClock class="size-3.5" /> Contracts expired: renew or exit ({{ data.contracts_expired.count }})
                </p>
                <RouterLink v-for="e in data.contracts_expired.items" :key="e.reference" :to="`/employees/${e.id}?tab=contracts`"
                    class="flex items-center gap-2.5 py-1.5 hover:opacity-80">
                    <Avatar :name="`${e.first_name} ${e.last_name}`" :src="e.photo_url" size="xs" />
                    <span class="flex-1 truncate text-sm text-slate-700 dark:text-slate-300">{{ e.first_name }} {{ e.last_name }}</span>
                    <span class="text-xs text-rose-600 font-semibold" :title="formatDate(e.end_date)">{{ daysLeft(e.end_date) }}</span>
                </RouterLink>
            </div>
            <div>
                <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-500">
                    <Hourglass class="size-3.5" /> Probations ending ({{ data.probations_ending.count }})
                </p>
                <p v-if="!data.probations_ending.items.length" class="text-xs text-slate-400">None in the next 30 days.</p>
                <div v-for="e in data.probations_ending.items" :key="e.id"
                    class="flex items-center gap-2.5 py-1.5">
                    <Avatar :name="`${e.first_name} ${e.last_name}`" :src="e.photo_url" size="xs" />
                    <span class="flex-1 truncate text-sm text-slate-700 dark:text-slate-300">{{ e.first_name }} {{ e.last_name }}</span>
                    <span class="text-xs" :class="urgency(e.probation_end_date)" :title="formatDate(e.probation_end_date)">
                        {{ daysLeft(e.probation_end_date) }}
                    </span>
                </div>
            </div>
            <div>
                <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-500">
                    <FileClock class="size-3.5" /> Contracts expiring ({{ data.contracts_expiring.count }})
                </p>
                <p v-if="!data.contracts_expiring.items.length" class="text-xs text-slate-400">None in the next 60 days.</p>
                <div v-for="e in data.contracts_expiring.items" :key="e.id"
                    class="flex items-center gap-2.5 py-1.5">
                    <Avatar :name="`${e.first_name} ${e.last_name}`" :src="e.photo_url" size="xs" />
                    <span class="flex-1 truncate text-sm text-slate-700 dark:text-slate-300">{{ e.first_name }} {{ e.last_name }}</span>
                    <span class="text-xs" :class="urgency(e.contract_end_date)" :title="formatDate(e.contract_end_date)">
                        {{ daysLeft(e.contract_end_date) }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
