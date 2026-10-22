import { Target, GraduationCap, GitBranch, TrendingUp } from 'lucide-vue-next';

export default [
    { section: 'Workspace',   label: 'My Performance',       to: '/performance',        icon: TrendingUp,    can: 'performance.participate', order: 40 },
    { section: 'Performance', label: 'Performance Reviews',  to: '/performance/manage', icon: Target,        can: 'performance.manage-team', order: 10 },
    { section: 'Performance', label: 'Learning & Development', to: '/learning',          icon: GraduationCap, can: 'performance.admin',       order: 20 },
    { section: 'Performance', label: 'Succession & Talent',  to: '/succession',          icon: GitBranch,     can: 'performance.admin',       order: 30 },
];
