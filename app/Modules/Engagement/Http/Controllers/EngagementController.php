<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Engagement\Models\EngagementComment;
use App\Modules\Engagement\Support\AuthorHash;
use App\Modules\Performance\Notifications\PerformanceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Suggestion box. Anonymous comments keep employee_id null; the author finds them through author_hash
 * (AuthorHash), which HR endpoints never return, and can reply in the thread without revealing who they are.
 */
class EngagementController extends Controller
{
    /** Employee: my comments, including my anonymous ones, with HR's replies. */
    public function mine(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'No employee record linked.');
        $hash = AuthorHash::for($request->user());

        $comments = EngagementComment::where(fn ($q) => $q->where('employee_id', $employee->id)->orWhere('author_hash', $hash))
            ->with(['replies.user:id,name'])
            ->orderByDesc('id')->get();

        // Opening the history marks HR's replies as seen.
        $unseen = $comments->flatMap->replies->filter(fn ($r) => ! $r->from_author && ! $r->seen)->pluck('id');
        if ($unseen->isNotEmpty()) {
            \App\Modules\Engagement\Models\EngagementReply::whereKey($unseen)->update(['seen' => true]);
        }

        return response()->json([
            'data' => $comments->map(fn ($c) => $this->present($c, false, forAuthor: true)),
            'categories' => EngagementComment::CATEGORIES,
        ]);
    }

    /** Employee: submit a comment. */
    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'No employee record linked.');

        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(EngagementComment::CATEGORIES))],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'anonymous' => ['sometimes', 'boolean'],
        ]);
        $anonymous = (bool) ($data['anonymous'] ?? false);

        $comment = EngagementComment::create([
            'company_id' => $employee->company_id,
            'employee_id' => $anonymous ? null : $employee->id,
            'author_hash' => AuthorHash::for($request->user()),
            'category' => $data['category'],
            'body' => $data['body'],
            'anonymous' => $anonymous,
        ]);

        // Audit without breaking anonymity: anonymous submissions carry no causer and no body.
        $log = activity('engagement')->performedOn($comment)->withProperties(['category' => $comment->category, 'anonymous' => $comment->anonymous]);
        ($comment->anonymous ? $log->causedByAnonymous() : $log->causedBy($request->user()))
            ->log($comment->anonymous ? 'Anonymous comment submitted to HR' : 'Comment submitted to HR');

        $this->notifyHr($comment, 'New staff comment', ($anonymous ? 'An anonymous employee' : $employee->full_name).' sent a comment ('.(EngagementComment::CATEGORIES[$comment->category] ?? $comment->category).').', $request->user());

        return response()->json(['message' => 'Comment submitted.', 'data' => $this->present($comment, false, forAuthor: true)], 201);
    }

    /** Author: reply in my own thread (anonymously if the comment was anonymous). */
    public function respond(Request $request, EngagementComment $engagementComment): JsonResponse
    {
        $user = $request->user();
        $isAuthor = ($user->employee && $engagementComment->employee_id === $user->employee->id)
            || ($engagementComment->author_hash && hash_equals($engagementComment->author_hash, AuthorHash::for($user)));
        abort_unless($isAuthor, 403);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:3000']]);

        $engagementComment->replies()->create([
            'company_id' => $engagementComment->company_id,
            // Anonymous threads never record which user wrote the follow-up.
            'user_id' => $engagementComment->anonymous ? null : $user->id,
            'body' => $data['body'],
            'from_author' => true,
        ]);
        $engagementComment->update(['status' => 'pending']);
        $this->notifyHr($engagementComment, 'Staff follow-up on a comment',
            ($engagementComment->anonymous ? 'An anonymous employee' : ($user->employee?->full_name ?? $user->name)).' replied in a comment thread.', $user);

        return response()->json(['message' => 'Reply sent.', 'data' => $this->present($engagementComment->load('replies.user:id,name'), false, forAuthor: true)]);
    }

    /** HR: all comments. */
    public function index(Request $request): JsonResponse
    {
        $query = EngagementComment::with(['employee:id,first_name,last_name', 'replies.user:id,name'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id');

        $summary = EngagementComment::selectRaw('category, COUNT(*) as count')->groupBy('category')->pluck('count', 'category');

        return response()->json([
            'data' => $query->paginate(min($request->integer('per_page', 25), 100))->through(fn ($c) => $this->present($c, true)),
            'summary' => $summary,
            'categories' => EngagementComment::CATEGORIES,
        ]);
    }

    /** HR: reply to a comment. */
    public function reply(Request $request, EngagementComment $engagementComment): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:3000']]);

        $engagementComment->replies()->create([
            'company_id' => $engagementComment->company_id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);
        $old = $engagementComment->status;
        $engagementComment->update(['status' => 'replied']);
        activity('engagement')->performedOn($engagementComment)->causedBy($request->user())
            ->withProperties(['old' => ['status' => $old], 'attributes' => ['status' => 'replied'], 'category' => $engagementComment->category])
            ->log('HR replied to staff comment');

        // Reach the author (anonymous ones through the hash) without telling HR who it is.
        $author = $engagementComment->employee_id
            ? $engagementComment->employee?->user
            : AuthorHash::findUser($engagementComment->author_hash, $engagementComment->company_id);
        try {
            $author?->notify(new PerformanceNotification('HR replied to your comment', str($data['body'])->limit(140)->toString(), '/engagement', 'message-circle', false));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'Reply sent.', 'data' => $this->present($engagementComment->load('replies.user:id,name'), true)]);
    }

    private function notifyHr(EngagementComment $comment, string $title, string $message, User $except): void
    {
        $users = User::permission('engagement.manage')->where('company_id', $comment->company_id)->where('is_active', true)->get();
        foreach ($users->reject(fn (User $u) => $u->is($except)) as $user) {
            try {
                $user->notify(new PerformanceNotification($title, $message, '/engagement/manage', 'message-circle', false));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** $forAuthor: the author's own view. HR views never carry author_hash or an anonymous author's identity. */
    private function present(EngagementComment $c, bool $showEmployee = false, bool $forAuthor = false): array
    {
        return [
            'id' => $c->id,
            'category' => $c->category,
            'category_label' => EngagementComment::CATEGORIES[$c->category] ?? $c->category,
            'body' => $c->body,
            'anonymous' => $c->anonymous,
            'status' => $c->status,
            'employee' => $showEmployee && ! $c->anonymous && $c->employee ? ['id' => $c->employee->id, 'name' => $c->employee->full_name] : null,
            'replies' => $c->relationLoaded('replies') ? $c->replies->map(fn ($r) => [
                'id' => $r->id,
                'body' => $r->body,
                'from_author' => (bool) $r->from_author,
                'author' => $r->from_author
                    ? ($forAuthor ? 'You' : ($c->anonymous ? 'Anonymous' : ($c->employee?->full_name ?? $r->user?->name ?? 'Employee')))
                    : ($r->user?->name ?? 'HR'),
                'seen' => $r->seen,
                'created_at' => $r->created_at,
            ]) : [],
            'unread_replies' => $forAuthor && $c->relationLoaded('replies') ? $c->replies->filter(fn ($r) => ! $r->from_author && ! $r->seen)->count() : null,
            'created_at' => $c->created_at,
        ];
    }
}
