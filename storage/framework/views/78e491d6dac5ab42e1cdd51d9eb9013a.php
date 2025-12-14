<!-- Card Component -->
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'variant' => 'default', // default, metric, product, feature, checkout
    'padding' => 'md',
    'shadow' => 'sm',
    'rounded' => 'lg',
    'hover' => false
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'variant' => 'default', // default, metric, product, feature, checkout
    'padding' => 'md',
    'shadow' => 'sm',
    'rounded' => 'lg',
    'hover' => false
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
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
    'metric' => 'border-l-4 border-brand-deep-blue bg-gradient-to-r from-white to-blue-50/30',
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
?>

<div class="<?php echo e($classes); ?>" <?php echo e($attributes); ?>>
    <?php echo e($slot); ?>

</div><?php /**PATH C:\Users\dktakyi001\Desktop\XTRA4U\resources\views/components/card.blade.php ENDPATH**/ ?>