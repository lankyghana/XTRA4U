@extends('layouts.admin')

@section('title', 'New USSD Plan - XTRA4U Admin')

@section('content')
<x-admin-layout title="New USSD Plan" subtitle="Create a subscription plan vendors can purchase" active="ussd-plans">
    <div class="max-w-4xl">
        @if ($errors->any())
            <div class="mb-6 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">
                @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
            </div>
        @endif

        <form action="{{ route('admin.ussd-plans.store') }}" method="POST"
              class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            @csrf

            @include('admin.ussd-plans._form', ['plan' => null, 'baseCode' => $baseCode])

            <div class="pt-6 mt-6 border-t border-gray-200 flex justify-end gap-3">
                <a href="{{ route('admin.ussd-plans.index') }}"
                   class="px-6 py-3 text-sm font-semibold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">
                    Cancel
                </a>
                <button type="submit"
                        class="px-6 py-3 bg-brand-deep-blue text-white text-sm font-semibold rounded-lg shadow-sm hover:bg-brand-bright-blue transition-colors">
                    Create Plan
                </button>
            </div>
        </form>
    </div>
</x-admin-layout>
@endsection
