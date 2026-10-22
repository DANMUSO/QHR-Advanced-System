import { ClipboardCheck, ClipboardList } from 'lucide-vue-next';

export default [
    { section: 'Employees', label: 'Probation', to: '/probation', icon: ClipboardCheck, can: 'probation.manage', order: 15 },
    { section: 'Team', label: 'Probation reviews', to: '/team/probation', icon: ClipboardList, can: ['performance.manage-team', 'probation.manage'], order: 40 },
];
