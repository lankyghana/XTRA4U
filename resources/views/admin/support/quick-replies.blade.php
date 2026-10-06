@extends('layouts.admin')

@section('title', 'Support Quick Replies - Admin Portal')

@section('content')
<x-admin-layout title="Quick Replies" subtitle="Reusable support replies. Admins can edit the text before sending." active="support">
    <div class="space-y-4">
        <a href="{{ route('admin.support.index') }}" class="text-sm text-gray-600 hover:underline">&larr; Support inbox</a>

        @if (session('status'))
            <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ $editing ? route('admin.support.quick-replies.update', $editing->id) : route('admin.support.quick-replies.store') }}"
              class="bg-white rounded-xl shadow p-4 space-y-3">
            @csrf
            @if ($editing) @method('PUT') @endif
            <h2 class="font-semibold text-gray-900">{{ $editing ? 'Edit quick reply' : 'New quick reply' }}</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Title</label>
                    <input name="title" value="{{ old('title', $editing?->title) }}" required maxlength="120" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">Category</label>
                    <select name="category_id" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                        <option value="">General (all categories)</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id', $editing?->category_id) === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Content</label>
                <textarea name="body" rows="3" required maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm">{{ old('body', $editing?->body) }}</textarea>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing?->is_active ?? true))> Active
            </label>
            <div class="flex gap-2">
                <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-4 py-2 text-sm bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet">{{ $editing ? 'Save changes' : 'Add quick reply' }}</button>
                @if ($editing)
                    <a href="{{ route('admin.support.quick-replies.index') }}" class="px-4 py-2 text-sm text-gray-700 bg-gray-100 rounded-lg">Cancel</a>
                @endif
            </div>
        </form>

        <div class="bg-white rounded-xl shadow divide-y divide-gray-100">
            @forelse ($replies as $reply)
                <div class="p-4 flex items-start gap-3 {{ $reply->is_active ? '' : 'opacity-60' }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-medium text-gray-900">{{ $reply->title }}
                            <span class="ml-1 text-xs font-normal text-gray-500">{{ $reply->category?->name ?? 'General' }}</span>
                            @unless ($reply->is_active)<span class="ml-1 text-xs text-gray-500">(inactive)</span>@endunless
                        </p>
                        <p class="text-sm text-gray-600 mt-0.5 whitespace-pre-wrap">{{ $reply->body }}</p>
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-1.5 shrink-0">
                        <form method="POST" action="{{ route('admin.support.quick-replies.move', $reply->id) }}">@csrf<input type="hidden" name="direction" value="up">
                            <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-brand-violet" aria-label="Move up">↑</button></form>
                        <form method="POST" action="{{ route('admin.support.quick-replies.move', $reply->id) }}">@csrf<input type="hidden" name="direction" value="down">
                            <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-brand-violet" aria-label="Move down">↓</button></form>
                        <a href="{{ route('admin.support.quick-replies.edit', $reply->id) }}" class="px-2 py-1 text-xs bg-gray-100 rounded">Edit</a>
                        <form method="POST" action="{{ route('admin.support.quick-replies.toggle', $reply->id) }}">@csrf
                            <button class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-3 py-1.5 text-xs border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:ring-brand-violet">{{ $reply->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                        <form method="POST" action="{{ route('admin.support.quick-replies.destroy', $reply->id) }}" onsubmit="return confirm('Delete this quick reply?')">@csrf @method('DELETE')
                            <button class="px-2 py-1 text-xs text-red-700 bg-red-50 rounded">Delete</button></form>
                    </div>
                </div>
            @empty
                <p class="p-8 text-center text-sm text-gray-500">No quick replies yet.</p>
            @endforelse
        </div>
    </div>
</x-admin-layout>
@endsection
