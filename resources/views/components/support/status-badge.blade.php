@props(['status', 'label'])

{{-- Support statuses share the admin status vocabulary (waiting_admin = amber, waiting_vendor = brand, resolved = green, closed = neutral). --}}
<x-admin.status :status="$status" :label="$label" {{ $attributes }} />
