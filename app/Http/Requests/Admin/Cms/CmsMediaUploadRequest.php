<?php

namespace App\Http\Requests\Admin\Cms;

/**
 * Only presence/size is checked here; MediaService does the real content
 * inspection (sniffed MIME, decoded dimensions, re-encode). The client-sent
 * MIME type and extension are never trusted.
 */
class CmsMediaUploadRequest extends CmsRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.config('cms.media.max_kb')],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
