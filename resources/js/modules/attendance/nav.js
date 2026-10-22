import { Clock, ClipboardList } from 'lucide-vue-next';

export default [
    { section: 'Self-Service',      label: 'My Attendance',   to: '/attendance',        icon: Clock,         order: 10, hasEmployee: true },
    { section: 'Leave & Attendance',label: 'Attendance',          to: '/attendance/manage', icon: ClipboardList, can: ['attendance.view-team','attendance.manage'],  order: 40 },
];
