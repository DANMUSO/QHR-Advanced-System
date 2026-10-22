import { Heart, Receipt, Wallet, BarChart2 } from 'lucide-vue-next';

export default [
    { section: 'Finance', label: 'Benefits & Pension',  to: '/benefits',           icon: Heart,     can: ['settings.manage', 'organization.manage'], order: 10 },
    { section: 'Finance', label: 'Expense Approvals',   to: '/expenses/approvals', icon: Receipt,   can: ['leave.approve', 'claims.approve', 'payroll.manage'], order: 20 },
    { section: 'Finance', label: 'Expense Payments',    to: '/expenses/payments',  icon: Wallet,    can: 'claims.pay',      order: 22 },
    { section: 'Finance', label: 'Reports',             to: '/claims/reports',     icon: BarChart2, can: 'claims.reports',  order: 30 },
];
