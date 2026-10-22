<script setup>
import { computed } from 'vue';
import { ChevronDown, ChevronUp, Users } from 'lucide-vue-next';
import Avatar from '@/components/ui/Avatar.vue';

defineOptions({ name: 'OrgChartNode' });

/**
 * One person in the org chart plus (when expanded) their reports.
 * Rendered as <li> inside the chart's <ul> tree so CSS draws the connector lines.
 */
const props = defineProps({
    node: { type: Object, required: true },
    expanded: { type: Set, required: true },
    highlightId: { type: [Number, String], default: null },
    selectedId: { type: [Number, String], default: null },
    matchIds: { type: Set, default: () => new Set() },
    departmentFilter: { type: [Number, String], default: '' },
    colorFor: { type: Function, required: true },
});
const emit = defineEmits(['toggle', 'select']);

const hasChildren = computed(() => props.node.children?.length > 0);
const isOpen = computed(() => props.expanded.has(props.node.id));
const color = computed(() => props.colorFor(props.node.department_id));
const dimmed = computed(() => props.departmentFilter !== '' && String(props.node.department_id) !== String(props.departmentFilter));
</script>

<template>
    <li>
        <div
            :id="`org-node-${node.id}`"
            class="org-card group relative w-56 cursor-pointer rounded-xl border bg-white text-left shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md dark:bg-slate-900"
            :class="[
                String(selectedId) === String(node.id) ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-slate-200 dark:border-slate-700',
                String(highlightId) === String(node.id) && 'org-pulse',
                matchIds.size && !matchIds.has(node.id) ? 'opacity-40' : '',
                dimmed && 'opacity-30 grayscale',
            ]"
            role="button"
            tabindex="0"
            :aria-label="`${node.name}, ${node.position ?? 'no position'}`"
            @click="emit('select', node)"
            @keydown.enter="emit('select', node)"
        >
            <span class="absolute inset-x-0 top-0 h-1 rounded-t-xl" :style="{ background: color }" />
            <div class="flex items-center gap-3 px-3.5 pb-3 pt-4">
                <Avatar :name="node.name" :src="node.photo_url" size="md" class="ring-2 ring-white dark:ring-slate-900" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-900 dark:text-white" :title="node.name">{{ node.name }}</p>
                    <p class="truncate text-xs text-slate-500" :title="node.position">{{ node.position ?? 'No position' }}</p>
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-3.5 py-2 dark:border-slate-800">
                <span class="inline-flex max-w-[9rem] items-center gap-1.5 truncate text-[11px] font-medium text-slate-600 dark:text-slate-400">
                    <span class="size-2 shrink-0 rounded-full" :style="{ background: color }" />
                    <span class="truncate">{{ node.department ?? 'Unassigned' }}</span>
                </span>
                <span v-if="hasChildren" class="inline-flex items-center gap-1 text-[11px] text-slate-400" :title="`${node.children.length} direct report(s)`">
                    <Users class="size-3" /> {{ node.children.length }}
                </span>
            </div>

            <button
                v-if="hasChildren"
                type="button"
                class="no-print absolute -bottom-3 left-1/2 z-10 flex h-6 min-w-6 -translate-x-1/2 items-center justify-center gap-0.5 rounded-full border border-slate-200 bg-white px-1.5 text-[11px] font-semibold text-slate-600 shadow-sm transition hover:border-brand-400 hover:text-brand-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                :aria-label="isOpen ? `Collapse ${node.name}'s team` : `Expand ${node.name}'s team`"
                :aria-expanded="isOpen"
                @click.stop="emit('toggle', node.id)"
            >
                <template v-if="isOpen"><ChevronUp class="size-3.5" /></template>
                <template v-else>+{{ node.total }}<ChevronDown class="size-3.5" /></template>
            </button>
        </div>

        <ul v-if="hasChildren && isOpen">
            <OrgChartNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :expanded="expanded"
                :highlight-id="highlightId"
                :selected-id="selectedId"
                :match-ids="matchIds"
                :department-filter="departmentFilter"
                :color-for="colorFor"
                @toggle="emit('toggle', $event)"
                @select="emit('select', $event)"
            />
        </ul>
    </li>
</template>
