<script setup>
/** Staff on leave on a date (today by default); supervisors see their team, HR the company. */
import { ref, onMounted } from 'vue';
import { Undo2 } from 'lucide-vue-next';
import api from '@/lib/api';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import Spinner from '@/components/ui/Spinner.vue';

const emit = defineEmits(['recall']);
const date = ref(new Date().toISOString().slice(0, 10));
const rows = ref([]);
const loading = ref(true);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/leave/on-leave', { params: { date: date.value } });
        rows.value = data.data;
    } finally { loading.value = false; }
}

defineExpose({ load });
onMounted(load);
</script>

<template>
    <div>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <label class="text-sm font-medium text-slate-600 dark:text-slate-400" for="on-leave-date">Date</label>
            <input id="on-leave-date" v-model="date" type="date" class="input w-44" @change="load" />
            <span class="text-sm text-slate-500">{{ rows.length }} on leave</span>
        </div>
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <div v-else-if="!rows.length" class="card p-10 text-center text-slate-400">Nobody is on leave on this date.</div>
        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="r in rows" :key="r.id" class="card p-4">
                <div class="flex items-start gap-3">
                    <Avatar :src="r.employee?.photo_url" :name="r.employee?.name" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold text-slate-900 dark:text-white">{{ r.employee?.name }}</p>
                        <p class="text-xs text-slate-500">{{ r.employee?.department }}<span v-if="r.employee?.branch"> · {{ r.employee.branch }}</span></p>
                        <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-300">
                            <span class="size-2 rounded-full" :style="{ background: r.leave_type?.color }" /> {{ r.leave_type?.name }}
                        </p>
                        <p class="text-xs text-slate-400">{{ r.start_date }} – {{ r.end_date }} · {{ r.days }} day(s)</p>
                        <Badge v-if="r.status === 'recalled'" color="purple" class="mt-1">Recalled</Badge>
                    </div>
                    <button v-if="r.can_recall" class="btn-ghost !p-1.5" title="Recall" @click="emit('recall', r)"><Undo2 class="size-4" /></button>
                </div>
            </div>
        </div>
    </div>
</template>
