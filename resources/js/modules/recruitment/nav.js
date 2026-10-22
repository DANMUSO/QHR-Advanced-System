import { Users, BarChart2 } from 'lucide-vue-next';

export default [
    { section: 'Recruitment', label: 'Pipeline', to: '/recruitment',         icon: Users,     can: 'employees.create', order: 10 },
    { section: 'Recruitment', label: 'Reports',  to: '/recruitment/reports', icon: BarChart2, can: 'employees.view',   order: 20 },
];
