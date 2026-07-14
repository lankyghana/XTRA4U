@extends('layouts.app')

@section('title', 'Service Unavailable - XTRA4U')
@section('description', 'This service is temporarily unavailable on XTRA4U')

@section('content')
<section class="min-h-[70vh] bg-gradient-to-br from-slate-50 via-white to-amber-50">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24">
        <div class="bg-white border border-gray-200 rounded-2xl shadow-lg p-8 sm:p-10">
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">
                        {{ $title ?? 'Service Temporarily Unavailable' }}
                    </h1>
                    <p class="mt-3 text-sm sm:text-base text-gray-600">
                        {{ $message ?? \App\Support\ServiceAvailability::message() }}
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="{{ $backHref ?? route('storefront.index') }}"
                           class="inline-flex items-center px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                            {{ $backLabel ?? 'Back to Store' }}
                        </a>
                    </div>

                    <p class="mt-6 text-xs text-gray-500">
                        Other services may still be available. Please check back soon.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
