{{--
    "Need Help?" WhatsApp block at the foot of every vendor store page.
    Renders nothing when the vendor has no phone number.
--}}
@props(['vendor', 'message' => null])

@if ($vendor?->phone_number)
    <section style="background-color: var(--x4-canvas-soft); border-top: 1px solid var(--x4-hairline); padding: 56px 0;">
        <x-storefront.reveal class="max-w-6xl mx-auto px-5">
            <div class="text-center" style="background-color: var(--x4-canvas); border: 1px solid var(--x4-hairline); border-radius: var(--x4-r-xl); box-shadow: var(--x4-shadow-1); padding: 40px 24px;">
                <h2 class="x4-display-md mb-3" style="color: var(--x4-ink);">Need Help?</h2>
                <p class="x4-body-lg mb-6" style="color: var(--x4-ink-sec);">
                    {{ $message ?? 'Contact '.$vendor->name.' if your order takes longer than 2 hours.' }}
                </p>
                <a
                    href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="x4-btn"
                    style="background-color: #22c55e; color: #fff; border: 1px solid #22c55e; padding: 13px 26px;"
                >
                    <x-storefront.icon name="whatsapp" class="w-4 h-4" />
                    Contact vendor: {{ $vendor->phone_number }}
                </a>
            </div>
        </x-storefront.reveal>
    </section>
@endif
