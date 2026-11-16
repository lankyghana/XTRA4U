@extends('layouts.developers')
@section('title', 'Create developer account')
@section('content')
<div class="max-w-md mx-auto bg-white rounded-xl border border-gray-200 p-6">
    <h1 class="text-xl font-semibold mb-4">Create a developer account</h1>
    <form method="POST" action="{{ route('developers.register.submit') }}" class="space-y-4">
        @csrf
        <label class="block text-sm font-medium">Name
            <input name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Email
            <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Company <span class="text-gray-400 font-normal">(optional)</span>
            <input name="company" value="{{ old('company') }}" maxlength="120" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Password <span class="text-gray-400 font-normal">(10+ characters, letters and numbers)</span>
            <input type="password" name="password" required autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Confirm password
            <input type="password" name="password_confirmation" required autocomplete="new-password" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="flex items-start gap-2 text-sm">
            <input type="checkbox" name="terms" value="1" required class="mt-1">
            <span>I agree to use the API only for lawful purposes and to keep my API keys and webhook secrets confidential.</span>
        </label>
        <button class="w-full rounded-lg bg-[#00942C] text-white py-2.5 font-medium">Create account</button>
    </form>
    <p class="text-sm text-gray-500 mt-4">Already registered? <a class="underline" href="{{ route('developers.login') }}">Sign in</a></p>
</div>
@endsection
