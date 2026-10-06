@extends('layouts.admin')

@section('title', 'Wallet Top-ups')

@section('content')
<x-admin-layout title="Wallet Top-ups" subtitle="Reconcile vendor wallet top-ups against the payment gateway." active="wallet-topups">
    <div class="space-y-4">
        <x-admin.filters :action="route('admin.wallet-topups.index')" :active="filled(request('status')) || filled(request('gateway')) || filled(request('from')) || filled(request('to'))">
            <label class="sr-only" for="topup-status">Status</label>
            <select id="topup-status" name="status" class="w-full sm:w-44">
                <option value="">All statuses</option>
                <option value="initiated" {{ request('status')==='initiated' ? 'selected' : '' }}>Initiated</option>
                <option value="completed" {{ request('status')==='completed' ? 'selected' : '' }}>Completed</option>
                <option value="failed" {{ request('status')==='failed' ? 'selected' : '' }}>Failed</option>
            </select>
            <label class="sr-only" for="topup-gateway">Gateway</label>
            <input id="topup-gateway" type="text" name="gateway" placeholder="Gateway" value="{{ request('gateway') }}" class="w-full sm:w-40" />
            <label class="sr-only" for="topup-from">From date</label>
            <input id="topup-from" type="date" name="from" value="{{ request('from') }}" class="w-full sm:w-40" />
            <label class="sr-only" for="topup-to">To date</label>
            <input id="topup-to" type="date" name="to" value="{{ request('to') }}" class="w-full sm:w-40" />
        </x-admin.filters>

        <x-admin.table :headers="['ID', 'Vendor', 'Amount', 'Status', 'Gateway', 'Reference', 'Created', 'Completed', '']">
            @forelse($topups as $t)
                <tr>
                    <td class="text-gray-500">{{ $t->id }}</td>
                    <td class="whitespace-nowrap text-gray-900">{{ $t->vendor?->name }} <span class="text-xs text-gray-400">#{{ $t->vendor_id }}</span></td>
                    <td class="whitespace-nowrap font-semibold text-gray-900">GHS {{ number_format($t->amount, 2) }}</td>
                    <td><x-admin.status :status="$t->status" /></td>
                    <td class="text-gray-700">{{ $t->gateway }}</td>
                    <td class="font-mono text-xs text-gray-600">{{ $t->reference }}</td>
                    <td class="whitespace-nowrap text-gray-500">{{ $t->created_at }}</td>
                    <td class="whitespace-nowrap text-gray-500">{{ $t->completed_at ?? '-' }}</td>
                    <td class="text-right">
                        <button type="button" class="view-json admin-link text-sm" data-json='@json($t->gateway_response)'>View response</button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><x-admin.empty title="No top-ups found" description="Try different filters, or wait for vendors to top up their wallets." /></td></tr>
            @endforelse
        </x-admin.table>

        @if ($topups->hasPages())
            <div class="flex justify-end">{{ $topups->links() }}</div>
        @endif
    </div>

    <div id="json-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-brand-dark/50 p-4" role="dialog" aria-modal="true" aria-labelledby="json-title">
        <div class="w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl">
            <h2 id="json-title" class="mb-3 text-base font-semibold text-gray-900">Gateway response</h2>
            <pre id="json-content" class="max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-100 p-3 text-sm"></pre>
            <div class="mt-4 text-right">
                <x-button type="button" variant="secondary" id="json-close">Close</x-button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var modal = document.getElementById('json-modal');
        function close() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
        document.querySelectorAll('.view-json').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var json = this.getAttribute('data-json');
                document.getElementById('json-content').textContent = JSON.stringify(JSON.parse(json || '{}'), null, 2);
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.getElementById('json-close').focus();
            });
        });
        document.getElementById('json-close').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    });
    </script>
</x-admin-layout>
@endsection
