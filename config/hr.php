<?php

/*
|--------------------------------------------------------------------------
| HR permission catalogue & role templates
|--------------------------------------------------------------------------
| Permissions are grouped by module for the role-management screen.
| Role templates replace the legacy "access_by" groups (All / Supervisor / HR / Finance / Super user).
| Run `php artisan hr:sync-permissions` after editing this file.
*/

return [

    // Require two-factor auth for the roles listed in each company's `require_two_factor_roles` setting.
    'enforce_two_factor' => env('HR_ENFORCE_2FA', true),

    'permissions' => [
        'Dashboard' => [
            'dashboard.hr' => 'See HR workforce widgets',
            'dashboard.finance' => 'See finance & payroll widgets',
        ],
        'Organization' => [
            'companies.manage' => 'Create and edit companies',
            'organization.manage' => 'Manage branches, departments, positions, levels, benefits, holidays',
            'settings.manage' => 'Edit company settings and policies',
        ],
        'Employees' => [
            'employees.view' => 'View all employee records',
            'employees.create' => 'Add employees',
            'employees.update' => 'Edit employee records',
            'employees.exit' => 'Exit and re-instate employees',
            'employees.transfer' => 'Transfer employees between companies',
            'employees.view-salary' => 'See salary information',
        ],
        'Recruitment' => [
            'recruitment.requisition' => 'Raise and track own job requisitions',
            'recruitment.manage' => 'Run the recruitment pipeline (approve requisitions, postings, applicants, offers, onboarding)',
            'recruitment.reports' => 'View hiring reports',
        ],
        'Leave' => [
            'leave.apply' => 'Apply for leave',
            'leave.approve' => 'Approve team leave',
            'leave.manage' => 'Manage leave types, balances, recalls',
            'leave.reports' => 'View leave reports',
        ],
        'Medical Claims' => [
            'claims.submit' => 'Submit medical claims',
            'claims.approve' => 'Approve medical claims (HR)',
            'claims.pay' => 'Process claim payments (Finance)',
            'claims.reports' => 'View claim reports',
        ],
        'Attendance' => [
            'attendance.view-own' => 'View own attendance',
            'attendance.view-team' => 'View team attendance',
            'attendance.manage' => 'Manage attendance and reports',
        ],
        'Performance' => [
            'performance.participate' => 'Take part in performance reviews',
            'performance.manage-team' => 'Set and review team KPIs',
            'performance.admin' => 'Configure assessments and view all results',
        ],
        'Payroll' => [
            'payroll.view-own' => 'View own payslips',
            'payroll.manage' => 'Prepare payroll runs and pay data',
            'payroll.review' => 'Review calculated payroll runs (HR check before Finance approval)',
            'payroll.approve' => 'Approve payroll runs',
            'payroll.reports' => 'View payroll and statutory reports',
        ],
        'Documents & Engagement' => [
            'documents.manage' => 'Manage company and employee documents',
            'announcements.manage' => 'Publish announcements',
            'engagement.manage' => 'Respond to staff comments and suggestions',
            'probation.manage' => 'Run probation reviews',
        ],
        'System' => [
            'users.manage' => 'Manage user accounts and roles',
            'users.impersonate' => 'Sign in as another user',
            'audit.view' => 'View the audit trail and sign-in logs',
        ],
    ],

    'roles' => [
        'super-admin' => ['label' => 'Super Admin', 'permissions' => '*'],
        'hr-admin' => [
            'label' => 'HR Administrator',
            'permissions' => [
                'dashboard.hr', 'organization.manage', 'settings.manage',
                'employees.view', 'employees.create', 'employees.update', 'employees.exit', 'employees.transfer', 'employees.view-salary',
                'leave.manage', 'leave.reports', 'claims.approve', 'claims.reports', 'payroll.review',
                'recruitment.requisition', 'recruitment.manage', 'recruitment.reports',
                'attendance.view-team', 'attendance.manage', 'performance.admin', 'performance.manage-team',
                'documents.manage', 'announcements.manage', 'engagement.manage', 'probation.manage',
                'users.manage', 'users.impersonate', 'audit.view',
            ],
        ],
        'payroll-officer' => [
            'label' => 'Payroll Officer',
            'permissions' => ['dashboard.finance', 'employees.view', 'employees.view-salary', 'payroll.manage', 'payroll.reports'],
        ],
        'finance' => [
            'label' => 'Finance',
            'permissions' => ['dashboard.finance', 'claims.pay', 'claims.reports', 'payroll.approve', 'payroll.reports'],
        ],
        'supervisor' => [
            'label' => 'Supervisor',
            'permissions' => ['leave.approve', 'leave.reports', 'attendance.view-team', 'performance.manage-team', 'recruitment.requisition'],
        ],
        'employee' => [
            'label' => 'Employee',
            'permissions' => ['leave.apply', 'claims.submit', 'attendance.view-own', 'performance.participate', 'payroll.view-own'],
        ],
    ],

    /*
    | Dashboard widgets, in default display order. Modules append their own widget classes here.
    */
    'dashboard_widgets' => [
        App\Dashboard\Widgets\WelcomeWidget::class,
        App\Dashboard\Widgets\ApprovalsInboxWidget::class,
        App\Dashboard\Widgets\HeadcountKpisWidget::class,
        App\Dashboard\Widgets\HeadcountTrendWidget::class,
        App\Dashboard\Widgets\WorkforceBreakdownWidget::class,
        App\Dashboard\Widgets\HrAlertsWidget::class,
        App\Dashboard\Widgets\MyTeamWidget::class,
        App\Dashboard\Widgets\AnnouncementsWidget::class,
        App\Dashboard\Widgets\CelebrationsWidget::class,
        App\Dashboard\Widgets\UpcomingHolidaysWidget::class,
        App\Dashboard\Widgets\SecurityStatusWidget::class,
        App\Dashboard\Widgets\RecentActivityWidget::class,
    ],

    /*
    | Approval providers feed the dashboard approvals inbox (leave, claims, KPIs, payroll ...).
    | Each implements App\Dashboard\Contracts\ApprovalProvider.
    */
    'approval_providers' => [],
];
