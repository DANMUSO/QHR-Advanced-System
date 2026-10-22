import { CalendarDays, CalendarRange, BarChart2, SlidersHorizontal, Plane } from 'lucide-vue-next';

export default [
    { section: 'Self-Service', label: 'My Leave', to: '/leave', icon: Plane, can: 'leave.apply', order: 12, hasEmployee: true },
    { section: 'Leave & Attendance', label: 'Leave', to: '/leave/manage', icon: CalendarDays, can: ['leave.approve', 'leave.manage'], order: 30 },
    { section: 'Leave & Attendance', label: 'Leave Planner', to: '/leave/planner', icon: CalendarRange, can: ['leave.approve', 'leave.manage'], order: 31 },
    { section: 'Leave & Attendance', label: 'Leave Types', to: '/leave/types', icon: SlidersHorizontal, can: 'leave.manage', order: 33 },
    { section: 'Leave & Attendance', label: 'Reports', to: '/leave/reports', icon: BarChart2, can: 'leave.reports', order: 35 },
];
