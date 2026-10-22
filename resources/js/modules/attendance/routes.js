const toManage = (tab) => (to) => ({ path: '/attendance/manage', query: { ...to.query, tab } });

export default [
    // Self-service: punches and weekly timesheets (?tab=timesheets)
    { path: 'attendance', name: 'attendance.mine', component: () => import('./pages/MyTime.vue'), meta: { title: 'My Attendance' } },
    // Supervisors (their team: register, overtime, timesheets) and HR (company, plus shifts and geo-fences)
    { path: 'attendance/manage', name: 'attendance.manage', component: () => import('./pages/AttendanceManagement.vue'), meta: { title: 'Attendance Management', can: ['attendance.view-team', 'attendance.manage'] } },

    // Short links
    { path: 'attendance/my', redirect: '/attendance' },
    { path: 'attendance/timesheets', redirect: (to) => ({ path: '/attendance', query: { ...to.query, tab: 'timesheets' } }) },
    { path: 'attendance/report', redirect: toManage('report') },
    { path: 'attendance/overtime', redirect: toManage('overtime') },
    { path: 'attendance/timesheet-approvals', redirect: toManage('timesheets') },
    { path: 'attendance/shifts', redirect: toManage('shifts') },
    { path: 'attendance/geofencing', redirect: toManage('geofencing') },
    { path: 'attendance/devices', redirect: toManage('devices') },
];
