<?php

namespace App\Policies;

use App\Models\SupportAttachment;
use App\Support\Support\SupportPrincipal;
use Illuminate\Support\Facades\Gate;

class SupportAttachmentPolicy
{
    /** An attachment is visible to exactly those who can view its conversation. */
    public function view(SupportPrincipal $actor, SupportAttachment $attachment): bool
    {
        $conversation = $attachment->message?->conversation;

        return $conversation !== null && Gate::forUser($actor)->allows('view', $conversation);
    }
}
