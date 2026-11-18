@extends('layouts.developers')
@section('title', 'New application')
@section('content')
<div class="max-w-xl bg-white rounded-xl border border-gray-200 p-6">
    <h1 class="text-xl font-semibold mb-4">New application</h1>
    <form method="POST" action="{{ route('developers.applications.store') }}" class="space-y-4">
        @csrf
        <label class="block text-sm font-medium">Name
            <input name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Description <span class="text-gray-400 font-normal">(optional)</span>
            <textarea name="description" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">{{ old('description') }}</textarea>
        </label>
        <p class="text-sm text-gray-600 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">This application will belong to your store <b>{{ auth('vendor')->user()->name }}</b> and can only see and sell your own products. This can't be changed later.</p>
        <button class="rounded-lg bg-[#00942C] text-white px-5 py-2.5 font-medium">Create application</button>
    </form>
</div>
@endsection
