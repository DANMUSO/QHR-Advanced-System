import { LayoutDashboard, ShieldCheck, ScrollText, Megaphone } from 'lucide-vue-next';

/**
 * HR Advanced — Master Navigation
 * ─────────────────────────────────────────────────────────────────────────────
 * Sections in sidebar order:
 *
 *   (top)              Dashboard
 *   Self-Service       My Portal (all employee self-service) + My Time
 *   Employees          Directory, org chart, lifecycle, records
 *   Leave & Attendance Leave management, attendance management
 *   Payroll            Runs, setup, loans, reports
 *   Recruitment        Pipeline, hiring reports
 *   Performance        Performance management, L&D, succession
 *   Compliance         Cases, H&S, exits, verification, data protection
 *   Finance            Benefits, approvals, reports
 *   Analytics          HR dashboard, reports, AI insights
 *   Administration     Companies, structure, users, announcements, settings
 * ─────────────────────────────────────────────────────────────────────────────
 */

const SECTION_ORDER = [
    null,
    'Self-Service',
    'Employees',
    'Leave & Attendance',
    'Payroll',
    'Recruitment',
    'Performance',
    'Compliance',
    'Finance',
    'Analytics',
    'Administration',
];

const core = [
    {
        section: null,
        label:   'Dashboard',
        to:      '/',
        icon:    LayoutDashboard,
        exact:   true,
        order:   0,
    },
    {
        section: 'Administration',
        label:   'Announcements',
        to:      '/announcements',
        icon:    Megaphone,
        can:     'announcements.manage',
        order:   25,
    },
    {
        section: 'Administration',
        label:   'Sign-in Logs',
        to:      '/admin/login-activity',
        icon:    ShieldCheck,
        can:     'audit.view',
        order:   80,
    },
    {
        section: 'Administration',
        label:   'Audit Trail',
        to:      '/admin/audit-log',
        icon:    ScrollText,
        can:     'audit.view',
        order:   90,
    },
];

const moduleItems = Object.values(
    import.meta.glob('./modules/*/nav.js', { eager: true }),
).flatMap((m) => m.default ?? []);

// One menu entry per path: the first definition wins if two modules register the same link.
const seen = new Set();
const items = [...core, ...moduleItems].filter((i) => {
    if (seen.has(i.to)) {
        if (import.meta.env.DEV) console.warn(`Duplicate nav link ignored: ${i.to}`);
        return false;
    }
    seen.add(i.to);
    return true;
});

export const navigation = SECTION_ORDER
    .concat(
        [...new Set(items.map((i) => i.section))].filter(
            (s) => !SECTION_ORDER.includes(s),
        ),
    )
    .map((section) => ({
        section,
        items: items
            .filter((i) => (i.section ?? null) === section)
            .sort((a, b) => (a.order ?? 50) - (b.order ?? 50)),
    }))
    .filter((s) => s.items.length);

export function searchablePages(auth) {
    return items
        .filter((i) => !i.can || auth.can(i.can))
        .concat([{ label: 'Account Security', to: '/account/security', icon: ShieldCheck }]);
}
