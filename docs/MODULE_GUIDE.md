# Building an HR module

Each HR module is self-contained so modules can be built independently. Read [HR-FEATURES.md](../HR-FEATURES.md) for the business rules.

## Stack and conventions
- Laravel 12 API (`/api/...`), Sanctum cookie auth, MySQL (InnoDB), PHP 8.2. Vue 3 `<script setup>` SPA, Vue Router, Pinia, Tailwind 4, lucide-vue-next icons, chart.js via vue-chartjs.
- Money is stored as `decimal(14,2)`, dates as `date`/`timestamp`, statuses as string enums. No text-encoded numbers.
- Timezone: Africa/Nairobi. Currency: KES (`$company->setting('currency')`).
- Match the existing code style: see `app/Http/Controllers/AnnouncementController.php`, `app/Dashboard/Widgets/*`, `resources/js/pages/Announcements.vue`.

## Backend layout: `app/Modules/<Name>/`
```
app/Modules/Leave/
  module.php                    manifest (see App\Support\ModuleRegistry for every key)
  routes.php                    API routes. Already inside the auth:sanctum + account.secure group, under /api
  database/migrations/          module migrations (auto-loaded); prefix file names 2026_10_17_<4 digits>_
  database/DemoSeeder.php       namespace App\Modules\Leave\Database; idempotent demo data
  Models/ Http/Controllers/ Http/Requests/ Services/ Notifications/ Dashboard/ Console/ ...
```
- Namespace: `App\Modules\<Name>\...` (PSR-4 via `App\` → `app/`).
- **Company scoping:** company-owned models `use App\Models\Concerns\BelongsToCompany;` with a `company_id` column. They are then auto-filtered to the user's company and auto-stamped on create. Child rows of a company-owned parent may skip `company_id`, but must then be loaded through the parent.
- **Permissions:** use the existing keys in `config/hr.php` (`leave.apply`, `leave.approve`, `claims.pay`, `payroll.manage`, …). Protect routes with `->middleware('permission:x')` or `$request->user()->can('x')`. New keys go in the manifest under `permissions` / `role_permissions`. Super admins pass every check.
- **"Me":** `$request->user()->employee` (may be null for the platform super admin, so handle it). Supervisor = `$employee->primary_supervisor_id`; direct reports = `$employee->directReports()`.
- **Do not edit `App\Models\Employee`/`User`**. Add relations from your manifest's `boot` closure:
  `Employee::resolveRelationUsing('leaveBalances', fn ($e) => $e->hasMany(LeaveBalance::class));`
- **Audit:** add `Spatie\Activitylog\Traits\LogsActivity` to important models (see `Employee`), and call `activity()->...->log()` for workflow decisions.
- **Notifications:** Laravel notifications to the `User` (`$employee->user`). Channels: `'mail'`, `'database'` (bell; `toArray` must return `title`, `message`, `url`, `icon`) and `App\Notifications\Channels\SmsChannel` (implement `toSms(): string`). Guard against employees without a user account. Mail is logged locally.
- **Dashboard:** widgets extend `App\Dashboard\Widget`; list them in the manifest under `widgets`, with a Vue component at `resources/js/components/dashboard/widgets/<StudlyKey>.vue` (key `who_is_out` → `WhoIsOut.vue`) taking props `data`, `title`. Approval queues implement `App\Dashboard\Contracts\ApprovalProvider` (manifest `approval_providers`). `approve_endpoint`/`reject_endpoint` are API paths **without** the `/api` prefix (e.g. `/leave/requests/5/approve`); reject posts `{reason}`. `url` is an SPA path.
- **Scheduling:** manifest `schedule` => `fn (\Illuminate\Console\Scheduling\Schedule $s) => $s->command('leave:accrue')->monthlyOn(1, '00:30')`. Register console commands with `Artisan::starting` or put them in `app/Console/Commands` (auto-discovered); module-local command classes can be registered in `boot` with `\Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(MyCommand::class))`.
- **Cross-module data:** Payroll reads from other modules only through `App\Contracts\PayrollInputProvider` (manifest `payroll_inputs`). The My Team widget reads `team_status`.
- **Holidays:** `App\Models\Holiday::datesBetween($from, $to, $companyId)`. Company rules: `$company->setting('work_start')` etc. (see `Company::DEFAULT_SETTINGS`); add new keys with sensible defaults read via `setting($key, $default)`.
- **Dropdowns:** `GET /api/lookups?only=branches,departments,positions,levels,benefits,employees,supervisors,companies`.
- **PDFs:** `barryvdh/laravel-dompdf` (`Pdf::loadView(...)`), Blade views in `app/Modules/<Name>/resources/views` registered in `boot` with `view()->addNamespace('leave', __DIR__.'/resources/views')`.
- **Files:** store on the `public` disk under `<module>/...` for public assets, or the `local` disk for private documents served through an authorised download route. Validate type and size.

## Frontend layout: `resources/js/modules/<module>/`
```
resources/js/modules/leave/
  routes.js     export default [{ path: 'leave', name: 'leave', component: () => import('./pages/MyLeave.vue'), meta: { title: 'Leave', can: 'leave.apply' } }, ...]
                (paths relative, no leading slash; they become children of the app layout)
  nav.js        export default [{ section: 'Workspace', label: 'Leave', to: '/leave', icon: CalendarDays, can: 'leave.apply', order: 10 }]
                sections: 'Workspace' (self-service), 'Team' (supervisor), 'People' (HR), 'Finance', 'Administration'
  pages/  components/  api.js (optional)
