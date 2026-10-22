import { HeartPulse, Banknote, Settings2, Receipt } from 'lucide-vue-next';

export default [
    { section: 'Self-Service', label: 'My Claims', to: '/claims', icon: Receipt, can: 'claims.submit', order: 14, hasEmployee: true },
    { section: 'Finance', label: 'Claim Approvals', to: '/claims/approvals', icon: HeartPulse, can: 'claims.approve', order: 12 },
    { section: 'Finance', label: 'Claim Payments', to: '/claims/payments', icon: Banknote, can: 'claims.pay', order: 14 },
    { section: 'Finance', label: 'Claim Settings', to: '/claims/settings', icon: Settings2, can: ['settings.manage', 'claims.approve'], order: 16 },
];
