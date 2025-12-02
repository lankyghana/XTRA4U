@props([
    'variant' => 'default', // default, completed, pending, failed, processing
    'size' => 'md' // sm, md, lg
])

@php
    $baseClasses = 'inline-flex items-center font-medium rounded-full';
    
    $sizeClasses = [
        'sm' => 'px-2 py-1 text-xs',
        'md' => 'px-2.5 py-0.5 text-sm',
        'lg' => 'px-3 py-1 text-sm',
    ];
    
    $variantClasses = [
        'default' => 'bg-gray-100 text-gray-800',
        'completed' => 'bg-green-50 text-brand-green border border-green-200',
        'pending' => 'bg-yellow-50 text-yellow-700 border border-yellow-200', 
        'failed' => 'bg-red-50 text-red-700 border border-red-200',
        'warning' => 'bg-red-50 text-red-700 border border-red-200',
        'processing' => 'bg-blue-50 text-brand-deep-blue border border-blue-200',
    ];
    
    $classes = $baseClasses . ' ' . $sizeClasses[$size] . ' ' . $variantClasses[$variant];
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    @if ($variant === 'completed')
        <svg class="w-4 h-4 mr-1 text-brand-green" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
        </svg>
    @elseif ($variant === 'pending')
        <svg class="w-4 h-4 mr-1 text-yellow-700" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
        </svg>
    @elseif ($variant === 'failed' || $variant === 'warning')
        <svg class="w-4 h-4 mr-1 text-red-700" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
        </svg>
    @elseif ($variant === 'processing')
        <svg class="w-4 h-4 mr-1 text-brand-deep-blue animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
    @endif
    {{ $slot }}
</span>