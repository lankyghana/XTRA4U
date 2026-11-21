{{--
    customer/assistant.blade.php

    Public Customer Assistant (M5.6). Guest-accessible, read-only, stateless. Every piece of assistant text is rendered with
    x-text (escaped); there is no x-html / innerHTML / markdown renderer, and the only links are the server-built service-page
    links returned in `links` (also re-checked client-side to be same-site /services/ paths). The conversation lives ONLY in
    the `messages` array in page memory: no storage API is used, and each request carries just the current message.
--}}
@extends('layouts.app')

@section('title', 'Ask XTRA4U - XTRA4U')
@section('description', 'Ask questions about XTRA4U services and find products you can buy.')

@section('body-class', 'x4')

@php
    $mainStoreVendor = \App\Support\MainStore::vendor();
    $shopUrl = $mainStoreVendor
        ? route('storefront.vendor', ['vendor' => $mainStoreVendor->vendor_code])
        : route('checkout.show');
@endphp

@section('site-header')
    <x-storefront.header :shop-url="$shopUrl" />
@endsection

@section('site-footer')
    <x-storefront.footer :shop-url="$shopUrl" />
@endsection

@section('content')
<div class="x4-page" style="padding-top: 64px;">
    <section style="background-color: var(--x4-canvas-soft); min-height: calc(100vh - 64px);">
        <div class="max-w-2xl mx-auto px-4 sm:px-5" style="padding-top: 40px; padding-bottom: 64px;">
            <h1 class="x4-heading-lg mb-2" style="color: var(--x4-ink-strong);">Ask XTRA4U</h1>
            <p class="x4-body-md mb-5" style="color: var(--x4-ink-body);">
                Ask about our services and find products you can buy, with their current prices.
            </p>

            @unless($available)
                <div class="x4-panel" style="padding: 24px;" role="status" data-assistant-state="unavailable">
                    <h2 class="font-semibold mb-1" style="color: var(--x4-ink);">The assistant is not available right now</h2>
                    <p class="text-sm" style="color: var(--x4-ink-body);">
                        You can still browse our services from the <a href="{{ route('storefront.index') }}" class="underline">home page</a>.
                    </p>
                </div>
            @else
                <div x-data="customerAssistant({{ (int) $maxChars }}, @js(route('customer.assistant.message')))" data-assistant-state="available" class="space-y-4">
                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                        This assistant only gives information. It cannot place orders, take payments or look up an order or payment.
                        To buy, use the service page and the normal checkout. It does not remember earlier messages: the conversation
                        disappears when you refresh or close this page. Please do not type payment references, PINs, passwords or
                        other personal details. Check prices and details on the service page before you pay.
                    </div>

                    <div class="x4-panel" style="padding: 0;" aria-live="polite">
                        <template x-if="messages.length === 0">
                            <div class="px-4 py-5">
                                <p class="text-sm mb-3" style="color: var(--x4-ink-body);">Try a question:</p>
                                <div class="flex flex-wrap gap-2">
                                    <template x-for="q in examples" :key="q">
                                        <button type="button" @click="draft = q; $refs.box.focus()"
                                                class="rounded-full border border-gray-300 px-3 py-1 text-xs text-gray-700 hover:bg-gray-50"
                                                x-text="q"></button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-for="(m, i) in messages" :key="i">
                            <div class="px-4 py-3 border-b border-gray-100 last:border-b-0">
                                <div class="text-xs font-medium mb-1"
                                     :class="m.role === 'error' ? 'text-red-700' : (m.role === 'user' ? 'text-gray-500' : 'text-violet-700')"
                                     x-text="m.role === 'user' ? 'You' : (m.role === 'error' ? 'Problem' : 'XTRA4U Assistant')"></div>
                                <div class="text-sm whitespace-pre-wrap break-words" style="color: var(--x4-ink);" x-text="m.text"></div>
                                <template x-if="m.links && m.links.length">
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <template x-for="l in m.links" :key="l.path">
                                            <a :href="l.path" class="rounded-lg bg-violet-600 text-white px-3 py-1.5 text-xs font-medium hover:bg-violet-700"
                                               x-text="'Open ' + l.label"></a>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div x-show="loading" x-cloak class="px-4 py-3 text-sm text-gray-500" role="status">Looking that up&hellip;</div>
                    </div>

                    <form @submit.prevent="send()" class="space-y-2">
                        <label for="assistant-message" class="sr-only">Your question</label>
                        <textarea id="assistant-message" x-ref="box" x-model="draft" rows="3" :maxlength="max" autocomplete="off" required
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                                  placeholder="For example: What is the cheapest MTN data bundle?"
                                  @keydown.ctrl.enter.prevent="send()"></textarea>
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs text-gray-500"><span x-text="draft.length"></span>/<span x-text="max"></span></span>
                            <div class="flex items-center gap-2">
                                <button type="button" @click="reset()" x-show="messages.length > 0" x-cloak :disabled="loading"
                                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 disabled:opacity-50">
                                    Start over
                                </button>
                                <button type="submit" :disabled="loading || draft.trim().length === 0"
                                        class="rounded-lg bg-violet-600 text-white px-4 py-2 text-sm disabled:opacity-50">
                                    <span x-show="!loading">Ask</span><span x-show="loading" x-cloak>Please wait&hellip;</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <script>
                    document.addEventListener('alpine:init', () => {
                        // The conversation lives ONLY in this array (page memory). No storage API is used anywhere.
                        Alpine.data('customerAssistant', (max, url) => ({
                            max, url, draft: '', loading: false, messages: [],
                            examples: [
                                'What services does XTRA4U offer?',
                                'What is the cheapest MTN data bundle?',
                                'Which result checkers can I buy?',
                                'How do I buy a data bundle?',
                            ],
                            reset() {
                                if (this.loading) return;
                                this.messages = [];
                                this.draft = '';
                            },
                            safeLinks(links) {
                                // Only same-site service pages, whatever the response says.
                                return (Array.isArray(links) ? links : [])
                                    .filter(l => l && typeof l.path === 'string' && l.path.startsWith('/services/') && typeof l.label === 'string')
                                    .slice(0, 4);
                            },
                            async send() {
                                const text = this.draft.trim();
                                if (!text || this.loading) return;
                                this.messages.push({ role: 'user', text });
                                this.draft = '';
                                this.loading = true;
                                try {
                                    const res = await fetch(this.url, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                            'X-Requested-With': 'XMLHttpRequest',
                                        },
                                        body: JSON.stringify({ message: text }),
                                    });
                                    const data = await res.json().catch(() => null);
                                    if (res.ok && data && data.ok && typeof data.answer === 'string') {
                                        this.messages.push({ role: 'assistant', text: data.answer, links: this.safeLinks(data.links) });
                                    } else {
                                        const msg = data && data.error && typeof data.error.message === 'string'
                                            ? data.error.message
                                            : (res.status === 419 ? 'Your session expired. Refresh the page and try again.'
                                                : (res.status === 429 ? 'You are asking too quickly. Please wait a moment and try again.'
                                                    : 'The assistant could not answer right now.'));
                                        this.messages.push({ role: 'error', text: msg });
                                    }
                                } catch (e) {
                                    this.messages.push({ role: 'error', text: 'Could not reach the assistant. Check your connection and try again.' });
                                } finally {
                                    this.loading = false;
                                }
                            },
                        }));
                    });
                </script>
            @endunless
        </div>
    </section>
</div>
@endsection
