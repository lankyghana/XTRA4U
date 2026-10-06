<?php

namespace App\Services\Support;

use App\Models\AdminNotification;
use App\Models\SupportAttachment;
use App\Models\SupportCategory;
use App\Models\SupportConversation as Conversation;
use App\Models\SupportConversationEvent as Event;
use App\Models\SupportMessage;
use App\Models\SupportRead;
use App\Models\Vendor;
use App\Models\VendorNotification;
use App\Support\Support\SupportPrincipal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * All support-chat business rules. Controllers are thin callers.
 *
 * Every mutating method takes a SupportPrincipal (built only from the
 * authenticated session, see SupportPrincipal) and re-authorizes against the
 * conversation, so request data can never choose who the actor is or whose
 * conversation is touched.
 *
 * Status model (global, shared by all admins):
 *   vendor message  -> waiting_admin  (waiting_since set only on ENTERING it)
 *   admin message   -> waiting_vendor (waiting_since cleared)
 *   admin resolve   -> resolved       | admin close -> closed
 *   vendor reply to resolved within config('support.reopen_days') -> reopened
 *   (waiting_admin); older resolved, and any closed, need a new request.
 * Unread state is per reader (support_reads), never global.
 */
class SupportService
{
    public function __construct(
        private SupportRelatedRecords $related,
        private SupportAttachmentProcessor $processor,
    ) {}

    // ------------------------------------------------------------------
    // Create / send
    // ------------------------------------------------------------------

    /**
     * Vendor starts a new support request with a first message.
     *
     * @param  array{category_id:mixed,subject?:?string,related_type?:?string,related_id?:mixed,body?:?string,images?:array,voice?:?UploadedFile,voice_duration?:?int,client_token?:?string}  $data
     */
    public function startConversation(SupportPrincipal $actor, array $data): Conversation
    {
        $this->requireRole($actor, SupportPrincipal::ROLE_VENDOR);

        $vendor = Vendor::query()->findOrFail($actor->id);
        $token = $this->cleanToken($data['client_token'] ?? null);

        if ($token && ($existing = Conversation::where('vendor_id', $vendor->id)->where('client_token', $token)->first())) {
            return $existing;
        }

        $category = SupportCategory::active()->find($data['category_id'] ?? null);
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Please choose a valid category.']);
        }

        $related = null;
        if (! empty($data['related_type']) || ! empty($data['related_id'])) {
            $related = $this->related->resolve($vendor, $data['related_type'] ?? null, $data['related_id'] ?? null);
            if (! $related) {
                throw ValidationException::withMessages(['related_id' => 'We could not find that record on your account.']);
            }
        }

        $body = $this->cleanBody($data['body'] ?? null);
        $prepared = $this->prepareAttachments($data['images'] ?? [], $data['voice'] ?? null, $data['voice_duration'] ?? null);
        $this->requireContent($body, $prepared);

        $subject = trim((string) ($data['subject'] ?? ''));
        $subject = mb_substr($subject !== '' ? $subject : $category->name, 0, 150);

