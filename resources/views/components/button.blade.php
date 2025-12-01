<!-- Button Component -->
@props([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
    'icon' => null,
    'iconPosition' => 'left'
])

@php
$baseClasses = 'inline-flex items-center justify-center font-medium rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed';

$variants = [
    'primary' => 'bg-[#00942C] text-white hover:bg-[#009633] focus:ring-[#0B66B5] px-4 py-2 font-medium shadow-md',
    'secondary' => 'border border-gray-300 text-gray-700 bg-white hover:bg-gray-100 focus:ring-[#0B66B5] px-4 py-2 font-medium',
    'outline' => 'border border-gray-300 text-gray-700 bg-white hover:bg-gray-50 focus:ring-[#0B66B5] px-4 py-2 font-medium',
    'ghost' => 'text-gray-700 bg-transparent hover:bg-gray-100 focus:ring-[#0B66B5] px-4 py-2 font-medium',
    'danger' => 'bg-[#DC2626] text-white hover:bg-red-700 focus:ring-[#DC2626] px-4 py-2 font-medium',
    'success' => 'bg-[#00942C] text-white hover:bg-[#009633] focus:ring-[#00942C] px-4 py-2 font-medium',
];

$sizes = [
    'sm' => 'px-3 py-2 text-sm',
    'md' => 'px-4 py-2 text-sm',
    'lg' => 'px-6 py-3 text-base',
    'xl' => 'px-8 py-4 text-lg',
];

$classes = collect([$baseClasses, $variants[$variant], $sizes[$size]])->implode(' ');
@endphp

@if($href)
    <a href="{{ $href }}" 
       class="{{ $classes }}"
       {{ $attributes }}>
        @if($icon && $iconPosition === 'left')
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {!! $icon !!}
            </svg>
        @endif
        
        {{ $slot }}
        
        @if($loading)
            <svg class="animate-spin -mr-1 ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        
        @if($icon && $iconPosition === 'right')
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {!! $icon !!}
            </svg>
        @endif
    </a>
@else
    <button type="{{ $type }}" 
            class="{{ $classes }}"
            {{ $disabled ? 'disabled' : '' }}
            {{ $attributes }}>
        @if($icon && $iconPosition === 'left')
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {!! $icon !!}
            </svg>
        @endif
        
        {{ $slot }}
        
        @if($loading)
            <svg class="animate-spin -mr-1 ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        @endif
        
        @if($icon && $iconPosition === 'right')
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                {!! $icon !!}
            </svg>
        @endif
    </button>
@endif