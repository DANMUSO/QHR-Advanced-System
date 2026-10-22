import { LayoutDashboard, Users, Network, Inbox, FileSignature } from 'lucide-vue-next';

export default [
    // ── Self-Service ──────────────────────────────────────────────────────────
    { section: 'Self-Service', label: 'My Portal', to: '/portal', icon: LayoutDashboard, order: 5, hasEmployee: true },

    // ── Employees ─────────────────────────────────────────────────────────────
    // Directory is the entry point. New Employee = button on the list.
    // Letters, Lifecycle, Records = tabs on the Employee Detail page.
    { section: 'Employees', label: 'Employees', to: '/employees',           icon: Users,   can: 'employees.view', order: 10 },
    { section: 'Employees', label: 'Org Chart',  to: '/employees/org-chart', icon: Network, can: 'employees.view', order: 11 },
    // Self-service requests from My Portal (profile changes, resignations, letters).
    { section: 'Employees', label: 'Staff Requests', to: '/employees/requests', icon: Inbox, can: 'employees.update', order: 12 },
    // Expiring / expired contracts awaiting renewal or exit.
    { section: 'Employees', label: 'Contracts', to: '/employees/contracts', icon: FileSignature, can: 'employees.view', order: 13 },
];
