<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Recognition;
use App\Modules\Performance\Notifications\PerformanceNotification;
use App\Modules\Performance\Services\PerformanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Peer / manager recognition wall. Anyone taking part in performance can see and award recognition. */
class RecognitionController extends Controller
{
    use PresentsPeople;

    public function index(Request $request): JsonResponse
    {
        $rows = Recognition::with(['employee.position:id,title', 'employee.department:id,name', 'awardedBy:id,name'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->latest()->paginate(min($request->integer('per_page', 30), 100));

        $leaders = Recognition::where('created_at', '>=', now()->subDays(90))->selectRaw('employee_id, COUNT(*) as c')
            ->groupBy('employee_id')->orderByDesc('c')->limit(5)->with('employee:id,first_name,last_name,photo_path')->get()
            ->map(fn ($r) => ['employee' => $this->person($r->employee), 'count' => (int) $r->c]);

        return response()->json(array_merge($rows->through(fn (Recognition $r) => $this->row($r, $request))->toArray(), ['leaders' => $leaders]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required'],
            'title' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', Rule::in(Recognition::CATEGORIES)],
            'message' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], ['employee_id' => 'employee']);
        $employee = $this->findEmployee($data['employee_id']);
        abort_if($employee->id === $request->user()->employee?->id, 422, 'You cannot recognise yourself.');
        $category = $data['category'] ?? 'appreciation';

        $recognition = new Recognition([
            'employee_id' => $employee->id, 'category' => $category, 'title' => $data['title'] ?? null,
            'message' => $data['message'], 'awarded_by_user_id' => $request->user()->id,
        ]);
        $recognition->company_id = $employee->company_id;
        $recognition->save();
        $what = $data['title'] ?? str_replace('_', ' ', $category);
        try {
            $employee->user?->notify(new PerformanceNotification('You were recognised!',
                "{$this->actorName($request->user())} recognised you ({$what}): {$data['message']}", '/performance?tab=recognition', 'award'));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => $this->row($recognition->load('employee.position', 'awardedBy'), $request), 'message' => 'Recognition sent.'], 201);
    }

    /** Self-service: recognition I received and recognition I gave. */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();
        $with = ['employee.position:id,title', 'employee.department:id,name', 'awardedBy:id,name'];
        $received = $user->employee
            ? Recognition::with($with)->where('employee_id', $user->employee->id)->latest()->limit(100)->get()
            : collect();
        $given = Recognition::with($with)->where('awarded_by_user_id', $user->id)->latest()->limit(100)->get();

        return response()->json([
            'received' => $received->map(fn (Recognition $r) => $this->row($r, $request))->values(),
            'given' => $given->map(fn (Recognition $r) => $this->row($r, $request))->values(),
            'categories' => Recognition::CATEGORIES,
        ]);
    }

    public function destroy(Request $request, Recognition $recognition): JsonResponse
    {
        abort_unless($recognition->awarded_by_user_id === $request->user()->id || PerformanceAccess::isAdmin($request->user()), 403);
        $recognition->delete();

        return response()->json(['message' => 'Recognition removed.']);
    }

    private function row(Recognition $r, Request $request): array
    {
        return [
            'id' => $r->id, 'employee' => $this->person($r->employee), 'category' => $r->category, 'title' => $r->title, 'message' => $r->message,
            'awarded_by' => $r->awardedBy?->name ?? 'HR', 'created_at' => $r->created_at,
            'can_delete' => $r->awarded_by_user_id === $request->user()->id || PerformanceAccess::isAdmin($request->user()),
        ];
    }
}
