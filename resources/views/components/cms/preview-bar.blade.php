{{-- Shown only on the admin-protected preview routes. --}}
@if ($cms->previewing())
    <div role="status" style="position: relative; z-index: 60; background-color: #1c1e54; color: #fff; text-align: center; padding: 8px 16px; font-size: 13px;">
        Preview &mdash; this is the unpublished draft. It is visible only to signed-in administrators.
    </div>
@endif
