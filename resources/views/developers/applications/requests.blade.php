@extends('layouts.vendor')
@section('title', 'Request logs - XTRA4U')
@section('content')
<x-developers.shell title="Request logs" subtitle="Every API request made with this application's keys. Credentials and request bodies are never logged." tab="applications" :back="['url' => route('developers.applications.show', $app), 'label' => $app->name]">
    <x-developers.section :flush="true">
        @include('developers.partials.request-rows', ['rows' => $requests, 'time' => 'full', 'empty' => 'No requests yet'])
    </x-developers.section>
    <div>{{ $requests->links() }}</div>
</x-developers.shell>
@endsection