        $written = [];
        try {
            return DB::transaction(function () use ($vendor, $category, $related, $subject, $token, $actor, $body, $prepared, &$written) {
                $conversation = new Conversation;
                $conversation->forceFill([
                    'vendor_id' => $vendor->id,
                    'category_id' => $category->id,
                    'subject' => $subject,
                    'status' => Conversation::STATUS_OPEN,
                    'client_token' => $token,
                    'related_type' => $related['type'] ?? null,
                    'related_id' => $related['id'] ?? null,
                    'related_label' => $related['label'] ?? null,
                ])->save();

                $this->event($conversation, Event::CREATED, $actor, null, Conversation::STATUS_OPEN, [
                    'category' => $category->slug,
                    'related' => $related ? $related['type'].':'.$related['id'] : null,
                ]);

                $this->appendMessage($conversation, $actor, $body, $prepared, null, $written);

                return $conversation->fresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            // Double-submit race on client_token: hand back the winner.
            if ($token && ($existing = Conversation::where('vendor_id', $vendor->id)->where('client_token', $token)->first())) {
                $this->deleteFiles($written);

                return $existing;
            }
            $this->deleteFiles($written);
            throw $e;
        } catch (Throwable $e) {
            $this->deleteFiles($written);
            throw $e;
        }
    }

    /**
     * Post a message. A retried request carrying the same client_token returns the
     * original message instead of creating a duplicate (check `wasRecentlyCreated`).
     *
     * @param  array{body?:?string,images?:array,voice?:?UploadedFile,voice_duration?:?int,client_token?:?string}  $data
     */
    public function sendMessage(SupportPrincipal $actor, Conversation $conversation, array $data): SupportMessage
    {
        $this->authorize($actor, 'reply', $conversation);

        $token = $this->cleanToken($data['client_token'] ?? null);
        if ($token && ($dup = $this->existingMessage($conversation->id, $token, $actor))) {
            return $dup;
        }

        $body = $this->cleanBody($data['body'] ?? null);
        $prepared = $this->prepareAttachments($data['images'] ?? [], $data['voice'] ?? null, $data['voice_duration'] ?? null);
        $this->requireContent($body, $prepared);

        $written = [];
        try {
            return DB::transaction(function () use ($conversation, $actor, $body, $prepared, $token, &$written) {
                // Serialize all state changes for this conversation.
                $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();

                // Re-check under the lock: ownership/status may have changed since the policy check.
                $this->authorize($actor, 'reply', $locked);

                if ($token && ($dup = $this->existingMessage($locked->id, $token, $actor))) {
                    return $dup;
                }

                return $this->appendMessage($locked, $actor, $body, $prepared, $token, $written);
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->deleteFiles($written);
            if ($token && ($dup = $this->existingMessage($conversation->id, $token, $actor))) {
                return $dup;
            }
            throw $e;
        } catch (Throwable $e) {
            $this->deleteFiles($written);
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Admin lifecycle
    // ------------------------------------------------------------------

    public function resolve(SupportPrincipal $actor, Conversation $conversation): Conversation
    {
        return $this->transition($actor, $conversation, function (Conversation $c) use ($actor) {
            if ($c->isClosed()) {
                throw ValidationException::withMessages(['status' => 'A closed conversation cannot be resolved. Reopen it first.']);
            }
            if ($c->isResolved()) {
                return null;
            }
            $from = $c->status;
            $c->forceFill([
                'status' => Conversation::STATUS_RESOLVED,
                'resolved_at' => now(),
                'resolved_by_guard' => $actor->guard,
                'resolved_by_id' => $actor->id,
                'waiting_since' => null,
            ])->save();
            $this->event($c, Event::RESOLVED, $actor, $from, $c->status);
            $this->notifyVendor($c, 'Support request resolved', 'Your support request "'.$c->subject.'" was marked as resolved.');

            return $c;
        });
    }

    public function close(SupportPrincipal $actor, Conversation $conversation): Conversation
    {
        return $this->transition($actor, $conversation, function (Conversation $c) use ($actor) {
            if ($c->isClosed()) {
                return null;
            }
            $from = $c->status;
            $c->forceFill([
                'status' => Conversation::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by_guard' => $actor->guard,
                'closed_by_id' => $actor->id,
                'waiting_since' => null,
            ])->save();
            $this->event($c, Event::CLOSED, $actor, $from, $c->status);

            return $c;
        });
    }

    public function reopen(SupportPrincipal $actor, Conversation $conversation): Conversation
    {
        return $this->transition($actor, $conversation, function (Conversation $c) use ($actor) {
            if (! in_array($c->status, [Conversation::STATUS_RESOLVED, Conversation::STATUS_CLOSED], true)) {
                return null;
            }
            $from = $c->status;
            $c->forceFill([
                'status' => Conversation::STATUS_OPEN,
                'resolved_at' => null, 'resolved_by_guard' => null, 'resolved_by_id' => null,
                'closed_at' => null, 'closed_by_guard' => null, 'closed_by_id' => null,
                'waiting_since' => null,
            ])->save();
            $this->event($c, Event::REOPENED, $actor, $from, $c->status, ['by' => 'admin']);

            return $c;
        });
    }

    private function transition(SupportPrincipal $actor, Conversation $conversation, \Closure $apply): Conversation
    {
        $this->authorize($actor, 'manage', $conversation);

        return DB::transaction(function () use ($conversation, $apply, $actor) {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, 'manage', $locked);

            return $apply($locked) ?? $locked;
        });
    }

    // ------------------------------------------------------------------
    // Read state (per reader)
    // ------------------------------------------------------------------

    /**
     * Advance THIS reader's read position (never anyone else's, never backwards).
     */
    public function markRead(SupportPrincipal $actor, Conversation $conversation, ?int $upToMessageId = null): void
    {
        $this->authorize($actor, 'view', $conversation);

        $latest = max((int) $conversation->last_vendor_message_id, (int) $conversation->last_admin_message_id);
        $target = $upToMessageId === null ? $latest : min($upToMessageId, $latest);

        $this->advanceRead($conversation->id, $actor, $target);
    }

    private function advanceRead(int $conversationId, SupportPrincipal $reader, int $messageId): void
    {
        $key = ['conversation_id' => $conversationId, 'reader_guard' => $reader->guard, 'reader_id' => $reader->id];

        if (! SupportRead::query()->where($key)->exists()) {
            try {
                (new SupportRead)->forceFill($key + ['last_read_message_id' => $messageId, 'read_at' => now()])->save();

                return;
            } catch (UniqueConstraintViolationException) {
                // lost the race; fall through to the update.
            }
        }

        SupportRead::query()->where($key)->where('last_read_message_id', '<', $messageId)
            ->update(['last_read_message_id' => $messageId, 'read_at' => now()]);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Inserts the message and applies the status / queue / audit transitions.
     * Must run inside a transaction with $conversation locked.
     */
    private function appendMessage(Conversation $c, SupportPrincipal $actor, ?string $body, array $prepared, ?string $token, array &$written): SupportMessage
    {
        $now = now();
        $fromStatus = $c->status;
        $isVendor = $actor->isVendor();

        if ($c->isClosed()) {
            throw ValidationException::withMessages(['message' => 'This conversation is closed. Please start a new support request.']);
        }

        $reopened = false;
        if ($c->isResolved()) {
            if ($isVendor && ! $this->withinReopenWindow($c)) {
                throw ValidationException::withMessages(['message' => 'This request was resolved a while ago. Please start a new support request.']);
            }
            $reopened = true;
        }

        $hasImage = collect($prepared)->contains('kind', SupportAttachment::KIND_IMAGE);
        $hasVoice = collect($prepared)->contains('kind', SupportAttachment::KIND_AUDIO);

        $message = new SupportMessage;
        $message->forceFill([
            'conversation_id' => $c->id,
            'sender_type' => $actor->role,
            'sender_guard' => $actor->guard,
            'sender_id' => $actor->id,
            'body' => $body,
            'message_type' => $this->messageType($body, $hasImage, $hasVoice),
            'client_token' => $token,
        ])->save();

        foreach ($prepared as $blob) {
            $path = $this->processor->store($blob, (int) $c->vendor_id, (int) $c->id);
            $written[] = $path;

            (new SupportAttachment)->forceFill([
                'message_id' => $message->id,
                'kind' => $blob['kind'],
                'disk' => config('support.disk'),
                'storage_path' => $path,
                'original_name' => $blob['original_name'],
                'mime_type' => $blob['mime'],
                'size' => $blob['size'],
                'duration_seconds' => $blob['duration'],
                'created_at' => $now,
            ])->save();
        }

        $newStatus = $isVendor ? Conversation::STATUS_WAITING_ADMIN : Conversation::STATUS_WAITING_VENDOR;

        $updates = [
            'status' => $newStatus,
            'last_message_at' => $now,
            'last_message_preview' => $this->preview($body, $hasImage, $hasVoice),
            'last_message_sender' => $actor->role,
            'has_image' => $c->has_image || $hasImage,
            'has_voice' => $c->has_voice || $hasVoice,
        ];

        if ($reopened) {
            $updates += ['resolved_at' => null, 'resolved_by_guard' => null, 'resolved_by_id' => null];
        }

        if ($isVendor) {
            $updates['last_vendor_message_at'] = $now;
            $updates['last_vendor_message_id'] = $message->id;
            $updates['first_vendor_message_at'] = $c->first_vendor_message_at ?? $now;
            // FIFO: the clock starts only when the conversation ENTERS waiting_admin.
            $updates['waiting_since'] = ($fromStatus === Conversation::STATUS_WAITING_ADMIN && $c->waiting_since)
                ? $c->waiting_since
                : $now;
        } else {
            $updates['last_admin_message_at'] = $now;
            $updates['last_admin_message_id'] = $message->id;
            $updates['waiting_since'] = null;
        }

        $c->forceFill($updates)->save();

        if ($reopened) {
            $this->event($c, Event::REOPENED, $actor, $fromStatus, $newStatus, ['by' => $actor->role]);
        } elseif ($fromStatus !== $newStatus && $fromStatus !== Conversation::STATUS_OPEN) {
            $this->event($c, Event::STATUS_CHANGED, $actor, $fromStatus, $newStatus);
        }

        // The sender has, by definition, read up to their own message.
        $this->advanceRead($c->id, $actor, $message->id);

        // Alert only on a real transition, so a burst of messages is one notification.
        if ($fromStatus !== $newStatus) {
            $isVendor ? $this->notifyAdmins($c) : $this->notifyVendor($c, 'New support reply', 'Support replied to "'.$c->subject.'".');
        }

        return $message->setRelation('attachments', $message->attachments()->get());
    }

    private function prepareAttachments(array $images, ?UploadedFile $voice, mixed $voiceDuration): array
    {
        $images = array_values(array_filter($images, fn ($f) => $f instanceof UploadedFile));
        if (count($images) > config('support.images.max_per_message')) {
            throw ValidationException::withMessages(['images' => 'You can attach at most '.config('support.images.max_per_message').' images per message.']);
        }

        $prepared = array_map(fn (UploadedFile $f) => $this->processor->prepareImage($f), $images);

        if ($voice instanceof UploadedFile) {
            $prepared[] = $this->processor->prepareAudio($voice, is_numeric($voiceDuration) ? (int) $voiceDuration : null);
        }

        return $prepared;
    }

    private function requireContent(?string $body, array $prepared): void
    {
        if (($body === null || $body === '') && $prepared === []) {
            throw ValidationException::withMessages(['message' => 'Please type a message or attach an image or voice note.']);
        }
    }

    private function cleanBody(?string $body): ?string
    {
        $body = trim((string) $body);
        if (mb_strlen($body) > config('support.message_max_length')) {
            throw ValidationException::withMessages(['message' => 'Message is too long (max '.config('support.message_max_length').' characters).']);
        }

        return $body === '' ? null : $body;
    }

    private function cleanToken(?string $token): ?string
    {
        return is_string($token) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $token) ? $token : null;
    }

    private function existingMessage(int $conversationId, string $token, SupportPrincipal $actor): ?SupportMessage
    {
        $message = SupportMessage::query()->where('conversation_id', $conversationId)->where('client_token', $token)->first();
        if (! $message) {
            return null;
        }

        if ($message->sender_guard !== $actor->guard || (int) $message->sender_id !== $actor->id) {
            throw ValidationException::withMessages(['message' => 'Invalid request. Please reload and try again.']);
        }

        return $message->load('attachments');
    }

    private function withinReopenWindow(Conversation $c): bool
    {
        return $c->resolved_at !== null
            && $c->resolved_at->greaterThanOrEqualTo(now()->subDays(max(0, (int) config('support.reopen_days'))));
    }

    private function messageType(?string $body, bool $image, bool $voice): string
    {
        $kinds = ($body !== null ? 1 : 0) + ($image ? 1 : 0) + ($voice ? 1 : 0);

        return $kinds > 1 ? 'mixed' : ($image ? 'image' : ($voice ? 'voice' : 'text'));
    }

    private function preview(?string $body, bool $image, bool $voice): string
    {
        if ($body !== null) {
            return mb_substr(preg_replace('/\s+/', ' ', $body), 0, 120);
        }

        return $voice ? '[Voice note]' : '[Image]';
    }

    private function event(Conversation $c, string $event, SupportPrincipal $actor, ?string $from, ?string $to, array $meta = []): void
    {
        (new Event)->forceFill([
            'conversation_id' => $c->id,
            'event' => $event,
            'actor_type' => $actor->role,
            'actor_guard' => $actor->guard,
            'actor_id' => $actor->id,
            'from_status' => $from,
            'to_status' => $to,
            'meta' => $meta ?: null,
            'created_at' => now(),
        ])->save();
    }

    private function notifyAdmins(Conversation $c): void
    {
        $vendor = Vendor::query()->find($c->vendor_id);

        AdminNotification::create([
            'type' => AdminNotification::TYPE_SUPPORT_MESSAGE,
            'title' => 'New support message',
            'message' => ($vendor?->name ?? 'A vendor').': '.$c->subject,
            'vendor_id' => $c->vendor_id,
            'data' => ['conversation_id' => $c->id],
        ]);
    }

    private function notifyVendor(Conversation $c, string $title, string $message): void
    {
        VendorNotification::create([
            'vendor_id' => $c->vendor_id,
            'type' => VendorNotification::TYPE_SUPPORT_REPLY,
            'title' => $title,
            'message' => $message,
            'data' => ['conversation_id' => $c->id],
        ]);
    }

    private function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk(config('support.disk'))->delete($path);
            } catch (Throwable) {
                // best effort; an orphan file is private and harmless.
            }
        }
    }

    private function requireRole(SupportPrincipal $actor, string $role): void
    {
        if ($actor->role !== $role) {
            abort(403, 'Unauthorized');
        }
    }

    private function authorize(SupportPrincipal $actor, string $ability, Conversation $conversation): void
    {
        Gate::forUser($actor)->authorize($ability, $conversation);
    }
}
