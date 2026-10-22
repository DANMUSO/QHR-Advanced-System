import { Scale, HardHat, Lock, BadgeCheck, HeartPulse } from 'lucide-vue-next';

export default [
    // Exit & Clearance → Employee Detail > Lifecycle tab
    // KYC & Verification → Employee Detail > Documents tab
    { section: 'Compliance', label: 'Disciplinary & Grievances', to: '/compliance',                 icon: Scale,   can: 'employees.update', order: 10 },
    { section: 'Compliance', label: 'Health & Safety',           to: '/compliance/hs',              icon: HardHat, can: 'employees.update', order: 20 },
    { section: 'Compliance', label: 'CRB & Good Conduct',        to: '/compliance/crb',             icon: BadgeCheck, can: 'employees.update', order: 22 },
    { section: 'Compliance', label: 'Medical & Insurance',       to: '/compliance/medical',         icon: HeartPulse, can: 'employees.update', order: 24 },
    { section: 'Compliance', label: 'Data Protection',           to: '/compliance/data-protection', icon: Lock,    can: 'settings.manage',  order: 30 },
];
