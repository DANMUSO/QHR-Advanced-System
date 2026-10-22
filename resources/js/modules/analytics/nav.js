import { LayoutDashboard, BarChart3, Brain } from 'lucide-vue-next';

export default [
    { section: 'Analytics', label: 'HR Dashboard',    to: '/analytics',         icon: LayoutDashboard, can: 'dashboard.hr', order: 10 },
    { section: 'Analytics', label: 'Reports',         to: '/analytics/reports', icon: BarChart3,       can: 'dashboard.hr', order: 20 },
    { section: 'Analytics', label: 'AI Insights',     to: '/analytics/ai',      icon: Brain,           can: 'dashboard.hr', order: 30 },
];
