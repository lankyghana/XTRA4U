<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation only. It deliberately has no field for sender, vendor,
 * conversation ownership or storage paths: those are derived server-side.
 * Authoritative content checks (MIME sniffing, re-encoding, limits) happen in
 * SupportAttachmentProcessor.
 */
class SupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is enforced by route middleware + SupportService policies
    }

    public function rules(): array
    {
        $images = config('support.images');

        return [
            'message' => ['nullable', 'string', 'max:'.config('support.message_max_length')],
            'images' => ['nullable', 'array', 'max:'.$images['max_per_message']],
            'images.*' => ['file', 'max:'.($images['max_kb'] + 512)],
            'voice' => ['nullable', 'file', 'max:'.(config('support.audio.max_kb') + 512)],
            'voice_duration' => ['nullable', 'integer', 'min:0', 'max:'.(config('support.audio.max_seconds') + 2)],
            'client_token' => ['nullable', 'string', 'max:64'],

            // New-conversation only (ignored by the reply endpoints).
            'category_id' => ['sometimes', 'integer'],
            'subject' => ['nullable', 'string', 'max:150'],
            'related_type' => ['nullable', 'string', 'max:40'],
            'related_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** Service input. Never includes identity: that is the SupportPrincipal's job. */
    public function payload(): array
    {
        return [
            'body' => $this->input('message'),
            'images' => $this->file('images', []),
            'voice' => $this->file('voice'),
            'voice_duration' => $this->input('voice_duration'),
            'client_token' => $this->input('client_token'),
            'category_id' => $this->input('category_id'),
            'subject' => $this->input('subject'),
            'related_type' => $this->input('related_type'),
            'related_id' => $this->input('related_id'),
        ];
    }
}
