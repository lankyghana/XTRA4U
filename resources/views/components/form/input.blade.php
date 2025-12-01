<!-- XTRA4U Form Input Component - Brand Compliant -->
@props([
    'label' => null,
    'name',
    'type' => 'text',
    'placeholder' => null,
    'required' => false,
    'error' => null,
    'helpText' => null,
    'value' => null
])

<div class="space-y-1">
    @if($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-gray-700">
            {{ $label }}
            @if($required)
                <span class="text-brand-error">*</span>
            @endif
        </label>
    @endif
    
    <div class="relative">
        @if($type === 'textarea')
            <textarea 
                id="{{ $name }}"
                name="{{ $name }}"
                placeholder="{{ $placeholder }}"
                {{ $required ? 'required' : '' }}
                rows="4"
                class="border border-gray-300 rounded-lg p-3 w-full focus:ring-2 focus:ring-brand-deep-blue focus:border-transparent transition-colors duration-200 {{ $error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : '' }}"
                {{ $attributes }}>{{ old($name, $value) }}</textarea>
        @elseif($type === 'select')
            <select 
                id="{{ $name }}"
                name="{{ $name }}"
                {{ $required ? 'required' : '' }}
                class="border border-gray-300 rounded-lg p-3 w-full focus:ring-2 focus:ring-brand-deep-blue focus:border-transparent transition-colors duration-200 {{ $error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : '' }}"
                {{ $attributes }}>
                {{ $slot }}
            </select>
        @else
            <input 
                type="{{ $type }}"
                id="{{ $name }}"
                name="{{ $name }}"
                value="{{ old($name, $value) }}"
                placeholder="{{ $placeholder }}"
                {{ $required ? 'required' : '' }}
                class="border border-gray-300 rounded-lg p-3 w-full focus:ring-2 focus:ring-brand-deep-blue focus:border-transparent transition-colors duration-200 {{ $error ? 'border-red-300 focus:ring-red-500 focus:border-red-500' : '' }}"
                {{ $attributes }}>
        @endif
        
        @if($error)
            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                <svg class="h-5 w-5 text-brand-error" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
        @endif
    </div>
    
    @if($error)
        <p class="text-sm text-brand-error">{{ $error }}</p>
    @endif
    
    @if($helpText && !$error)
        <p class="text-sm text-brand-medium-gray">{{ $helpText }}</p>
    @endif
</div>