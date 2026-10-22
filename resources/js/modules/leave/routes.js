// The /leave/manage tabs (approvals, on-leave, planner, recall, balances, admin) are deep-linked with ?tab=.
const toManage = (tab) => (to) => ({ path: '/leave/manage', query: { ...to.query, tab } });

export default [
    // Employee self-service: requests, balances and the handover inbox (?tab=handovers)
    { path: 'leave', name: 'leave', component: () => import('./pages/MyLeave.vue'), meta: { title: 'My Leave', can: 'leave.apply' } },
    { path: 'leave/apply', redirect: '/leave' },
    { path: 'leave/handovers', redirect: (to) => ({ path: '/leave', query: { ...to.query, tab: 'handovers' } }) },

    // Supervisors (their team) and leave administrators (company)
    { path: 'leave/manage', name: 'leave.manage', component: () => import('./pages/LeaveManagement.vue'), meta: { title: 'Leave Management', can: ['leave.approve', 'leave.manage'] } },
    { path: 'leave/types', name: 'leave.types', component: () => import('./pages/LeaveTypes.vue'), meta: { title: 'Leave Types', can: 'leave.manage' } },
    { path: 'leave/reports', name: 'leave.reports', component: () => import('./pages/LeaveReports.vue'), meta: { title: 'Leave Reports', can: 'leave.reports' } },

    // Short links into the management tabs
    { path: 'leave/approvals', redirect: toManage('approvals') },
    { path: 'leave/on-leave', redirect: toManage('on-leave') },
    { path: 'leave/planner', redirect: toManage('planner') },
    { path: 'leave/recall', redirect: toManage('recall') },
    { path: 'leave/balances', redirect: toManage('balances') },
    { path: 'leave/admin', redirect: (to) => ({ path: '/leave/manage', query: { ...to.query, tab: to.query.tab || 'admin' } }) },
];
