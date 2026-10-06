<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportMessageRequest;
use App\Models\SupportCategory;
use App\Models\SupportConversation;
use App\Models\SupportConversationEvent;
use App\Models\SupportQuickReply;
use App\Services\Support\SupportAttachmentResponder;
use App\Services\Support\SupportInbox;
use App\Services\Support\SupportRelatedRecords;
use App\Services\Support\SupportService;
use App\Services\Support\SupportThread;
use App\Support\Support\SupportPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin support inbox. The admin identity comes from SupportPrincipal::admin()
 * (admin guard, or a web User with role=admin) and read state is per admin.
 */
class SupportInboxController extends Controller
{
    public function __construct(
        private SupportService $support,
        private SupportInbox $inbox,
        private SupportThread $thread,
        private SupportRelatedRecords $related,
    ) {}

    public function index(Request $request)
    {
        return $this->render($request, null);
    }

    public function show(Request $request, int $conversationId)
    {
        $actor = SupportPrincipal::admin();
        $conversation = SupportConversation::query()->with(['vendor:id,name,phone_number,email', 'category:id,name'])->findOrFail($conversationId);

        $page = $this->thread->page($conversation, $actor);
        $this->support->markRead($actor, $conversation, $page['last_id']);

        return $this->render($request, [
            'conversation' => $conversation,
            'page' => $page,
            'relatedUrl' => $this->related->adminUrl($conversation->related_type, $conversation->related_id),
            'events' => SupportConversationEvent::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->limit(15)->get(),
        ]);
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        $actor = SupportPrincipal::admin();
        $conversation = SupportConversation::query()->findOrFail($conversationId);

        $page = $this->thread->page(
            $conversation,
            $actor,
            $request->filled('after') ? (int) $request->query('after') : null,
            $request->filled('before') ? (int) $request->query('before') : null,
        );

        if ($request->filled('after') && $request->boolean('mark_read') && $page['messages']) {
            $this->support->markRead($actor, $conversation, $page['last_id']);
        }

        return response()->json($page + $this->state($conversation));
    }

    public function send(SupportMessageRequest $request, int $conversationId): JsonResponse
    {
        $actor = SupportPrincipal::admin();
        $conversation = SupportConversation::query()->findOrFail($conversationId);

        $message = $this->support->sendMessage($actor, $conversation, $request->payload());
        $conversation->refresh();

        return response()->json([
            'message' => $this->thread->present(collect([$message]), $conversation, $actor)[0],
        ] + $this->state($conversation), $message->wasRecentlyCreated ? 201 : 200);
    }

    public function markRead(int $conversationId): JsonResponse
    {
        $this->support->markRead(SupportPrincipal::admin(), SupportConversation::findOrFail($conversationId));

        return response()->json(['ok' => true]);
    }

    public function resolve(Request $request, int $conversationId)
    {
        return $this->lifecycle($request, $conversationId, 'resolve', 'Conversation marked as resolved.');
    }

    public function close(Request $request, int $conversationId)
    {
        return $this->lifecycle($request, $conversationId, 'close', 'Conversation closed.');
    }

    public function reopen(Request $request, int $conversationId)
    {
        return $this->lifecycle($request, $conversationId, 'reopen', 'Conversation reopened.');
    }

    public function unreadCount(): JsonResponse
    {
        $actor = SupportPrincipal::admin();

        return response()->json([
            'unread' => $this->inbox->unreadConversations($actor),
            'waiting' => $this->inbox->waitingForAdmin(),
        ]);
    }

    /** Active quick replies only; optionally those for a category plus the general ones. */
    public function quickReplies(Request $request): JsonResponse
    {
        SupportPrincipal::admin();

        $query = SupportQuickReply::active()->ordered();
        if ($request->filled('category')) {
            $query->where(fn ($q) => $q->whereNull('category_id')->orWhere('category_id', (int) $request->query('category')));
        }

        return response()->json(['replies' => $query->get(['id', 'category_id', 'title', 'body'])]);
    }

    public function attachment(SupportAttachmentResponder $responder, int $attachmentId)
    {
        return $responder->respond(SupportPrincipal::admin(), $attachmentId);
    }

    private function lifecycle(Request $request, int $conversationId, string $action, string $flash)
    {
        $actor = SupportPrincipal::admin();
        $conversation = SupportConversation::query()->findOrFail($conversationId);

        $this->support->{$action}($actor, $conversation);

        return redirect()->route('admin.support.show', $conversationId)->with('status', $flash);
    }

    private function render(Request $request, ?array $selected)
    {
        $actor = SupportPrincipal::admin();
        // Query-string values must be plain strings (a `?q[]=x` array would break rendering).
        $filters = array_map(
            fn ($v) => is_scalar($v) ? (string) $v : null,
            $request->only(['view', 'category', 'vendor', 'from', 'to', 'has_image', 'has_voice', 'q', 'sort'])
        );

        return view('admin.support.index', [
            'conversations' => $this->inbox->forAdmin($actor, $filters),
            'filters' => $filters,
            'categories' => SupportCategory::ordered()->get(['id', 'name']),
            'statuses' => SupportConversation::STATUSES,
            'selected' => $selected,
            'pollSeconds' => config('support.poll_interval_seconds'),
        ]);
    }

    private function state(SupportConversation $conversation): array
    {
        return [
            'status' => $conversation->status,
            'status_label' => $conversation->statusLabel(),
            'can_reply' => ! $conversation->isClosed(),
        ];
    }
}
