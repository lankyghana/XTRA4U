@extends('layouts.app')

@section('title', ($title ?? 'Coming Soon') . ' - XTRA4U')
@section('description', 'Coming soon on XTRA4U')

@section('content')
<section class="min-h-[70vh] bg-gradient-to-br from-slate-50 via-white to-purple-50">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24">
        <div class="bg-white border border-gray-200 rounded-2xl shadow-lg p-8 sm:p-10">
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center">
                    <svg class="w-6 h-6 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">
                        {{ $title ?? 'Coming Soon' }}
                    </h1>
                    <p class="mt-2 text-sm sm:text-base text-gray-600">
                        {{ $subtitle ?? 'This page will be available soon.' }}
                    </p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        @php
                            $cta = $primaryCta ?? null;
                        @endphp

                        @if(is_array($cta) && !empty($cta['href']) && !empty($cta['label']))
                            <a href="{{ $cta['href'] }}"
                               class="inline-flex items-center px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                                {{ $cta['label'] }}
                            </a>
                        @else
                            <a href="{{ route('storefront.index') }}"
                               class="inline-flex items-center px-5 py-2.5 rounded-lg text-sm font-semibold text-white bg-brand-deep-blue hover:bg-brand-bright-blue transition-colors">
                                Back to Home
                            </a>
                        @endif
                    </div>

                    <p class="mt-6 text-xs text-gray-500">
                        If you need help immediately, please check the Home page for available services.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection
