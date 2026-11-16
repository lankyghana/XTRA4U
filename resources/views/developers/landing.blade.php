@extends('layouts.developers')
@section('title', 'Developer Portal')
@section('content')
<div class="max-w-2xl mx-auto text-center py-12">
    <h1 class="text-3xl font-semibold mb-3">Build on XTRA4U</h1>
    <p class="text-gray-600 mb-8">Create an application, get API keys and receive signed webhooks for the data bundle orders your software places on XTRA4U. Start in the sandbox; production access is reviewed by our team.</p>
    <div class="flex justify-center gap-3">
        <a href="{{ route('developers.register') }}" class="rounded-lg bg-[#00942C] text-white px-5 py-2.5 font-medium">Create developer account</a>
        <a href="{{ route('developers.login') }}" class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 font-medium">Sign in</a>
    </div>
</div>
@endsection
