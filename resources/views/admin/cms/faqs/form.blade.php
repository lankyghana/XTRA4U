@php $isNew = ! $faq->exists; @endphp

<x-admin-layout :title="$isNew ? 'New FAQ' : 'Edit FAQ'" subtitle="Answers support simple formatting: **bold**, *italic*, lists and links" active="cms-faqs">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.faqs.index')" variant="secondary" size="sm">Back to FAQs</x-button>
    </x-slot>

    @include('admin.cms._flash')

    <form method="POST" action="{{ $isNew ? route('admin.cms.faqs.store') : route('admin.cms.faqs.update', $faq) }}" class="max-w-3xl space-y-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" novalidate>
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div>
            <label for="category" class="block text-sm font-semibold text-gray-700">Category</label>
            <input id="category" name="category" type="text" list="faq-categories" maxlength="60" required value="{{ old('category', $faq->category) }}" class="mt-1 block w-full">
            <datalist id="faq-categories">
                @foreach ($categories as $c)<option value="{{ $c }}">@endforeach
            </datalist>
            <p class="mt-1 text-xs text-gray-500">Pick an existing category or type a new one.</p>
        </div>

        <div>
            <label for="question" class="block text-sm font-semibold text-gray-700">Question</label>
            <input id="question" name="question" type="text" maxlength="255" required value="{{ old('question', $faq->question) }}" class="mt-1 block w-full">
        </div>

        <x-admin.cms-markdown name="answer" :value="old('answer', $faq->answer)" label="Answer" :rows="8" />

        <div>
            <input type="hidden" name="is_active" value="0">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $faq->is_active))> Active (untick to hide it without deleting)
            </label>
        </div>

        <div class="flex justify-end gap-2">
            <x-button :href="route('admin.cms.faqs.index')" variant="secondary">Cancel</x-button>
            <x-button type="submit" variant="primary">{{ $isNew ? 'Create FAQ' : 'Save changes' }}</x-button>
        </div>
    </form>
</x-admin-layout>
