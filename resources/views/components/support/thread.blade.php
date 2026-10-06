@props([
    // Everything the Alpine component needs; built by the controller/view, contains no secrets.
    'config',
    'realm' => 'vendor', // vendor | admin
    'quick' => false,    // admin quick-reply picker
    'hideMessages' => false, // new-request page: composer only
])

@php
    $isAdmin = $realm === 'admin';
    $mineBubble = 'bg-brand-violet text-white';
    $sendBtn = 'bg-brand-violet hover:opacity-90';
@endphp

<div x-data="supportThread({{ \Illuminate\Support\Js::from($config) }})" x-init="init()" class="flex flex-col h-full min-h-0">
    {{ $before ?? '' }}

    {{-- Messages --}}
    <div @if ($hideMessages) x-show="false" @endif x-ref="scroller" class="flex-1 min-h-[45vh] max-h-[60vh] lg:max-h-none overflow-y-auto px-3 py-4 space-y-3 bg-gray-50" aria-live="polite">
        <div x-show="hasMoreBefore" class="text-center">
            <button type="button" @click="loadEarlier()" :disabled="loadingEarlier" class="text-xs text-gray-600 underline disabled:opacity-50">
                <span x-text="loadingEarlier ? 'Loading…' : 'Load earlier messages'"></span>
            </button>
        </div>

        <template x-if="messages.length === 0">
            <p class="text-center text-sm text-gray-500 py-10">No messages yet.</p>
        </template>

        <template x-for="m in messages" :key="m.id">
            <div :class="m.mine ? 'flex justify-end' : 'flex justify-start'">
                <div class="max-w-[85%] sm:max-w-[70%]">
                    <p class="text-[11px] text-gray-500 mb-0.5" :class="m.mine ? 'text-right' : ''">
                        <span class="font-medium" x-text="m.sender"></span> · <span x-text="m.time"></span>
                    </p>
                    <div class="rounded-2xl px-3 py-2 text-sm shadow-sm space-y-2 break-words"
                         :class="m.mine ? '{{ $mineBubble }}' : 'bg-white text-gray-900 border border-gray-200'">
                        <p x-show="m.body" x-text="m.body" class="whitespace-pre-wrap"></p>
                        <template x-for="a in m.attachments" :key="a.id">
                            <div>
                                <template x-if="a.kind === 'image'">
                                    <a :href="a.url" target="_blank" rel="noopener">
                                        <img :src="a.url" loading="lazy" alt="Image attachment" class="rounded-lg max-h-56 w-auto border border-black/10">
                                    </a>
                                </template>
                                <template x-if="a.kind === 'audio'">
                                    <div>
                                        <audio controls preload="none" :src="a.url" class="w-56 max-w-full"></audio>
                                        <p class="text-[11px] opacity-75 mt-0.5" x-show="a.duration" x-text="'Voice note · ' + formatDuration(a.duration)"></p>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Closed / cannot reply --}}
    <div x-show="!canReply" x-cloak class="px-4 py-3 text-sm text-gray-600 bg-gray-100 border-t border-gray-200">
        {{ $isAdmin ? 'This conversation is closed. Reopen it to reply.' : 'This conversation is closed. Please start a new support request if you still need help.' }}
    </div>

    {{-- Composer --}}
    <form x-show="canReply" x-cloak @submit.prevent="send()" class="border-t border-gray-200 bg-white p-3 space-y-2">
        @if ($quick)
            <div class="relative" @click.outside="quickOpen = false">
                <button type="button" @click="toggleQuick()" class="text-xs font-medium text-brand-violet hover:underline">Quick replies ▾</button>
                <div x-show="quickOpen" x-cloak class="absolute bottom-7 left-0 z-20 w-80 max-w-[90vw] bg-white border border-gray-200 rounded-lg shadow-lg">
                    <input type="text" x-model="quickSearch" placeholder="Search quick replies…" class="w-full px-3 py-2 text-sm border-b border-gray-200 rounded-t-lg focus:outline-none">
                    <ul class="max-h-56 overflow-y-auto">
                        <template x-for="r in filteredQuick()" :key="r.id">
                            <li><button type="button" @click="insertQuick(r)" class="w-full text-left px-3 py-2 hover:bg-gray-50">
                                <span class="block text-sm font-medium text-gray-900" x-text="r.title"></span>
                                <span class="block text-xs text-gray-500 line-clamp-2" x-text="r.body"></span>
                            </button></li>
                        </template>
                        <li x-show="quickLoaded && filteredQuick().length === 0" class="px-3 py-3 text-sm text-gray-500">No matching quick replies.</li>
                    </ul>
                </div>
            </div>
        @endif

        <p x-show="error" x-text="error" role="alert" class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-3 py-2"></p>

        {{-- Image previews --}}
        <div x-show="images.length" class="flex flex-wrap gap-2">
            <template x-for="(img, i) in images" :key="img.key">
                <div class="relative">
                    <img :src="img.url" alt="" class="h-16 w-16 object-cover rounded-lg border border-gray-200">
                    <button type="button" @click="removeImage(i)" class="absolute -top-1.5 -right-1.5 h-5 w-5 rounded-full bg-gray-800 text-white text-xs leading-5" aria-label="Remove image">×</button>
                </div>
            </template>
        </div>

        {{-- Recording / preview --}}
        <div x-show="recording" class="flex items-center gap-3 rounded-lg bg-red-50 border border-red-200 px-3 py-2">
            <span class="h-2.5 w-2.5 rounded-full bg-red-600 animate-pulse"></span>
            <span class="text-sm font-medium text-red-800">Recording <span x-text="formatDuration(seconds)"></span> / 3:00</span>
            <button type="button" @click="stopRecording()" class="ml-auto px-3 py-1 text-sm font-medium text-white bg-red-600 rounded-lg">Stop</button>
        </div>
        <div x-show="voiceUrl && !recording" class="flex flex-wrap items-center gap-2 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">
            <audio controls :src="voiceUrl" class="h-9 max-w-full"></audio>
            <span class="text-xs text-gray-500" x-text="formatDuration(voiceSeconds)"></span>
            <button type="button" @click="discardVoice()" class="ml-auto text-sm text-red-700 hover:underline">Discard</button>
        </div>

        <div class="flex items-end gap-2">
            <textarea x-model="text" x-ref="box" rows="2" maxlength="4000" placeholder="Type your message…"
                      @keydown.ctrl.enter.prevent="send()" @keydown.meta.enter.prevent="send()"
                      class="flex-1 min-w-0 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-violet resize-none"></textarea>

            <label class="shrink-0 cursor-pointer p-2 rounded-lg border border-gray-300 hover:bg-gray-50" title="Attach image">
                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="addImages($event)">
                <svg class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span class="sr-only">Attach image</span>
            </label>

            <button type="button" x-show="voiceSupported && !recording && !voiceUrl" @click="startRecording()" class="shrink-0 p-2 rounded-lg border border-gray-300 hover:bg-gray-50" title="Record voice note">
                <svg class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-14 0m7 7v3m-4 0h8M12 3a3 3 0 00-3 3v5a3 3 0 006 0V6a3 3 0 00-3-3z"/></svg>
                <span class="sr-only">Record voice note</span>
            </button>

            <button type="submit" :disabled="sending || recording" class="shrink-0 px-4 py-2 text-sm font-semibold text-white rounded-lg disabled:opacity-50 {{ $sendBtn }}">
                <span x-text="sending ? 'Sending…' : 'Send'"></span>
            </button>
        </div>

        <div x-show="sending && progress > 0 && progress < 100" class="h-1.5 w-full bg-gray-200 rounded-full overflow-hidden">
            <div class="h-full bg-green-500" :style="`width: ${progress}%`"></div>
        </div>
        <p x-show="voiceNote" x-text="voiceNote" class="text-xs text-gray-500"></p>
    </form>
