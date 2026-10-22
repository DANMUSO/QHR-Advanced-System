<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Reminder;
use App\Modules\Organization\Models\Workflow;
use App\Modules\Organization\Services\ReminderRunner;
use App\Modules\Organization\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Approval workflows and automatic reminders (permission settings.manage). */
class AutomationController extends Controller
{
    // ---- Workflows -------------------------------------------------------------------------

    public function workflows(): JsonResponse
    {
        $rows = Workflow::with('company:id,name')->orderBy('trigger')->orderBy('name')->get()
            ->map(fn (Workflow $w) => $w->toArray() + [
                'trigger_label' => Workflow::TRIGGERS[$w->trigger] ?? $w->trigger,
                'trigger_honoured' => in_array($w->trigger, Workflow::HONOURED_TRIGGERS, true),
            ]);

        return response()->json([
            'data' => $rows,
            'options' => [
                'triggers' => Workflow::TRIGGERS,
                'honoured_triggers' => Workflow::HONOURED_TRIGGERS,
                'step_types' => Workflow::STEP_TYPES,
                'roles' => Role::where('name', '!=', 'super-admin')->orderBy('name')->pluck('name'),
            ],
        ]);
    }

    public function storeWorkflow(Request $request): JsonResponse
    {
        $workflow = new Workflow($this->validatedWorkflow($request));
        $workflow->company_id = CompanyContext::forWrite($request);
        $workflow->is_active = $request->boolean('is_active', true);
        $workflow->save();
        $this->deactivateOthers($workflow);

        return response()->json(['message' => 'Workflow created.', 'data' => $workflow], 201);
    }

    public function updateWorkflow(Request $request, int $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);
        $workflow->fill($this->validatedWorkflow($request));
        if ($request->has('is_active')) {
            $workflow->is_active = $request->boolean('is_active');
        }
        $workflow->save();
        $this->deactivateOthers($workflow);

        return response()->json(['message' => 'Workflow saved.', 'data' => $workflow]);
    }

    public function toggleWorkflow(int $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);
        $workflow->update(['is_active' => ! $workflow->is_active]);
        $this->deactivateOthers($workflow);

        return response()->json(['message' => $workflow->is_active ? 'Workflow activated.' : 'Workflow deactivated.', 'data' => $workflow]);
    }

    public function destroyWorkflow(Request $request, int $id): JsonResponse
    {
        $workflow = Workflow::findOrFail($id);
        $workflow->delete();
        activity('settings')->causedBy($request->user())->performedOn($workflow)->event('deleted')->log("Workflow \"{$workflow->name}\" deleted");

        return response()->json(['message' => 'Workflow deleted.']);
    }

    private function validatedWorkflow(Request $request): array
    {
        $roles = Role::pluck('name')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys(Workflow::TRIGGERS))],
            'description' => ['nullable', 'string', 'max:2000'],
            'steps' => ['required', 'array', 'min:1', 'max:6'],
            'steps.*.type' => ['required', Rule::in(array_keys(Workflow::STEP_TYPES))],
            'steps.*.role' => ['nullable', 'required_if:steps.*.type,role', Rule::in($roles)],
            'steps.*.label' => ['nullable', 'string', 'max:80'],
        ], [
            'steps.required' => 'Add at least one approval step.',
            'steps.min' => 'Add at least one approval step.',
            'steps.*.role.required_if' => 'Choose a role for each "Anyone with role" step.',
        ]);
        $data['steps'] = array_values(array_map(fn ($s) => [
            'type' => $s['type'],
            'role' => $s['type'] === 'role' ? $s['role'] : null,
            'label' => ($s['label'] ?? '') ?: ($s['type'] === 'role' ? str($s['role'])->headline()->toString() : Workflow::STEP_TYPES[$s['type']]),
        ], $data['steps']));

        return $data;
    }

    /** Only one active workflow per trigger per company. */
    private function deactivateOthers(Workflow $workflow): void
    {
        if ($workflow->is_active) {
            Workflow::where('company_id', $workflow->company_id)->where('trigger', $workflow->trigger)
                ->whereKeyNot($workflow->id)->where('is_active', true)->get()->each->update(['is_active' => false]);
        }
    }

    // ---- Reminders -------------------------------------------------------------------------

    public function reminders(): JsonResponse
    {
        $rows = Reminder::with('company:id,name')->orderBy('event')->orderBy('days_before')->get()
            ->map(fn (Reminder $r) => $r->toArray() + ['event_label' => Reminder::EVENTS[$r->event] ?? $r->event]);

        return response()->json([
            'data' => $rows,
            'options' => ['events' => Reminder::EVENTS, 'channels' => Reminder::CHANNELS, 'recipients' => Reminder::RECIPIENTS],
        ]);
    }

    public function storeReminder(Request $request): JsonResponse
    {
        $reminder = new Reminder($this->validatedReminder($request));
        $reminder->company_id = CompanyContext::forWrite($request);
        $reminder->save();
        activity('settings')->causedBy($request->user())->performedOn($reminder)->event('created')->log("Reminder \"{$reminder->name}\" created");

        return response()->json(['message' => 'Reminder created.', 'data' => $reminder], 201);
    }

    public function updateReminder(Request $request, int $id): JsonResponse
    {
        $reminder = Reminder::findOrFail($id);
        $reminder->fill($this->validatedReminder($request))->save();
        activity('settings')->causedBy($request->user())->performedOn($reminder)->event('updated')
            ->withProperties(['attributes' => $reminder->getChanges()])->log("Reminder \"{$reminder->name}\" updated");

        return response()->json(['message' => 'Reminder saved.', 'data' => $reminder]);
    }

    public function destroyReminder(Request $request, int $id): JsonResponse
    {
        $reminder = Reminder::findOrFail($id);
        $reminder->delete();
        activity('settings')->causedBy($request->user())->performedOn($reminder)->event('deleted')->log("Reminder \"{$reminder->name}\" deleted");

        return response()->json(['message' => 'Reminder deleted.']);
    }

    /** Preview who the rule matches today, or send now with ?send=1. */
    public function runReminder(Request $request, int $id, ReminderRunner $runner): JsonResponse
    {
        $reminder = Reminder::findOrFail($id);
        if ($request->boolean('send')) {
            $n = $runner->run($reminder, now());
            activity('settings')->causedBy($request->user())->performedOn($reminder)->log("Reminder \"{$reminder->name}\" run manually ({$n} match(es))");

            return response()->json(['message' => $n ? "Sent reminders for {$n} employee(s)." : 'No employees match this reminder today.', 'count' => $n]);
        }

        $matches = $runner->matches($reminder, now())->map(fn ($m) => ['employee_id' => $m[0]->id, 'name' => $m[0]->full_name, 'message' => $m[1]])->values();

        return response()->json(['data' => $matches, 'count' => $matches->count()]);
    }

    private function validatedReminder(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'event' => ['required', Rule::in(array_keys(Reminder::EVENTS))],
            'days_before' => ['required', 'integer', 'min:0', 'max:365'],
            'channel' => ['required', Rule::in(array_keys(Reminder::CHANNELS))],
            'recipients' => ['required', Rule::in(array_keys(Reminder::RECIPIENTS))],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
