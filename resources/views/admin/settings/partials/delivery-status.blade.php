<div class="max-w-4xl"
     x-data="{
        active: {{ $deliveryActive ? 'true' : 'false' }},
        level: '{{ $deliveryLevel }}',
        message: @js($deliveryMessage),
        levels: {
            fast:   { label: 'Fast delivery', badge: 'bg-green-100 text-green-700', ring: 'peer-checked:border-green-500 peer-checked:ring-green-500/30', dot: 'bg-green-500' },
            normal: { label: 'Normal',        badge: 'bg-blue-100 text-blue-700',   ring: 'peer-checked:border-blue-500 peer-checked:ring-blue-500/30',   dot: 'bg-blue-500' },
            slow:   { label: 'Slow delivery', badge: 'bg-amber-100 text-amber-700', ring: 'peer-checked:border-amber-500 peer-checked:ring-amber-500/30', dot: 'bg-amber-500' },
        }
     }">

    <div class="mb-4 flex justify-end">
        <span class="text-sm" :class="active ? 'text-green-600' : 'text-gray-400'">
            <svg class="inline w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <span x-text="active ? 'Notice is live' : 'Notice is off'"></span>
        </span>
    </div>

    {{-- Intro --}}
    <div class="mb-8 bg-gradient-to-r from-brand-deep-blue to-brand-bright-blue rounded-xl p-6 text-white">
        <h2 class="text-lg font-semibold">Tell vendors how delivery is running</h2>
        <p class="mt-1 text-sm text-white/80">
            When active, this notice pops up on each vendor's main dashboard the next time they visit.
            They can dismiss it and continue. Editing the notice re-shows it to everyone.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.settings.delivery-status.update') }}">
        @csrf
        @method('PUT')

        {{-- Active toggle --}}
        <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-5 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-gray-900">Show this notice to vendors</p>
                <p class="mt-0.5 text-xs text-gray-500">Turn off to hide it without deleting the message.</p>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <span class="text-xs font-medium" :class="active ? 'text-green-600' : 'text-gray-400'" x-text="active ? 'On' : 'Off'"></span>
                <input type="checkbox" name="active" value="1" x-model="active"
                       class="h-5 w-9 appearance-none rounded-full bg-gray-300 checked:bg-green-500 relative cursor-pointer transition-colors
                              before:content-[''] before:absolute before:top-0.5 before:left-0.5 before:h-4 before:w-4 before:rounded-full before:bg-white before:transition-transform checked:before:translate-x-4">
            </div>
        </div>

        {{-- Level selector --}}
        <div class="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm p-5">
            <p class="text-sm font-semibold text-gray-900">Delivery speed</p>
            <p class="mt-0.5 text-xs text-gray-500">Sets the colour and icon vendors see.</p>
            <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-3">
                @foreach (['fast' => 'Fast delivery', 'normal' => 'Normal', 'slow' => 'Slow delivery'] as $value => $label)
                    <label class="relative cursor-pointer">
                        <input type="radio" name="level" value="{{ $value }}" x-model="level" class="peer sr-only">
                        <div class="rounded-xl border-2 border-gray-200 ring-4 ring-transparent p-4 transition-all hover:border-gray-300"
                             :class="levels['{{ $value }}'].ring">
                            <span class="inline-block w-2.5 h-2.5 rounded-full mb-2" :class="levels['{{ $value }}'].dot"></span>
                            <p class="text-sm font-semibold text-gray-900">{{ $label }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        {{-- Message --}}
        <div class="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm p-5">
            <label for="delivery-message" class="block text-sm font-semibold text-gray-900">Notice message</label>
            <p class="mt-0.5 text-xs text-gray-500">What vendors read. Keep it short and clear.</p>
            <textarea id="delivery-message" name="message" rows="3" maxlength="500" x-model="message"
                      placeholder="e.g. Data deliveries are slower than usual today due to a network provider issue. Thank you for your patience."
                      class="mt-3 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue text-sm">{{ old('message', $deliveryMessage) }}</textarea>
        </div>

        {{-- Live preview --}}
        <div class="mt-6">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">Vendor preview</p>
            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 flex justify-center">
                <div class="w-full max-w-sm bg-white rounded-2xl shadow-xl overflow-hidden">
                    <div class="h-1.5 w-full" :class="levels[level].dot"></div>
                    <div class="p-5">
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full" :class="levels[level].badge">
                                <span class="w-1.5 h-1.5 rounded-full" :class="levels[level].dot"></span>
                                <span x-text="levels[level].label"></span>
                            </span>
                        </div>
                        <p class="mt-3 text-sm text-gray-700 whitespace-pre-line" x-text="message || 'Your message will appear here.'"></p>
                        <button type="button" class="mt-4 w-full rounded-xl bg-brand-deep-blue text-white text-sm font-semibold py-2.5">Got it — Continue</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit"
                    class="inline-flex items-center px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                Save changes
            </button>
        </div>
    </form>
</div>
