<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Organization\Models\SupportReply;
use App\Modules\Organization\Models\SupportTicket;
use App\Modules\Organization\Notifications\OrganizationNotice;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Help desk. Company users raise tickets to the platform support team (super admins).
 * Admins with settings.manage see all tickets of their company; others see their own; super admins see every ticket.
 */
class SupportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->visible($request)->with(['user:id,name,email', 'company:id,name'])->withCount('replies')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w->where('subject', 'like', "%{$s}%")->orWhere('ticket_number', 'like', "%{$s}%")))
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END")->latest();

        $base = $this->visible($request);
        $stats = collect(SupportTicket::STATUSES)->mapWithKeys(fn ($s) => [$s => (clone $base)->where('status', $s)->count()]);

        return response()->json(array_merge($query->paginate(15)->toArray(), [
            'stats' => $stats,
            'options' => ['categories' => SupportTicket::CATEGORIES, 'priorities' => SupportTicket::PRIORITIES, 'statuses' => SupportTicket::STATUSES],
            'is_support_agent' => Tenancy::isGlobal(),
        ]));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = $this->visible($request)->with(['user:id,name,email', 'company:id,name', 'replies.user:id,name', 'replies.user.roles:id,name'])->findOrFail($id);
        $ticket->replies->each(function ($r) {
            $r->setAttribute('is_support', (bool) $r->user?->roles->contains('name', 'super-admin'));
            $r->user?->unsetRelation('roles');
        });

        return response()->json(['data' => $ticket]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(SupportTicket::CATEGORIES))],
            'priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        $user = $request->user();
        $ticket = SupportTicket::create($data + [
            'company_id' => $user->company_id ?? Tenancy::companyId(),
            'user_id' => $user->id,
            'ticket_number' => SupportTicket::nextNumber(),
            'status' => 'open',
        ]);

        User::role('super-admin')->where('is_active', true)->whereKeyNot($user->id)->get()
            ->each->notify(new OrganizationNotice("New support ticket {$ticket->ticket_number}", "{$user->name}: {$ticket->subject}", '/organization/support?ticket='.$ticket->id, 'in_app', 'life-buoy'));

        return response()->json(['message' => "Ticket {$ticket->ticket_number} submitted. Our support team will respond shortly.", 'data' => $ticket], 201);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $ticket = $this->visible($request)->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        abort_if($ticket->status === 'closed', 422, 'This ticket is closed. Open a new ticket instead.');

        $user = $request->user();
        $reply = SupportReply::create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => $data['body']]);

        $isAgent = $user->hasRole('super-admin');
        if ($isAgent && $ticket->status === 'open') {
            $ticket->update(['status' => 'in_progress']);
        } elseif (! $isAgent && $ticket->status === 'resolved') {
            $ticket->update(['status' => 'open', 'resolved_at' => null]); // customer re-opened
        }

        $notify = $isAgent ? collect([$ticket->user]) : User::role('super-admin')->where('is_active', true)->get();
        $notify->filter()->reject(fn ($u) => $u->is($user))
            ->each->notify(new OrganizationNotice("Reply on {$ticket->ticket_number}", "{$user->name}: ".str($data['body'])->limit(120), '/organization/support?ticket='.$ticket->id, 'in_app', 'message-square'));

        return response()->json(['message' => 'Reply sent.', 'data' => $reply->load('user:id,name'), 'status' => $ticket->status], 201);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $ticket = $this->visible($request)->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(SupportTicket::STATUSES)]]);
        $user = $request->user();

        // Requesters may only close or re-open their own tickets; support agents may set any status.
        if (! $user->hasRole('super-admin')) {
            abort_unless(in_array($data['status'], ['closed', 'open'], true), 403, 'Only the support team can change this status.');
        }

        $ticket->update([
            'status' => $data['status'],
            'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? ($ticket->resolved_at ?? now()) : null,
        ]);

        if ($ticket->user && ! $ticket->user->is($user)) {
            $ticket->user->notify(new OrganizationNotice("Ticket {$ticket->ticket_number} ".str_replace('_', ' ', $data['status']), $ticket->subject, '/organization/support?ticket='.$ticket->id, 'in_app', 'life-buoy'));
        }

        return response()->json(['message' => 'Ticket status updated.', 'data' => $ticket]);
    }

    private function visible(Request $request): Builder
    {
        $user = $request->user();
        $query = SupportTicket::query();
        if (Tenancy::isGlobal()) {
            return $query->when(Tenancy::companyId(), fn ($q, $c) => $q->where('company_id', $c));
        }
        if ($user->can('settings.manage')) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('user_id', $user->id);
    }
}
