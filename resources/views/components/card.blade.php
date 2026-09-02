<!-- Card Component -->
@props([
    'variant' => 'default', // default, metric, product, feature, checkout
    'padding' => 'md',
    'shadow' => 'sm',
    'rounded' => 'lg',
    'hover' => false
])

@php
$baseClasses = 'bg-white border border-gray-200';

$paddingClasses = [
    'none' => '',
    'sm' => 'p-4',
    'md' => 'p-6',
    'lg' => 'p-8',
];

$shadowClasses = [
    'none' => '',
    'sm' => 'shadow-sm',
    'md' => 'shadow-md',
    'lg' => 'shadow-lg',
    'xl' => 'shadow-xl',
];

$roundedClasses = [
    'none' => '',
    'sm' => 'rounded-sm',
    'md' => 'rounded-md',
    'lg' => 'rounded-lg',
    'xl' => 'rounded-xl',
];

// Variant-specific styling
$variantClasses = [
    'default' => '',
    'metric' => 'border-l-4 border-brand-violet bg-gradient-to-r from-white to-violet-50/30',
    'product' => 'hover:shadow-lg transition-all duration-200 hover:-translate-y-1',
    'feature' => 'text-center hover:shadow-md transition-all duration-200',
    'checkout' => 'max-w-2xl mx-auto shadow-lg border-gray-100',
];

$hoverClasses = $hover ? 'hover:shadow-lg transition-shadow duration-200' : '';

$classes = collect([
    $baseClasses,
    $paddingClasses[$padding],
    $shadowClasses[$shadow],
    $roundedClasses[$rounded],
    $variantClasses[$variant],
    $hoverClasses
])->filter()->implode(' ');
@endphp

<div class="{{ $classes }}" {{ $attributes }}>
    {{ $slot }}
</div>