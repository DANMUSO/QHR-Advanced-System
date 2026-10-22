# HR Advanced

HR platform built with Laravel 12 (API, Sanctum SPA auth) and Vue 3 (Vite, Pinia, Tailwind 4).
The full feature specification is in [HR-FEATURES.md](HR-FEATURES.md).

## Setup

```bash
composer install
npm install
cp .env.example .env        # then set DB_* to your MySQL database
php artisan key:generate
php artisan migrate --seed   # creates roles/permissions, Kenyan holidays and (locally) demo data
npm run build                # or `npm run dev` while developing
php artisan serve            # http://127.0.0.1:8000
```

WAMP's MySQL creates MyISAM tables by default; the connection forces `InnoDB` (`DB_ENGINE`).

## Demo accounts (local only)

All demo passwords are `Password@123`.

| Email | Roles |
|---|---|
| admin@hr.test | Super Admin (all companies) |
| hr@hr.test | HR Administrator, Supervisor |
| finance@hr.test | Finance, Supervisor |
| payroll@hr.test | Payroll Officer |
| supervisor@hr.test | Supervisor |
| employee@hr.test | Employee |

Set `HR_ENFORCE_2FA=true` to require two-factor authentication for privileged roles. Locally it's off by default so the demo logins work straight away.

## Architecture notes

- **Multi-company:** models using `BelongsToCompany` are scoped to the signed-in user's company. Super admins see every company, or one company chosen through the `X-Company-Id` header (the company switcher in the top bar).
- **Permissions & roles:** defined in `config/hr.php`. Run `php artisan hr:sync-permissions` after editing.
- **Dashboard widgets:** each widget is a class in `app/Dashboard/Widgets`, registered in `config/hr.php`, and drawn by the Vue component of the same name in `resources/js/components/dashboard/widgets`.
- **Approvals inbox:** modules feed it by implementing `App\Dashboard\Contracts\ApprovalProvider` and listing the class in `hr.approval_providers`.
- **Modules:** API routes go in `routes/modules/*.php` and SPA routes in `resources/js/modules/<module>/routes.js`. Both are loaded automatically.
