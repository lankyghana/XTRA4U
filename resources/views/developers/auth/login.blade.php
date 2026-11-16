@extends('layouts.developers')
@section('title', 'Developer sign in')
@section('content')
<div class="max-w-md mx-auto bg-white rounded-xl border border-gray-200 p-6">
    <h1 class="text-xl font-semibold mb-4">Developer sign in</h1>
    <form method="POST" action="{{ route('developers.login.submit') }}" class="space-y-4">
        @csrf
        <label class="block text-sm font-medium">Email
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Password
            <input type="password" name="password" required autocomplete="current-password" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> Remember me</label>
        <button class="w-full rounded-lg bg-[#00942C] text-white py-2.5 font-medium">Sign in</button>
    </form>
    <p class="text-sm text-gray-500 mt-4">New here? <a class="underline" href="{{ route('developers.register') }}">Create an account</a></p>
</div>
@endsection
