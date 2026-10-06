<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupportMessageRequest;
use App\Models\SupportCategory;
use App\Models\SupportConversation;
use App\Services\Support\SupportAttachmentResponder;
use App\Services\Support\SupportInbox;
use App\Services\Support\SupportRelatedRecords;
use App\Services\Support\SupportService;
use App\Services\Support\SupportThread;
use App\Support\Support\SupportPrincipal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vendor side of support chat. Every method derives the actor from the vendor
 * guard (never from input) and looks conversations up scoped to that vendor, so
 * another vendor's conversation id is simply a 404.
 */
class SupportController extends Controller
{
    public function __construct(
        private SupportService $support,
        private SupportInbox $inbox,
        private SupportThread $thread,
        private SupportRelatedRecords $related,
    ) {}

    public function index()
    {
        $actor = SupportPrincipal::vendor();

        return view('vendor.support.index', [
            'vendor' => auth('vendor')->user(),
            'conversations' => $this->inbox->forVendor($actor),
        ]);
    }

    public function create(Request $request)
    {
        $vendor = auth('vendor')->user();
        $relatedType = $request->query('related_type');
        $prefill = $this->related->resolve(
            $vendor,
            is_string($relatedType) ? $relatedType : null,
            is_scalar($request->query('related_id')) ? $request->query('related_id') : null,
        );

        return view('vendor.support.new', [
            'vendor' => $vendor,
            'categories' => SupportCategory::active()->ordered()->get(['id', 'slug', 'name', 'related_types', 'common_issues']),
            'relatedTypes' => SupportRelatedRecords::TYPES,
            'prefill' => $prefill,
            'prefillCategory' => is_string($request->query('category')) ? $request->query('category') : null,
        ]);
    }

    public function store(SupportMessageRequest $request): JsonResponse
    {
        $conversation = $this->support->startConversation(SupportPrincipal::vendor(), $request->payload());

        return response()->json([
            'redirect' => route('vendor.support.show', $conversation->id),
        ], 201);
    }

    public function show(int $conversationId)
    {
        $actor = SupportPrincipal::vendor();
        $conversation = $this->find($conversationId, $actor);

        $page = $this->thread->page($conversation, $actor);
        $this->support->markRead($actor, $conversation, $page['last_id']);

        return view('vendor.support.show', [
            'vendor' => auth('vendor')->user(),
            'conversation' => $conversation->load('category:id,name'),
            'page' => $page,
            'canReply' => ! $conversation->isClosed(),
            'pollSeconds' => config('support.poll_interval_seconds'),
        ]);
    }

    public function messages(Request $request, int $conversationId): JsonResponse
    {
        $actor = SupportPrincipal::vendor();
        $conversation = $this->find($conversationId, $actor);

        $page = $this->thread->page(
            $conversation,
            $actor,
            $request->filled('after') ? (int) $request->query('after') : null,
            $request->filled('before') ? (int) $request->query('before') : null,
        );

        // Only the polling (newest) direction advances the read marker.
        if ($request->filled('after') && $request->boolean('mark_read') && $page['messages']) {
            $this->support->markRead($actor, $conversation, $page['last_id']);
        }

        return response()->json($page + $this->statePayload($conversation));
    }

    public function send(SupportMessageRequest $request, int $conversationId): JsonResponse
    {
        $actor = SupportPrincipal::vendor();
        $conversation = $this->find($conversationId, $actor);

        $message = $this->support->sendMessage($actor, $conversation, $request->payload());
        $conversation->refresh();

        return response()->json([
            'message' => $this->thread->present(collect([$message]), $conversation, $actor)[0],
        ] + $this->statePayload($conversation), $message->wasRecentlyCreated ? 201 : 200);
    }

    public function markRead(int $conversationId): JsonResponse
    {
        $actor = SupportPrincipal::vendor();
        $this->support->markRead($actor, $this->find($conversationId, $actor));

        return response()->json(['ok' => true]);
    }

    public function unreadCount(): JsonResponse
    {
        return response()->json(['unread' => $this->inbox->unreadConversations(SupportPrincipal::vendor())]);
    }

    /** Records this vendor may reference, for the "link a record" picker. */
    public function relatedOptions(Request $request): JsonResponse
    {
        $type = $request->query('type');
        abort_unless(is_string($type) && SupportRelatedRecords::isSupportedType($type), 404);

        return response()->json(['options' => $this->related->optionsFor(auth('vendor')->user(), $type)]);
    }

    public function attachment(SupportAttachmentResponder $responder, int $attachmentId)
    {
        return $responder->respond(SupportPrincipal::vendor(), $attachmentId);
    }

    private function find(int $id, SupportPrincipal $actor): SupportConversation
    {
        return SupportConversation::query()->where('vendor_id', $actor->id)->findOrFail($id);
    }

    private function statePayload(SupportConversation $conversation): array
    {
        return [
            'status' => $conversation->status,
            'status_label' => $conversation->statusLabel(),
            'can_reply' => ! $conversation->isClosed(),
        ];
    }
}
