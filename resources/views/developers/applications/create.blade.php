@extends('layouts.vendor')
@section('title', 'New application - XTRA4U')
@section('content')
<x-developers.shell title="New application" subtitle="Applications group your API keys and webhooks" tab="applications" :back="['url' => route('developers.applications.index'), 'label' => 'Applications']">
    <x-developers.section title="Application details" class="max-w-2xl">
        <form method="POST" action="{{ route('developers.applications.store') }}" class="space-y-4">
            @csrf
            <div>
                <label for="app-name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="app-name" name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1 w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-violet focus:border-brand-violet">
            </div>
            <div>
                <label for="app-description" class="block text-sm font-medium text-gray-700">Description <span class="text-gray-400 font-normal">(optional)</span></label>
                <textarea id="app-description" name="description" rows="3" maxlength="1000" class="mt-1 w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-violet focus:border-brand-violet">{{ old('description') }}</textarea>
            </div>
            <p class="text-sm text-gray-600 rounded-lg bg-gray-50 border border-gray-200 px-3 py-2">This application will belong to your store <b>{{ auth('vendor')->user()->name }}</b> and can only see and sell your own products. This can't be changed later.</p>
            <x-button type="submit">Create application</x-button>
        </form>
    </x-developers.section>
</x-developers.shell>
@endsection
