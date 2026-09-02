<div class="max-w-4xl">
    {{-- Intro --}}
    <div class="mb-8 bg-gradient-to-r from-brand-deep-blue to-brand-bright-blue rounded-xl p-6 text-white">
        <h2 class="text-lg font-semibold">Platform Service Vendors</h2>
        <p class="mt-1 text-sm text-white/80">
            Choose which vendor's catalog powers each official XTRA4U service page
            (e.g. xtra4u.com/services/data-bundles). Customers who land there via the
            homepage buy from the vendor assigned here. This does not affect any
            vendor's own storefront link, which always shows that vendor's full catalog.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.settings.platform-service-vendors.update') }}">
        @csrf
        @method('PUT')

        <div class="bg-white border border-gray-200 rounded-xl shadow-sm divide-y divide-gray-100">
            @foreach ($platformServiceAssignments as $category => $assignedVendorId)
                @php
                    $label = $categoryConfig[$category]['label'] ?? \Illuminate\Support\Str::title(str_replace(['-', '_'], ' ', $category));
                    $description = $categoryConfig[$category]['description'] ?? null;
                    $choices = $platformServiceVendorChoices[$category] ?? collect();
                    $selected = old("vendor.{$category}", $assignedVendorId);
                @endphp
                <div class="p-5">
                    <label for="vendor_{{ $category }}" class="block text-sm font-semibold text-gray-900">
                        {{ $label }}
                    </label>
                    @if ($description)
                        <p class="mt-0.5 text-xs text-gray-500">{{ $description }}</p>
                    @endif

                    <select id="vendor_{{ $category }}"
                            name="vendor[{{ $category }}]"
                            class="mt-3 block w-full max-w-md rounded-lg border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue text-sm">
                        <option value="" @selected(!$selected)>— Not assigned —</option>
                        @foreach ($choices->sortByDesc('eligible') as $choice)
                            @php $v = $choice['vendor']; @endphp
                            <option value="{{ $v->id }}" @selected((string) $selected === (string) $v->id)>
                                {{ $v->name }} ({{ $v->vendor_code }}){{ $choice['hint'] ? ' — '.$choice['hint'] : '' }}
                            </option>
                        @endforeach
                    </select>

                    @error("vendor.{$category}")
                        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
                    @enderror

                    @if ($choices->where('eligible', true)->isEmpty())
                        <p class="mt-1.5 text-xs text-amber-600">
                            No approved vendor currently offers this service. You can still assign one now,
                            but customers will see an unavailable message until it has active products/settings.
                        </p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit"
                    class="inline-flex items-center px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                Save changes
            </button>
        </div>
    </form>
</div>
