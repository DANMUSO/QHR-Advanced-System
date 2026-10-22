<?php

namespace App\Http\Controllers;

use App\Models\LoginActivity;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

class AuditController extends Controller
{
    public function loginActivities(Request $request): JsonResponse
    {
        $query = LoginActivity::with('user:id,name,email')->latest('created_at')
            ->when($request->query('event'), fn ($q, $e) => $q->where('event', $e))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('email', 'like', "%{$s}%")->orWhere('ip_address', 'like', "%{$s}%")))
            ->when($request->query('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d));

        if ($companyId = Tenancy::companyId()) {
            $query->whereIn('user_id', User::where('company_id', $companyId)->select('id'));
        }

        return response()->json($query->paginate(25));
    }

    public function activityLog(Request $request): JsonResponse
    {
        $query = Activity::with('causer:id,name')->latest()
            ->when($request->query('log'), fn ($q, $l) => $q->where('log_name', $l))
            ->when($request->query('subject'), fn ($q, $s) => $q->where('subject_type', 'like', "%\\{$s}"))
            ->when($request->query('search'), fn ($q, $s) => $q->where('description', 'like', "%{$s}%"));

        if ($companyId = Tenancy::companyId()) {
            self::scopeToCompany($query, $companyId);
        }

        return response()->json($query->paginate(25)->through(fn (Activity $a) => [
            'id' => $a->id,
            'log' => $a->log_name,
            'description' => $a->description,
            'event' => $a->event,
            'subject' => class_basename((string) $a->subject_type),
            'subject_id' => $a->subject_id,
            'causer' => $a->causer?->name ?? 'System',
            'changes' => $a->properties,
            'at' => $a->created_at,
        ]));
    }

    /**
     * Limits an activity query to one company: entries caused by that company's users, plus entries
     * (system/console ones with no causer, or made by a platform admin) whose subject belongs to the company.
     * Subjects are matched through their table's company_id, or employee_id for child rows; subjects that
     * can't be tied to a company are never shown, so other companies' entries don't leak.
     */
    public static function scopeToCompany(Builder $query, int $companyId): Builder
    {
        $types = Cache::remember('audit.subject_types', 300, fn () => Activity::query()->whereNotNull('subject_type')
            ->distinct()->pluck('subject_type')->all());

        return $query->where(function ($w) use ($companyId, $types) {
            $w->whereIn('causer_id', User::where('company_id', $companyId)->select('id'))->where('causer_type', User::class);

            foreach ($types as $type) {
                if (! class_exists($type) || ! is_subclass_of($type, Model::class)) {
                    continue;
                }
                $table = (new $type)->getTable();
                $ids = match (true) {
                    self::hasColumn($table, 'company_id') => DB::table($table)->where('company_id', $companyId)->select('id'),
                    self::hasColumn($table, 'employee_id') => DB::table($table)->whereIn('employee_id', DB::table('employees')->where('company_id', $companyId)->select('id'))->select('id'),
                    default => null,
                };
                if ($ids) {
                    $w->orWhere(fn ($s) => $s->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            }
        });
    }

    private static function hasColumn(string $table, string $column): bool
    {
        static $cache = [];

        return $cache["{$table}.{$column}"] ??= Schema::hasColumn($table, $column);
    }
}
