@extends('layouts.vendor')
@section('title', 'Assistant - XTRA4U')
@section('content')
<x-developers.shell title="Developer Assistant" subtitle="Ask about authentication, keys, scopes, rate limits, the sandbox, webhooks and errors" tab="assistant">
    <div class="max-w-3xl space-y-4">
        <p class="text-sm text-gray-600">
            Ask about the XTRA4U Developer API: authentication, API keys, scopes, rate limits, request and response formats,
            the sandbox, webhooks and common errors. It can also describe your own API applications (names, status, key
            environments and scopes).
        </p>

        @unless($available)
            <x-developers.section title="The assistant is not available right now" role="status" data-assistant-state="unavailable">
                <p class="text-sm text-gray-600">This feature is switched off. The <a href="{{ route('developers.dashboard') }}" class="text-brand-violet underline">Developer Portal</a> works as usual.</p>
            </x-developers.section>
        @else
            <div x-data="developerAssistant({{ (int) $maxChars }}, @js(route('developers.assistant.message')))" data-assistant-state="available" class="space-y-4">
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                    This assistant only reads information. It cannot change anything, place orders or see your API key values.
                    It does not remember earlier questions, and nothing you type is saved: the conversation disappears when you
                    refresh or close this page. Never paste real API keys or secrets.
                </div>

                <div class="rounded-xl border border-gray-200 bg-white overflow-hidden divide-y divide-gray-100" aria-live="polite">
                    <template x-if="messages.length === 0">
                        <p class="px-4 sm:px-5 py-6 text-sm text-gray-500">For example: "Which scope do I need to create an order?", "Why do I get MISSING_SCOPE?", or "Show my applications".</p>
                    </template>
                    <template x-for="(m, i) in messages" :key="i">
                        <div class="px-4 sm:px-5 py-3"
                             :class="m.role === 'assistant' ? 'bg-violet-50' : ''">
                            <div class="text-xs font-semibold mb-1"
                                 :class="m.role === 'error' ? 'text-red-700' : (m.role === 'user' ? 'text-gray-500' : 'text-brand-violet')"
                                 x-text="m.role === 'user' ? 'You' : (m.role === 'error' ? 'Problem' : 'Assistant')"></div>
                            <div class="text-sm text-gray-900 whitespace-pre-wrap break-words" x-text="m.text"></div>
                        </div>
                    </template>
                    <div x-show="loading" x-cloak class="px-4 sm:px-5 py-3 text-sm text-gray-500" role="status">Thinking&hellip;</div>
                </div>

                <form @submit.prevent="send()" class="space-y-2">
                    <label for="assistant-message" class="sr-only">Your question</label>
                    <textarea id="assistant-message" x-model="draft" rows="3" :maxlength="max" autocomplete="off" required
                              class="w-full px-4 py-2 text-sm border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-violet focus:border-brand-violet"
                              placeholder="Ask a question about the Developer API"
                              @keydown.ctrl.enter.prevent="send()"></textarea>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs text-gray-500"><span x-text="draft.length"></span>/<span x-text="max"></span></span>
                        <button type="submit" :disabled="loading || draft.trim().length === 0"
                                class="inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg bg-brand-violet text-white shadow-sm hover:bg-brand-violet-deep focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-violet-deep disabled:opacity-50 disabled:cursor-not-allowed">
                            <span x-show="!loading">Ask</span><span x-show="loading" x-cloak>Please wait&hellip;</span>
                        </button>
                    </div>
                </form>
            </div>

            <script>
                document.addEventListener('alpine:init', () => {
                    // Conversation lives ONLY in this array (page memory). No storage API is used anywhere, and every request
                    // sends just the current question, never the earlier messages.
                    Alpine.data('developerAssistant', (max, url) => ({
                        max, url, draft: '', loading: false, messages: [],
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
</x-developers.shell>
@endsection