</div>

@once
@push('scripts')
<script>
function supportThread(cfg) {
    return {
        messages: cfg.messages || [],
        lastId: cfg.lastId || 0,
        firstId: 0,
        hasMoreBefore: !!cfg.hasMoreBefore,
        canReply: !!cfg.canReply,
        messagesUrl: cfg.messagesUrl,
        sendUrl: cfg.sendUrl,
        quickUrl: cfg.quickUrl || null,
        pollMs: (cfg.pollSeconds || 8) * 1000,
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
        maxSeconds: 180,
        maxImages: cfg.maxImages || 4,

        extra: cfg.extra || {}, categories: cfg.categories || [],
        text: '', images: [], error: '', sending: false, progress: 0, voiceNote: '',
        token: '', loadingEarlier: false, timer: null, pollTimer: null,

        // voice
        voiceSupported: false, recording: false, seconds: 0, voiceBlob: null, voiceUrl: '', voiceSeconds: 0,
        recorder: null, stream: null, chunks: [], tick: null,

        // quick replies
        quickOpen: false, quickSearch: '', quickReplies: [], quickLoaded: false,

        init() {
            this.token = this.newToken();
            this.voiceSupported = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
            if (!this.voiceSupported) {
                this.voiceNote = 'Voice recording is not supported on this browser. You can type or attach an image instead.';
            }
            this.$nextTick(() => this.scrollToBottom());
            if (this.messagesUrl) this.pollTimer = setInterval(() => this.poll(), this.pollMs);
            window.addEventListener('beforeunload', () => this.releaseMic());
        },

        newToken() {
            return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
        },

        formatDuration(s) {
            s = Math.max(0, Math.round(s || 0));
            return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
        },

        scrollToBottom() {
            const el = this.$refs.scroller;
            if (el) el.scrollTop = el.scrollHeight;
        },

        merge(incoming, prepend = false) {
            const seen = new Set(this.messages.map(m => m.id));
            const fresh = incoming.filter(m => !seen.has(m.id));
            this.messages = prepend ? fresh.concat(this.messages) : this.messages.concat(fresh);
            return fresh.length;
        },

        async poll() {
            if (document.hidden) return;
            try {
                const res = await fetch(`${this.messagesUrl}?after=${this.lastId}&mark_read=1`, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin',
                });
                if ([401, 403, 404, 419].includes(res.status)) { clearInterval(this.pollTimer); return; }
                if (!res.ok) return;
                const data = await res.json();
                const el = this.$refs.scroller;
                const nearBottom = el ? (el.scrollHeight - el.scrollTop - el.clientHeight) < 120 : true;
                if (this.merge(data.messages) && nearBottom) this.$nextTick(() => this.scrollToBottom());
                if (data.last_id > this.lastId) this.lastId = data.last_id;
                this.canReply = !!data.can_reply;
            } catch (e) { /* transient network error: next tick retries */ }
        },

        async loadEarlier() {
            if (this.loadingEarlier || !this.messages.length) return;
            this.loadingEarlier = true;
            try {
                const el = this.$refs.scroller, prevHeight = el.scrollHeight;
                const res = await fetch(`${this.messagesUrl}?before=${this.messages[0].id}`, {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (res.ok) {
                    const data = await res.json();
                    this.merge(data.messages, true);
                    this.hasMoreBefore = data.has_more_before;
                    this.$nextTick(() => { el.scrollTop = el.scrollHeight - prevHeight; });
                }
            } finally { this.loadingEarlier = false; }
        },

        // ---- images ----
        addImages(ev) {
            this.error = '';
            for (const f of Array.from(ev.target.files || [])) {
                if (this.images.length >= this.maxImages) { this.error = `You can attach up to ${this.maxImages} images.`; break; }
                if (!['image/jpeg', 'image/png', 'image/webp'].includes(f.type)) { this.error = 'Only JPEG, PNG or WebP images are allowed.'; continue; }
                if (f.size > 5 * 1024 * 1024) { this.error = 'Each image must be 5 MB or smaller.'; continue; }
                this.images.push({ key: this.newToken(), file: f, url: URL.createObjectURL(f) });
            }
            ev.target.value = '';
        },
        removeImage(i) { URL.revokeObjectURL(this.images[i].url); this.images.splice(i, 1); },

        // ---- voice ----
        async startRecording() {
            this.error = '';
            if (!this.voiceSupported) return;
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                this.error = (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError'))
                    ? 'Microphone access was blocked. Allow microphone access for this site in your browser settings, then try again.'
                    : 'We could not find a working microphone on this device.';
                return;
            }
            const mime = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4']
                .find(t => window.MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(t));
            try {
                this.recorder = mime ? new MediaRecorder(this.stream, { mimeType: mime }) : new MediaRecorder(this.stream);
            } catch (e) {
                this.releaseMic(); this.error = 'Voice recording is not supported on this browser.'; return;
            }
            this.chunks = [];
            this.recorder.ondataavailable = ev => { if (ev.data && ev.data.size) this.chunks.push(ev.data); };
            this.recorder.onstop = () => {
                const type = this.recorder.mimeType || mime || 'audio/webm';
                this.voiceBlob = new Blob(this.chunks, { type });
                this.voiceSeconds = this.seconds;
                this.voiceUrl = URL.createObjectURL(this.voiceBlob);
                this.releaseMic();
            };
            this.seconds = 0; this.recording = true;
            this.recorder.start();
            this.tick = setInterval(() => { this.seconds++; if (this.seconds >= this.maxSeconds) this.stopRecording(); }, 1000);
        },
        stopRecording() {
            clearInterval(this.tick);
            this.recording = false;
            if (this.recorder && this.recorder.state !== 'inactive') this.recorder.stop();
        },
        discardVoice() {
            if (this.voiceUrl) URL.revokeObjectURL(this.voiceUrl);
            this.voiceBlob = null; this.voiceUrl = ''; this.voiceSeconds = 0;
        },
        releaseMic() {
            if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
        },

        // ---- quick replies (admin) ----
        async toggleQuick() {
            this.quickOpen = !this.quickOpen;
            if (this.quickOpen && !this.quickLoaded && this.quickUrl) {
                try {
                    const res = await fetch(this.quickUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    if (res.ok) this.quickReplies = (await res.json()).replies || [];
                } finally { this.quickLoaded = true; }
            }
        },
        filteredQuick() {
            const q = this.quickSearch.trim().toLowerCase();
            return q ? this.quickReplies.filter(r => (r.title + ' ' + r.body).toLowerCase().includes(q)) : this.quickReplies;
        },
        insertQuick(r) {
            this.text = this.text.trim() ? this.text.replace(/\s+$/, '') + '\n' + r.body : r.body;
            this.quickOpen = false;
            this.$nextTick(() => this.$refs.box && this.$refs.box.focus());
        },

        // ---- send ----
        send() {
            if (this.sending || this.recording) return;
            this.error = '';
            if (!this.text.trim() && !this.images.length && !this.voiceBlob) {
                this.error = 'Please type a message or attach an image or voice note.'; return;
            }
            const fd = new FormData();
            fd.append('message', this.text);
            fd.append('client_token', this.token);   // same token on retry => no duplicate
            Object.entries(this.extra).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) fd.append(k, v); });
            this.images.forEach(i => fd.append('images[]', i.file, i.file.name));
            if (this.voiceBlob) {
                const ext = this.voiceBlob.type.includes('mp4') ? 'm4a' : (this.voiceBlob.type.includes('ogg') ? 'ogg' : 'webm');
                fd.append('voice', this.voiceBlob, `voice.${ext}`);
                fd.append('voice_duration', this.voiceSeconds);
            }
            this.sending = true; this.progress = 0;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', this.sendUrl);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', this.csrf);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = e => { if (e.lengthComputable) this.progress = Math.round(e.loaded / e.total * 100); };
            xhr.onload = () => {
                this.sending = false;
                let body = {};
                try { body = JSON.parse(xhr.responseText); } catch (e) {}
                if ((xhr.status === 200 || xhr.status === 201) && body.redirect) {
                    window.location.href = body.redirect;   // new request created
                } else if (xhr.status === 200 || xhr.status === 201) {
                    if (body.message && this.merge([body.message])) this.lastId = Math.max(this.lastId, body.message.id);
                    this.canReply = body.can_reply !== false;
                    this.resetComposer();
                    this.$nextTick(() => this.scrollToBottom());
                } else if (xhr.status === 422) {
                    const errs = body.errors ? Object.values(body.errors).flat() : [];
                    this.error = errs[0] || body.message || 'Please check your message and try again.';
                } else if (xhr.status === 419) {
                    this.error = 'Your session expired. Please refresh the page and try again.';
                } else if (xhr.status === 429) {
                    this.error = 'You are sending too fast. Please wait a moment.';
                } else {
                    this.error = 'Could not send your message. Please try again.';
                }
            };
            xhr.onerror = () => { this.sending = false; this.error = 'Network problem. Your message was not lost; tap Send to retry.'; };
            xhr.send(fd);
        },
        resetComposer() {
            this.text = '';
            this.images.forEach(i => URL.revokeObjectURL(i.url));
            this.images = [];
            this.discardVoice();
            this.token = this.newToken();
            this.progress = 0;
        },
    };
}
</script>
@endpush
@endonce
