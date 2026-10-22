import { Wallet, Settings2, FileBarChart2, FileText } from 'lucide-vue-next';

export default [
    { section: 'Self-Service', label: 'My Payslips', to: '/portal?tab=payslips', icon: FileText, can: 'payroll.view-own', order: 13, hasEmployee: true },
    { section: 'Payroll', label: 'Runs',    to: '/payroll',         icon: Wallet,        can: ['payroll.manage','payroll.review','payroll.approve'],                   order: 10 },
    { section: 'Payroll', label: 'Setup',   to: '/payroll/setup',   icon: Settings2,     can: 'payroll.manage',                    order: 11 },
    { section: 'Payroll', label: 'Reports', to: '/payroll/outputs', icon: FileBarChart2, can: ['payroll.manage','payroll.approve','payroll.reports'], order: 12 },
];
