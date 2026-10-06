<?php

namespace App\Services\Support;

use App\Models\SupportConversation as Conversation;
use App\Support\Support\SupportPrincipal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-side queries for the conversation lists and unread counts.
 *
 * Per-reader unread state costs one LEFT JOIN on the unique
 * (conversation_id, reader_guard, reader_id) index of support_reads, plus a
 * count subquery evaluated only for the rows of the current page. Message
 * bodies are never loaded for a list; previews are denormalized.
 */
class SupportInbox
{
    public const VIEWS = ['all', 'unread', 'open', 'waiting_admin', 'waiting_vendor', 'resolved', 'closed'];

    public const SORTS = ['queue', 'newest', 'oldest'];

    /** @param array<string,mixed> $filters */
    public function forAdmin(SupportPrincipal $admin, array $filters = []): LengthAwarePaginator
    {
        abort_unless($admin->isAdmin(), 403);

        // Query-string input is untrusted: drop anything that is not a plain scalar
        // (e.g. ?q[]=x) so nothing downstream can be fed an array.
        $filters = array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $filters);

        $query = $this->withReads(Conversation::query(), $admin)
            ->select('support_conversations.*')
            ->selectRaw($this->unreadCountSql($admin), ['vendor'])
            ->with(['vendor:id,name,phone_number', 'category:id,name']);

        $view = in_array($filters['view'] ?? 'all', self::VIEWS, true) ? ($filters['view'] ?? 'all') : 'all';
        if ($view === 'unread') {
            $query->whereRaw('support_conversations.last_vendor_message_id > COALESCE(sr.last_read_message_id, 0)')
                ->where('support_conversations.status', '!=', Conversation::STATUS_CLOSED);
        } elseif ($view !== 'all') {
            $query->where('support_conversations.status', $view);
        }

        if (! empty($filters['category'])) {
            $query->where('support_conversations.category_id', (int) $filters['category']);
        }
        if (! empty($filters['vendor'])) {
            $query->where('support_conversations.vendor_id', (int) $filters['vendor']);
        }
        if ($from = $this->date($filters['from'] ?? null)) {
            $query->where('support_conversations.last_message_at', '>=', $from->startOfDay());
        }
        if ($to = $this->date($filters['to'] ?? null)) {
            $query->where('support_conversations.last_message_at', '<=', $to->endOfDay());
        }
        if (! empty($filters['has_image'])) {
            $query->where('support_conversations.has_image', true);
        }
        if (! empty($filters['has_voice'])) {
            $query->where('support_conversations.has_voice', true);
        }
        if (($term = trim((string) ($filters['q'] ?? ''))) !== '') {
            $this->search($query, mb_substr($term, 0, 100));
        }

        $sort = in_array($filters['sort'] ?? 'queue', self::SORTS, true) ? ($filters['sort'] ?? 'queue') : 'queue';
        match ($sort) {
            'newest' => $query->orderByDesc('support_conversations.last_message_at'),
            'oldest' => $query->orderBy('support_conversations.last_message_at'),
            // Oldest CURRENTLY waiting for an admin first; everything else after, newest first.
            default => $query
                ->orderByRaw('CASE WHEN support_conversations.waiting_since IS NULL THEN 1 ELSE 0 END')
                ->orderBy('support_conversations.waiting_since')
                ->orderByDesc('support_conversations.last_message_at'),
        };
        $query->orderByDesc('support_conversations.id');

        return $query->paginate(config('support.inbox_per_page'))->withQueryString();
    }

    public function forVendor(SupportPrincipal $vendor): LengthAwarePaginator
    {
        abort_unless($vendor->isVendor(), 403);

        return $this->withReads(Conversation::query(), $vendor)
            ->select('support_conversations.*')
            ->selectRaw($this->unreadCountSql($vendor), ['admin'])
            ->where('support_conversations.vendor_id', $vendor->id)
            ->with('category:id,name')
            ->orderByDesc('support_conversations.last_message_at')
            ->orderByDesc('support_conversations.id')
            ->paginate(config('support.inbox_per_page'));
    }

    /** Number of conversations that are unread for THIS reader. */
    public function unreadConversations(SupportPrincipal $reader): int
    {
        $query = $this->withReads(Conversation::query(), $reader);

        if ($reader->isAdmin()) {
            $query->whereRaw('support_conversations.last_vendor_message_id > COALESCE(sr.last_read_message_id, 0)')
                ->where('support_conversations.status', '!=', Conversation::STATUS_CLOSED);
        } else {
            $query->where('support_conversations.vendor_id', $reader->id)
                ->whereRaw('support_conversations.last_admin_message_id > COALESCE(sr.last_read_message_id, 0)');
        }

        return $query->count();
    }

    /** Unread message count of one conversation for THIS reader. */
    public function unreadMessages(SupportPrincipal $reader, Conversation $conversation): int
    {
        return (int) $this->withReads(Conversation::query()->whereKey($conversation->id), $reader)
            ->selectRaw($this->unreadCountSql($reader), [$reader->isAdmin() ? 'vendor' : 'admin'])
            ->value('unread_count');
    }

    public function waitingForAdmin(): int
    {
        return Conversation::where('status', Conversation::STATUS_WAITING_ADMIN)->count();
    }

    private function withReads(Builder $query, SupportPrincipal $reader): Builder
    {
        return $query->leftJoin('support_reads as sr', function ($join) use ($reader) {
            $join->on('sr.conversation_id', '=', 'support_conversations.id')
                ->where('sr.reader_guard', '=', $reader->guard)
                ->where('sr.reader_id', '=', $reader->id);
        });
    }

    /** Binding: the sender role whose messages count as "unread" for this reader. */
    private function unreadCountSql(SupportPrincipal $reader): string
    {
        return '(SELECT COUNT(*) FROM support_messages m'
            .' WHERE m.conversation_id = support_conversations.id AND m.sender_type = ?'
            .' AND m.id > COALESCE(sr.last_read_message_id, 0)) AS unread_count';
    }

    private function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $digits = preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $q) use ($like, $term, $digits) {
            $q->where('support_conversations.subject', 'like', $like)
                ->orWhere('support_conversations.related_label', 'like', $like)
                ->orWhereIn('support_conversations.vendor_id', function ($sub) use ($like, $digits) {
                    $sub->select('id')->from('vendors')
                        ->where('name', 'like', $like)
                        ->orWhere('phone_number', 'like', $like)
                        ->orWhere('email', 'like', $like);
                    if ($digits !== '' && strlen($digits) >= 6) {
                        $sub->orWhere('phone_number', 'like', '%'.ltrim($digits, '0'));
                    }
                })
                ->orWhereExists(function ($sub) use ($like) {
                    $sub->selectRaw('1')->from('support_messages as sm')
                        ->whereColumn('sm.conversation_id', 'support_conversations.id')
                        ->where('sm.body', 'like', $like);
                });

            // "#123" / "123": match the linked record id (order id, etc.).
            if (ctype_digit(ltrim($term, '# '))) {
                $q->orWhere('support_conversations.related_id', (int) ltrim($term, '# '));
            }
        });
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse((string) $value) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
