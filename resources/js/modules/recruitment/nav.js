import { Users, BarChart2 } from 'lucide-vue-next';

// Supervisors (recruitment.requisition) see the pipeline page with only their requisitions.
export default [
    { section: 'Recruitment', label: 'Pipeline', to: '/recruitment',         icon: Users,     can: ['recruitment.manage', 'recruitment.requisition'], order: 10 },
    { section: 'Recruitment', label: 'Reports',  to: '/recruitment/reports', icon: BarChart2, can: ['recruitment.reports', 'recruitment.manage'],     order: 20 },
];
