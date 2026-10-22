<script setup>
import { ref, onMounted } from 'vue';
import api from '@/lib/api';
import { formatDate } from '@/lib/format';
import Avatar from '@/components/ui/Avatar.vue';
import Badge from '@/components/ui/Badge.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import Spinner from '@/components/ui/Spinner.vue';
import ProbationReviewCard from '../components/ProbationReviewCard.vue';

// Probation review stages waiting for me (my reports, escalations, or a role-based stage of the workflow).
const reviews = ref([]);
const loading = ref(true);
const openId = ref(null);

async function load() {
    loading.value = true;
    try {
        const { data } = await api.get('/probation/my-reviews');
        reviews.value = data.data ?? [];
        if (!reviews.value.some((r) => r.id === openId.value)) openId.value = reviews.value[0]?.id ?? null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div>
        <PageHeader title="Probation reviews" subtitle="Reviews waiting for your comment. Stages run in order; HR makes the final decision." />
        <div v-if="loading" class="flex justify-center py-20"><Spinner /></div>
        <EmptyState v-else-if="!reviews.length" title="Nothing to review" description="You have no probation reviews waiting for you." />
        <div v-else class="space-y-3">
            <div v-for="r in reviews" :key="r.id" class="card">
                <button class="flex w-full flex-wrap items-center gap-3 p-4 text-left" @click="openId = openId === r.id ? null : r.id">
                    <Avatar :src="r.photo_url" :name="r.employee_name" size="md" />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-900 dark:text-white">{{ r.employee_name }}</p>
                        <p class="text-xs text-slate-500">{{ r.employee_number }} · ends {{ formatDate(r.probation_end_date) }}</p>
                    </div>
                    <Badge color="blue">{{ r.current_stage }}</Badge>
                    <Badge v-if="!r.routed_to_me" color="slate">Escalated / role</Badge>
                </button>
                <div v-if="openId === r.id" class="border-t border-slate-100 p-4 dark:border-slate-800">
                    <ProbationReviewCard :review="r" @updated="load" />
                </div>
            </div>
        </div>
    </div>
</template>
