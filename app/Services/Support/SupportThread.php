<?php

namespace App\Services\Support;

use App\Models\Admin;
use App\Models\SupportConversation as Conversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Support\Support\SupportPrincipal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Cursor-paginated message history, shaped for the viewer.
 *
 *   initial load : latest N messages
 *   before=ID    : the N messages older than ID  (scroll back)
 *   after=ID     : messages newer than ID        (polling; a future push
 *                  transport can reuse exactly this payload)
 */
class SupportThread
{
    /** @return array{messages:array<int,array>,has_more_before:bool,last_id:int} */
    public function page(Conversation $conversation, SupportPrincipal $viewer, ?int $after = null, ?int $before = null, ?int $limit = null): array
    {
        Gate::forUser($viewer)->authorize('view', $conversation);

        $limit = max(1, min((int) ($limit ?? config('support.messages_per_page')), 100));
        $query = SupportMessage::query()->where('conversation_id', $conversation->id)->with('attachments');

        if ($after !== null) {
            $messages = $query->where('id', '>', $after)->orderBy('id')->limit($limit)->get();
            $hasMore = false;
        } else {
            if ($before !== null) {
                $query->where('id', '<', $before);
            }
            $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $messages = $rows->take($limit)->reverse()->values();
        }

        return [
            'messages' => $this->present($messages, $conversation, $viewer),
            'has_more_before' => $hasMore,
            'last_id' => (int) ($messages->last()?->id ?? $after ?? 0),
        ];
    }

    /** @return array<int,array> */
    public function present(Collection $messages, Conversation $conversation, SupportPrincipal $viewer): array
    {
        $names = $viewer->isAdmin() ? $this->adminNames($messages) : [];
        $vendorName = $viewer->isAdmin() ? ($conversation->vendor?->name ?? $conversation->vendor()->value('name') ?? 'Vendor') : null;
        $routeName = $viewer->isAdmin() ? 'admin.support.attachments.show' : 'vendor.support.attachments.show';

        return $messages->map(function (SupportMessage $m) use ($viewer, $names, $vendorName, $routeName) {
            $mine = $m->sender_guard === $viewer->guard && (int) $m->sender_id === $viewer->id;

            if ($m->sender_type === SupportMessage::SENDER_VENDOR) {
                $sender = $viewer->isVendor() ? 'You' : $vendorName;
            } else {
                // Vendors never see which individual admin replied.
                $sender = $viewer->isAdmin() ? ($names[$m->sender_guard.':'.$m->sender_id] ?? 'Admin') : 'XTRA4U Support';
            }

            return [
                'id' => $m->id,
                'sender_type' => $m->sender_type,
                'sender' => $sender,
                'mine' => $mine,
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
                'time' => $m->created_at?->format('d M, H:i'),
                'attachments' => $m->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'kind' => $a->kind,
                    'mime' => $a->mime_type,
                    'name' => $a->original_name,
                    'size' => $a->size,
                    'duration' => $a->duration_seconds,
                    'url' => route($routeName, $a->id),
                ])->all(),
            ];
        })->all();
    }

    /** Two queries total, regardless of how many admins replied. */
    private function adminNames(Collection $messages): array
    {
        $ids = $messages->where('sender_type', SupportMessage::SENDER_ADMIN)->groupBy('sender_guard')
            ->map(fn ($group) => $group->pluck('sender_id')->unique()->all());

        $names = [];
        if ($ids->has('admin')) {
            foreach (Admin::whereIn('id', $ids['admin'])->pluck('name', 'id') as $id => $name) {
                $names['admin:'.$id] = $name;
            }
        }
        if ($ids->has('web')) {
            foreach (User::whereIn('id', $ids['web'])->pluck('name', 'id') as $id => $name) {
                $names['web:'.$id] = $name;
            }
        }

        return $names;
    }
}
