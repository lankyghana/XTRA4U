@extends('layouts.vendor')

@section('title', 'Assistant - XTRA4U')

@section('content')
<x-vendor-layout :vendor="$vendor" title="Assistant" subtitle="Ask questions about your own sales, orders, products and earnings" active="assistant">
    <div class="max-w-3xl space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-gray-900">Vendor Assistant</h2>
            <p class="mt-1 text-sm text-gray-600">
                Ask about your own business: how much you sold, your orders and their status, which products are on sale,
                your earnings and the commission deducted, and what your store page shows. Answers use the same figures as
                your dashboard.
            </p>
        </div>

        @unless($available)
            <div class="rounded-xl border border-gray-200 bg-white p-6" role="status" data-assistant-state="unavailable">
                <h3 class="font-semibold mb-1">The assistant is not available right now</h3>
                <p class="text-sm text-gray-600">This feature is switched off. Your <a href="{{ route('vendor.dashboard') }}" class="underline">dashboard</a> works as usual.</p>
            </div>
        @else
            <div x-data="vendorAssistant({{ (int) $maxChars }}, @js(route('vendor.assistant.message')))" data-assistant-state="available" class="space-y-4">
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                    This assistant only reads information. It cannot change orders, prices, products, your wallet or anything
                    else, and it cannot see customers' phone numbers or payment references. It does not remember earlier
                    questions and nothing you type is saved: the conversation disappears when you refresh or close this page.
                    Do not paste customer details or passwords. Always check important figures on your dashboard.
                </div>

                <div class="rounded-xl border border-gray-200 bg-white" aria-live="polite">
                    <template x-if="messages.length === 0">
                        <div class="px-4 py-5">
                            <p class="text-sm text-gray-500 mb-3">Try a question (these are examples, not your results):</p>
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
                                 x-text="m.role === 'user' ? 'You' : (m.role === 'error' ? 'Problem' : 'Assistant')"></div>
                            <div class="text-sm whitespace-pre-wrap break-words" x-text="m.text"></div>
                        </div>
                    </template>
                    <div x-show="loading" x-cloak class="px-4 py-3 text-sm text-gray-500" role="status">Looking at your data&hellip;</div>
                </div>

                <form @submit.prevent="send()" class="space-y-2">
                    <label for="assistant-message" class="sr-only">Your question</label>
                    <textarea id="assistant-message" x-ref="box" x-model="draft" rows="3" :maxlength="max" autocomplete="off" required
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-violet-500"
                              placeholder="Ask a question about your sales, orders, products or earnings"
                              @keydown.ctrl.enter.prevent="send()"></textarea>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs text-gray-500"><span x-text="draft.length"></span>/<span x-text="max"></span></span>
                        <button type="submit" :disabled="loading || draft.trim().length === 0"
                                class="rounded-lg bg-violet-600 text-white px-4 py-2 text-sm disabled:opacity-50">
                            <span x-show="!loading">Ask</span><span x-show="loading" x-cloak>Please wait&hellip;</span>
                        </button>
                    </div>
                </form>
            </div>

            <script>
                document.addEventListener('alpine:init', () => {
                    // The conversation lives ONLY in this array (page memory). No storage API is used anywhere, and every
                    // request sends just the current question, never the earlier messages.
                    Alpine.data('vendorAssistant', (max, url) => ({
                        max, url, draft: '', loading: false, messages: [],
                        examples: [
                            'How much have I sold this month?',
                            'How many orders are pending?',
                            'Which products are active?',
                            'Show my earnings for this week.',
                            'Explain the commission deducted from my sales.',
                        ],
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
                                    this.messages.push({ role: 'assistant', text: data.answer });
                                } else {
                                    const msg = data && data.error && typeof data.error.message === 'string'
                                        ? data.error.message
                                        : (res.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'The assistant could not answer right now.');
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
</x-vendor-layout>
@endsection
