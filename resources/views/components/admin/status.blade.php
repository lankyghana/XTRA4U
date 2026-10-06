@props([
    'status',
    'label' => null,
])

{{--
    One visual vocabulary for every status string in the admin area.
    Display only: the underlying status value is never altered. Each tone also
    carries a glyph so state is not communicated by colour alone.
--}}
@php
    $key = strtolower(trim((string) $status));
    $tone = match (true) {
        in_array($key, ['paid', 'completed', 'complete', 'successful', 'success', 'approved', 'active', 'resolved', 'delivered', 'healthy', 'verified', 'enabled', 'online']) => 'success',
        in_array($key, ['pending', 'pending_payment', 'pending_stock', 'waiting', 'waiting_admin', 'queued', 'review', 'manual_review', 'unpaid', 'degraded', 'on hold', 'on_hold']) => 'warning',
        in_array($key, ['processing', 'in_progress', 'waiting_vendor', 'submitted', 'retrying', 'verifying']) => 'info',
        in_array($key, ['failed', 'rejected', 'error', 'expired', 'declined', 'refunded', 'reversed']) => 'danger',
        default => 'neutral', // cancelled, closed, inactive, disabled, unknown
    };
    $classes = [
        'success' => 'bg-green-50 text-green-800 ring-green-600/20',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/25',
        'info' => 'bg-brand-violet-soft text-brand-violet-deep ring-brand-violet/25',
        'danger' => 'bg-red-50 text-red-800 ring-red-600/20',
        'neutral' => 'bg-gray-100 text-gray-700 ring-gray-500/20',
    ][$tone];
    $glyph = [
        'success' => 'M5 13l4 4L19 7',
        'warning' => 'M12 8v4m0 4h.01',
        'info' => 'M4 12a8 8 0 0116 0',
        'danger' => 'M6 18L18 6M6 6l12 12',
        'neutral' => 'M6 12h12',
    ][$tone];
    $text = $label ?? ($key === '' ? 'Unknown' : \Illuminate\Support\Str::headline($key));
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$classes}"]) }}>
    <svg class="h-3 w-3" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $glyph }}"/></svg>
    {{ $text }}
</span>