```
- API: `import api, { fieldErrors, errorMessage } from '@/lib/api'` (baseURL already includes `/api`). Toasts: `useUiStore().toast(msg, 'success'|'error'|'warning')`. Permissions: `useAuthStore().can('x')`; current employee: `auth.user.employee`.
- Shared UI in `resources/js/components/ui/`: `DataTable` (sorting, server paging, CSV export, cell slots), `PageHeader`, `Tabs`, `StatCard`, `Modal`, `ConfirmDialog` (optional reason), `Badge`, `Avatar`, `EmptyState`, `Spinner`. CSS utilities: `card input label btn-primary btn-secondary btn-danger btn-ghost field-error`. Formatting: `@/lib/format` (`formatDate`, `formatMoney`, `timeAgo`, `titleCase`). Export: `@/lib/export` (`downloadCsv`, `openServerFile('/api/...')` for server PDFs).
- Must look good in light and dark mode (`dark:` variants) and on mobile.

## Rules while several modules are built in parallel
- Only create/edit files inside your own module folders, unless your brief says otherwise. If a shared file needs a change, describe the exact change in your final report instead of making it.
- Database: **never** run `migrate:fresh`, `migrate:reset`, `db:wipe` or a plain `db:seed`. Use `php artisan migrate` (additive) and `php artisan db:seed --class="App\Modules\X\Database\DemoSeeder"`. Write seeders so they can be re-run.
- Frontend: never build into `public/build`. Check compilation with
  `npx vite build --outDir <your scratch dir>/build-<module> --emptyOutDir` (the app's live build is done once at the end).
- The dev server runs at http://127.0.0.1:8000 and picks up PHP changes immediately. Test your API over HTTP (Sanctum: fetch `/sanctum/csrf-cookie`, send the `X-XSRF-TOKEN` header plus `Referer: http://127.0.0.1:8000`, keep a cookie jar). Demo logins (password `Password@123`): admin@hr.test (super admin), hr@hr.test, finance@hr.test, payroll@hr.test, supervisor@hr.test (manager of employee@hr.test), employee@hr.test.
- `php -l` every PHP file you write, and run `php artisan route:list --path=api/<prefix>` to confirm your routes load.
