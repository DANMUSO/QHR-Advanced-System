<?php

namespace App\Modules\Analytics\Http\Controllers;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Http\Controllers\Controller;
use App\Modules\Analytics\Services\AnalyticsService;
use App\Support\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    /** Reports that expose pay data need salary visibility on top of dashboard.hr. */
    private const SALARY_REPORTS = ['payroll-cost', 'pay-gap'];

    public function __construct(private AnalyticsService $analytics) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = collect(config('hr.approval_providers', []))->merge(ModuleRegistry::collect('approval_providers'))
            ->map(fn ($c) => app($c))->filter(fn ($p) => $p instanceof ApprovalProvider)
            ->sum(fn (ApprovalProvider $p) => $p->countFor($user));

        return response()->json(['data' => $this->analytics->hrDashboard($pending)]);
    }

    public function report(Request $request, string $report): JsonResponse
    {
        [$rows, $summary] = $this->build($request, $report);

        return response()->json(['data' => $rows, 'summary' => (object) $summary]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        [$rows] = $this->build($request, $report);
        $headers = $rows ? array_keys(array_filter($rows[0], fn ($v) => ! is_array($v))) : [];
        $headers = array_values(array_diff($headers, ['id', 'photo', 'photo_url']));

        return response()->streamDownload(function () use ($rows, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($h) => Str::headline($h), $headers));
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($h) => $r[$h] ?? '', $headers));
            }
            fclose($out);
        }, "{$report}-report-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function insights(Request $request): JsonResponse
    {
        $key = $this->cacheKey($request, 'insights');

        return response()->json([
            'data' => Cache::remember($key, now()->addHour(), fn () => $this->analytics->insights()),
            'note' => 'Insights are rule-based findings computed from your live HR data.',
        ]);
    }

    public function refreshInsights(Request $request): JsonResponse
    {
        Cache::forget($this->cacheKey($request, 'insights'));

        return response()->json(['message' => 'Insights refreshed.', 'data' => Cache::remember($this->cacheKey($request, 'insights'), now()->addHour(), fn () => $this->analytics->insights())]);
    }

    /** @return array{0: array, 1: array} */
    private function build(Request $request, string $report): array
    {
        $user = $request->user();
        abort_if(in_array($report, self::SALARY_REPORTS, true) && ! ($user->can('employees.view-salary') || $user->can('payroll.reports')), 403, 'This report contains pay data you are not allowed to see.');
        $year = (int) $request->integer('year', now()->year);

        return match ($report) {
            'headcount' => [$this->analytics->headcount($year), []],
            'payroll-cost' => (fn ($r) => [$r['rows'], $r['summary']])($this->analytics->payrollCost($year)),
            'absenteeism' => [$this->analytics->absenteeism($year), []],
            'pay-gap' => [$this->analytics->payGap(), []],
            'attrition' => [$this->analytics->attrition(), []],
            'workforce' => [$this->analytics->workforce(), []],
            default => abort(404, 'Unknown report.'),
        };
    }

    private function cacheKey(Request $request, string $name): string
    {
        return "analytics:{$name}:".(\App\Support\Tenancy::companyId() ?? 'all').':'.$request->user()->id;
    }
}
