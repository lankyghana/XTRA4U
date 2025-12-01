@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto mt-10">
    <h2 class="text-2xl font-bold mb-6">All Vendors</h2>
    <table class="min-w-full bg-white border">
        <thead>
            <tr>
                <th class="py-2 px-4 border-b">Name</th>
                <th class="py-2 px-4 border-b">Email</th>
                <th class="py-2 px-4 border-b">Phone</th>
                <th class="py-2 px-4 border-b">Status</th>
                <th class="py-2 px-4 border-b">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($vendors as $vendor)
            <tr>
                <td class="py-2 px-4 border-b">{{ $vendor->name }}</td>
                <td class="py-2 px-4 border-b">{{ $vendor->email }}</td>
                <td class="py-2 px-4 border-b">{{ $vendor->phone_number }}</td>
                <td class="py-2 px-4 border-b">{{ $vendor->is_approved ? 'Approved' : 'Pending/Suspended' }}</td>
                <td class="py-2 px-4 border-b">
                    @if($vendor->is_approved)
                    <form method="POST" action="{{ route('admin.vendor.suspend', $vendor->id) }}">
                        @csrf
                        <button type="submit" class="bg-red-500 text-white px-3 py-1 rounded">Suspend</button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
