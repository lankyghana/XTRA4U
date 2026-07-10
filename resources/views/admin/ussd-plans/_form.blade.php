@php
    $plan = $plan ?? null;
    $liveSubscriptions = $liveSubscriptions ?? 0;
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="md:col-span-2">
        <label for="name" class="block text-sm font-medium text-gray-700 mb-2">Plan Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="name" required maxlength="100"
               value="{{ old('name', $plan->name ?? '') }}"
               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
               placeholder="e.g. Starter">
    </div>

    <div class="md:col-span-2">
        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
        <textarea name="description" id="description" rows="2" maxlength="1000"
                  class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                  placeholder="Shown to vendors when comparing plans.">{{ old('description', $plan->description ?? '') }}</textarea>
    </div>

    <div>
        <label for="price" class="block text-sm font-medium text-gray-700 mb-2">Price (GHS) <span class="text-red-500">*</span></label>
        <input type="number" name="price" id="price" required step="0.01" min="0" max="999999.99"
               value="{{ old('price', $plan->price ?? '') }}"
               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
               placeholder="80.00">
    </div>

    <div>
        <label for="included_sessions" class="block text-sm font-medium text-gray-700 mb-2">Included Sessions <span class="text-red-500">*</span></label>
        <input type="number" name="included_sessions" id="included_sessions" required min="1" max="10000000"
               value="{{ old('included_sessions', $plan->included_sessions ?? '') }}"
               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
               placeholder="5000">
        <p class="mt-1 text-xs text-gray-500">One session is consumed each time a customer dials in.</p>
    </div>

    <div>
        <label for="duration_days" class="block text-sm font-medium text-gray-700 mb-2">Duration (days) <span class="text-red-500">*</span></label>
        <input type="number" name="duration_days" id="duration_days" required min="1" max="3650"
               value="{{ old('duration_days', $plan->duration_days ?? 30) }}"
               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue">
    </div>

    <div>
        <label for="extension_code" class="block text-sm font-medium text-gray-700 mb-2">Extension Code <span class="text-red-500">*</span></label>
        <input type="text" name="extension_code" id="extension_code" required inputmode="numeric" pattern="\d{1,10}"
               value="{{ old('extension_code', $plan->extension_code ?? '') }}"
               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
               placeholder="45">
        <p class="mt-1 text-xs text-gray-500">
            Digits only. Vendors on this plan dial
            <code>{{ $baseCode ?: '*203*' }}&lt;ext&gt;*&lt;vendor id&gt;#</code>.
        </p>
        @if ($liveSubscriptions > 0)
            <p class="mt-1 text-xs text-amber-600">
                {{ $liveSubscriptions }} live {{ Str::plural('subscription', $liveSubscriptions) }} already hold a code from this plan.
                Changing the extension only affects codes issued from now on.
            </p>
        @endif
    </div>

    <div>
        <label for="display_order" class="block text-sm font-medium text-gray-700 mb-2">Display Order</label>
        <input type="number" name="display_order" id="display_order" min="0" max="65535"
               value="{{ old('display_order', $plan->display_order ?? 0) }}"
               class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue">
        <p class="mt-1 text-xs text-gray-500">Lower numbers appear first to vendors.</p>
    </div>

    <div class="md:col-span-2">
        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Internal Notes</label>
        <textarea name="notes" id="notes" rows="2" maxlength="2000"
                  class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                  placeholder="Not shown to vendors.">{{ old('notes', $plan->notes ?? '') }}</textarea>
    </div>
</div>

<div class="mt-6 flex items-center justify-between p-4 bg-gray-50 rounded-lg border border-gray-200">
    <div>
        <p class="text-sm font-medium text-gray-900">Active</p>
        <p class="text-xs text-gray-500 mt-0.5">Inactive plans cannot be purchased. Existing subscriptions keep running.</p>
    </div>
    <label class="relative inline-flex items-center cursor-pointer ml-4 flex-shrink-0">
        <input type="checkbox" name="is_active" value="1" class="sr-only peer"
               {{ old('is_active', $plan->is_active ?? true) ? 'checked' : '' }}>
        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-brand-deep-blue/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-brand-deep-blue"></div>
    </label>
</div>
