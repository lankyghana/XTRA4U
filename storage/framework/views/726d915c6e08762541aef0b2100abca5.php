<!-- Button Component -->
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
    'icon' => null,
    'iconPosition' => 'left'
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
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
    'icon' => null,
    'iconPosition' => 'left'
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
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
?>

<?php if($href): ?>
    <a href="<?php echo e($href); ?>" 
       class="<?php echo e($classes); ?>"
       <?php echo e($attributes); ?>>
        <?php if($icon && $iconPosition === 'left'): ?>
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php echo $icon; ?>

            </svg>
        <?php endif; ?>
        
        <?php echo e($slot); ?>

        
        <?php if($loading): ?>
            <svg class="animate-spin -mr-1 ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        <?php endif; ?>
        
        <?php if($icon && $iconPosition === 'right'): ?>
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php echo $icon; ?>

            </svg>
        <?php endif; ?>
    </a>
<?php else: ?>
    <button type="<?php echo e($type); ?>" 
            class="<?php echo e($classes); ?>"
            <?php echo e($disabled ? 'disabled' : ''); ?>

            <?php echo e($attributes); ?>>
        <?php if($icon && $iconPosition === 'left'): ?>
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php echo $icon; ?>

            </svg>
        <?php endif; ?>
        
        <?php echo e($slot); ?>

        
        <?php if($loading): ?>
            <svg class="animate-spin -mr-1 ml-2 h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        <?php endif; ?>
        
        <?php if($icon && $iconPosition === 'right'): ?>
            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <?php echo $icon; ?>

            </svg>
        <?php endif; ?>
    </button>
<?php endif; ?><?php /**PATH C:\Users\dktakyi001\Desktop\XTRA4U\resources\views/components/button.blade.php ENDPATH**/ ?>