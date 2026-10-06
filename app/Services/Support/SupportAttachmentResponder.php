<?php

namespace App\Services\Support;

use App\Models\SupportAttachment;
use App\Support\Support\SupportPrincipal;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way support files leave private storage. A caller who may not see
 * the attachment gets the same 404 as for an id that does not exist, so ids
 * cannot be probed.
 */
class SupportAttachmentResponder
{
    public function respond(SupportPrincipal $actor, int $attachmentId): Response
    {
        $attachment = SupportAttachment::query()->with('message.conversation')->find($attachmentId);

        if (! $attachment || ! Gate::forUser($actor)->allows('view', $attachment)) {
            abort(404);
        }

        $disk = Storage::disk($attachment->disk);
        if (! $disk->exists($attachment->storage_path)) {
            abort(404);
        }

        $headers = [
            // Type comes from our own validated record, never from the file name.
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="support-'.$attachment->id.'.'.pathinfo($attachment->storage_path, PATHINFO_EXTENSION).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        // Local disk: BinaryFileResponse gives HTTP Range support (audio seeking).
        if ($attachment->disk === 'local' || config('filesystems.disks.'.$attachment->disk.'.driver') === 'local') {
            $response = response()->file($disk->path($attachment->storage_path), $headers);

            // BinaryFileResponse defaults to `Cache-Control: public`. Support files are private: make sure
            // no shared cache (CDN, reverse proxy) may ever store or replay them.
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

            return $response;
        }

        return $disk->response($attachment->storage_path, null, $headers);
    }
}
