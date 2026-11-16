@extends('layouts.developers')
@section('title', 'New application')
@section('content')
<div class="max-w-xl bg-white rounded-xl border border-gray-200 p-6">
    <h1 class="text-xl font-semibold mb-4">New application</h1>
    <form method="POST" action="{{ route('developers.applications.store') }}" class="space-y-4">
        @csrf
        <label class="block text-sm font-medium">Name
            <input name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">
        </label>
        <label class="block text-sm font-medium">Description <span class="text-gray-400 font-normal">(optional)</span>
            <textarea name="description" rows="3" maxlength="1000" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2">{{ old('description') }}</textarea>
        </label>
        <fieldset class="space-y-2">
            <legend class="text-sm font-medium">Integration type</legend>
            <label class="flex items-start gap-2 text-sm"><input type="radio" name="type" value="marketplace" class="mt-1" @checked(old('type', 'marketplace') === 'marketplace')>
                <span><b>Marketplace</b> — access offers from approved vendors across XTRA4U.</span></label>
            <label class="flex items-start gap-2 text-sm"><input type="radio" name="type" value="vendor" class="mt-1" @checked(old('type') === 'vendor')>
                <span><b>Vendor-bound</b> — access one vendor's store only. That vendor must approve your application. The vendor cannot be changed later.</span></label>
        </fieldset>
        <label class="block text-sm font-medium">Vendor store code <span class="text-gray-400 font-normal">(vendor-bound only; the code in the vendor's store link)</span>
            <input name="vendor_code" value="{{ old('vendor_code') }}" maxlength="40" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 uppercase">
        </label>
        <p class="text-xs text-gray-500">New applications start in the sandbox. Production access is requested later and approved by an administrator.</p>
        <button class="rounded-lg bg-[#00942C] text-white px-5 py-2.5 font-medium">Create application</button>
    </form>
</div>
@endsection
