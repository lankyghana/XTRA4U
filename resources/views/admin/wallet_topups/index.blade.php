@extends('layouts.app')

@section('title', 'Wallet Top-ups')

@section('content')
<div class="max-w-7xl mx-auto py-6">
    <h1 class="text-2xl font-bold mb-4">Wallet Top-ups Reconciliation</h1>

    <form method="GET" class="mb-4 flex gap-2">
        <select name="status" class="rounded border-gray-200 px-3 py-2">
            <option value="">All statuses</option>
            <option value="initiated" {{ request('status')==='initiated' ? 'selected' : '' }}>initiated</option>
            <option value="completed" {{ request('status')==='completed' ? 'selected' : '' }}>completed</option>
            <option value="failed" {{ request('status')==='failed' ? 'selected' : '' }}>failed</option>
        </select>
        <input type="text" name="gateway" placeholder="Gateway" value="{{ request('gateway') }}" class="rounded border-gray-200 px-3 py-2" />
        <input type="date" name="from" value="{{ request('from') }}" class="rounded border-gray-200 px-3 py-2" />
        <input type="date" name="to" value="{{ request('to') }}" class="rounded border-gray-200 px-3 py-2" />
        <button class="px-4 py-2 bg-purple-600 text-white rounded">Filter</button>
    </form>

    <div class="bg-white rounded shadow overflow-hidden">
        <table class="w-full text-left">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2">ID</th>
                    <th class="px-4 py-2">Vendor</th>
                    <th class="px-4 py-2">Amount</th>
                    <th class="px-4 py-2">Status</th>
                    <th class="px-4 py-2">Gateway</th>
                    <th class="px-4 py-2">Reference</th>
                    <th class="px-4 py-2">Created</th>
                    <th class="px-4 py-2">Completed</th>
                    <th class="px-4 py-2">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($topups as $t)
                    <tr class="border-t">
                        <td class="px-4 py-2">{{ $t->id }}</td>
                        <td class="px-4 py-2">{{ $t->vendor?->name }} ({{ $t->vendor_id }})</td>
                        <td class="px-4 py-2">GHS {{ number_format($t->amount, 2) }}</td>
                        <td class="px-4 py-2">{{ $t->status }}</td>
                        <td class="px-4 py-2">{{ $t->gateway }}</td>
                        <td class="px-4 py-2">{{ $t->reference }}</td>
                        <td class="px-4 py-2">{{ $t->created_at }}</td>
                        <td class="px-4 py-2">{{ $t->completed_at ?? '-' }}</td>
                        <td class="px-4 py-2">
                            <button type="button" class="px-2 py-1 bg-gray-100 rounded view-json" data-json='@json($t->gateway_response)'>View JSON</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $topups->links() }}</div>

    <div id="json-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center">
        <div class="bg-white max-w-2xl w-full p-4 rounded">
            <h2 class="font-bold mb-2">Gateway Response</h2>
            <pre id="json-content" class="whitespace-pre-wrap text-sm bg-gray-100 p-2 rounded max-h-96 overflow-auto"></pre>
            <div class="text-right mt-2">
                <button id="json-close" class="px-4 py-2 bg-gray-200 rounded">Close</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.view-json').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var json = this.getAttribute('data-json');
                document.getElementById('json-content').textContent = JSON.stringify(JSON.parse(json || '{}'), null, 2);
                document.getElementById('json-modal').classList.remove('hidden');
                document.getElementById('json-modal').classList.add('flex');
            });
        });
        document.getElementById('json-close').addEventListener('click', function () {
            document.getElementById('json-modal').classList.add('hidden');
            document.getElementById('json-modal').classList.remove('flex');
        });
    });
    </script>
</div>
@endsection
