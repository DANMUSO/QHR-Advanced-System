import { Globe, Building2, Users, Settings2, LifeBuoy } from 'lucide-vue-next';

// Branding, workflows, reminders, import/export, integrations, API and backups are tabs of Settings.
export default [
    { section: 'Administration', label: 'Companies',     to: '/organization/companies', icon: Globe,     can: 'companies.manage',    order: 10 },
    { section: 'Administration', label: 'Structure',     to: '/organization',           icon: Building2, can: 'organization.manage', order: 11 },
    { section: 'Administration', label: 'Users & Roles', to: '/organization/users',     icon: Users,     can: 'users.manage',        order: 20 },
    { section: 'Administration', label: 'Settings',      to: '/organization/settings',  icon: Settings2, can: 'settings.manage',     order: 30 },
    { section: 'Self-Service',   label: 'Help & Support',to: '/organization/support',   icon: LifeBuoy,                              order: 90 },
];
