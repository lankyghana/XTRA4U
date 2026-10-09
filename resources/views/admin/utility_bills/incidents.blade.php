@extends('layouts.admin')

@section('content')
<x-admin-layout title="Utility Bills Incidents" subtitle="Provider problems grouped into one alert each" active="utility-bill-sales">
    <div class="space-y-6">
        <a href="{{ route('admin.utility-bill-sales.index') }}" class="text-sm text-brand-violet hover:underline">&larr; Utility bill sales</a>

        <section class="space-y-3" aria-labelledby="active-incidents">
            <h2 id="active-incidents" class="text-sm font-semibold text-gray-900">Active</h2>
            @include('admin.utility_bills.partials.incident-rows', ['incidents' => $active, 'empty' => 'No active incidents. KiNG FLEXY is behaving normally.'])
        </section>

        <section class="space-y-3" aria-labelledby="recent-incidents">
            <h2 id="recent-incidents" class="text-sm font-semibold text-gray-900">Recently recovered</h2>
            @include('admin.utility_bills.partials.incident-rows', ['incidents' => $recent, 'empty' => 'No recovered incidents yet.'])
        </section>
    </div>
</x-admin-layout>
@endsection
