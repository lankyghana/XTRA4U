@extends('layouts.admin')

@section('title', 'Service Availability - XTRA4U Admin')
@section('description', 'Open or close storefront service categories for customers')

@section('content')
<x-admin-layout title="Service Availability" subtitle="Open or close customer purchasing for each service category" active="service-availability">
    @php
        $openCount = collect($statuses)->filter()->count();
        $totalCount = count($statuses);
    @endphp
    <x-slot name="actions">
        <span class="hidden sm:inline text-sm {{ $openCount === $totalCount ? 'text-green-600' : 'text-amber-600' }}">
            <svg class="inline w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            {{ $openCount }}/{{ $totalCount }} categories open
        </span>
    </x-slot>

    <div class="max-w-4xl">

        {{-- Flash messages --}}
        @if (session('success'))
            <div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4">
                <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Intro --}}
        <div class="mb-8 bg-gradient-to-r from-brand-deep-blue to-brand-bright-blue rounded-xl p-6 text-white">
            <h2 class="text-lg font-semibold">Control what customers can buy</h2>
            <p class="mt-1 text-sm text-white/80">
                Closing a category immediately stops customers from purchasing that service and shows them
                the message below. Vendors and admins are not affected and can keep using their dashboards.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.settings.service-availability.update') }}">
            @csrf
            @method('PUT')

            {{-- Per-category toggles --}}
            <div class="bg-white border border-gray-200 rounded-xl shadow-sm divide-y divide-gray-100">
                @foreach ($statuses as $category => $isOpen)
                    @php
                        $label = $categoryConfig[$category]['label'] ?? \Illuminate\Support\Str::title(str_replace(['-', '_'], ' ', $category));
                        $description = $categoryConfig[$category]['description'] ?? null;
                    @endphp
                    <label class="flex items-center justify-between gap-4 p-5 cursor-pointer hover:bg-gray-50">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">{{ $label }}</p>
                            @if ($description)
                                <p class="mt-0.5 text-xs text-gray-500">{{ $description }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            <span class="text-xs font-medium {{ $isOpen ? 'text-green-600' : 'text-red-500' }}"
                                  x-data
                                  x-text="$refs['toggle_{{ $category }}']?.checked ? 'Open' : 'Closed'">
                                {{ $isOpen ? 'Open' : 'Closed' }}
                            </span>
                            {{-- Checkbox present => open. Absent (unchecked) => closed. --}}
                            <input type="checkbox"
                                   name="open[{{ $category }}]"
                                   value="1"
                                   x-ref="toggle_{{ $category }}"
                                   @checked($isOpen)
                                   class="h-5 w-9 appearance-none rounded-full bg-gray-300 checked:bg-green-500 relative cursor-pointer transition-colors
                                          before:content-[''] before:absolute before:top-0.5 before:left-0.5 before:h-4 before:w-4 before:rounded-full before:bg-white before:transition-transform checked:before:translate-x-4">
                        </div>
                    </label>
                @endforeach
            </div>

            {{-- Custom closed message --}}
            <div class="mt-6 bg-white border border-gray-200 rounded-xl shadow-sm p-5">
                <label for="message" class="block text-sm font-semibold text-gray-900">
                    Message shown when a service is closed
                </label>
                <p class="mt-0.5 text-xs text-gray-500">
                    Displayed to customers who try to buy a closed service. Leave blank to use the default message.
                </p>
                <textarea id="message"
                          name="message"
                          rows="3"
                          maxlength="500"
                          placeholder="{{ \App\Support\ServiceAvailability::DEFAULT_MESSAGE }}"
                          class="mt-3 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-bright-blue focus:ring-brand-bright-blue text-sm">{{ old('message', $message === \App\Support\ServiceAvailability::DEFAULT_MESSAGE ? '' : $message) }}</textarea>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                    Save changes
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
@endsection
