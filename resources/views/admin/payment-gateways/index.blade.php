@extends('layouts.admin')

@section('title', 'Payment Gateway Settings')

@section('content')
<x-admin-layout title="Payment Gateway Settings" subtitle="Manage payment gateway configurations and API settings" active="payment-gateways">
    <x-slot name="actions">
        <a href="{{ route('admin.payment-gateways.create') }}" 
           class="bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 font-medium">
            Add New Gateway
        </a>
    </x-slot>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @foreach($gatewayTypes as $typeKey => $typeName)
        <div class="bg-white rounded-lg shadow mb-6">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">{{ $typeName }}</h2>
            </div>

            @if(isset($gateways[$typeKey]) && $gateways[$typeKey]->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gateway</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Environment</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Default</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Configuration</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            @foreach($gateways[$typeKey] as $gateway)
                                @php
                                    // "Generic payments" are used for non-Order flows (e.g. AFA Registration).
                                    // Currently supported by Paystack + Flutterwave. Hubtel payment collection is not wired for this flow yet.
                                    $supportsGenericPayments = in_array($gateway->gateway_name, ['paystack', 'flutterwave'], true);
                                    $isPaymentCollectionType = ($typeKey === 'payment_collection');
                                @endphp
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    {{ $availableGateways[$gateway->gateway_name]['name'] ?? $gateway->gateway_name }}
                                                </div>
                                                <div class="text-sm text-gray-500">{{ $gateway->gateway_name }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            {{ $gateway->environment === 'live' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                            {{ ucfirst($gateway->environment) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <form method="POST" action="{{ route('admin.payment-gateways.toggle-active', $gateway) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="inline-flex px-2 py-1 text-xs font-semibold rounded-full border
                                                {{ $gateway->is_active ? 'bg-green-100 text-green-800 border-green-200 hover:bg-green-200' : 'bg-red-100 text-red-800 border-red-200 hover:bg-red-200' }}">
                                                {{ $gateway->is_active ? 'Active' : 'Inactive' }}
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if($gateway->is_default)
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                                Default
                                            </span>

                                            @if($isPaymentCollectionType && ! $supportsGenericPayments)
                                                <div class="mt-1 text-xs text-red-600">
                                                    Warning: AFA payments may fail on this gateway.
                                                </div>
                                            @endif
                                        @else
                                            <form method="POST" action="{{ route('admin.payment-gateways.set-default', $gateway) }}" class="inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit"
                                                        class="text-sm text-blue-600 hover:text-blue-800"
                                                        @if($isPaymentCollectionType && ! $supportsGenericPayments)
                                                            onclick="return confirm('This gateway is not yet wired for AFA (generic) payments. Regular checkout may work, but AFA Registration payments can fail. Continue setting as default?')"
                                                        @endif>
                                                    Set Default
                                                </button>
                                            </form>

                                            @if($isPaymentCollectionType && ! $supportsGenericPayments)
                                                <div class="mt-1 text-xs text-red-600">
                                                    AFA not supported on this gateway.
                                                </div>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center space-x-2">
                                            @if($gateway->isConfigured())
                                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                    Configured
                                                </span>
                                            @else
                                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                    Incomplete
                                                </span>
                                            @endif
                                            <button onclick="testGateway({{ $gateway->id }})" 
                                                    class="text-sm text-indigo-600 hover:text-indigo-800">
                                                Test
                                            </button>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <a href="{{ route('admin.payment-gateways.edit', $gateway) }}" 
                                               class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                            
                                            @unless($gateway->is_default && $gateways[$typeKey]->where('is_active', true)->count() === 1)
                                                <form method="POST" action="{{ route('admin.payment-gateways.destroy', $gateway) }}" 
                                                      onsubmit="return confirm('Are you sure you want to delete this gateway configuration?')" class="inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                                </form>
                                            @endunless
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="px-6 py-4 text-center text-gray-500">
                    No gateways configured for {{ strtolower($typeName) }}.
                    <a href="{{ route('admin.payment-gateways.create') }}" class="text-blue-600 hover:text-blue-800">
                        Add one now
                    </a>
                </div>
            @endif
        </div>
    @endforeach

    <!-- Gateway Features Info -->
    <div class="bg-blue-50 rounded-lg p-6 mt-6">
        <h3 class="text-lg font-semibold text-blue-900 mb-4">Gateway Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @foreach($availableGateways as $gatewayKey => $gatewayInfo)
                <div class="bg-white rounded-lg p-4 border">
                    <h4 class="font-semibold text-gray-900 mb-2">{{ $gatewayInfo['name'] }}</h4>
                    <p class="text-sm text-gray-600 mb-2">
                        <strong>Types:</strong> 
                        {{ implode(', ', array_map(function($type) use ($gatewayTypes) { 
                            return $gatewayTypes[$type]; 
                        }, $gatewayInfo['types'])) }}
                    </p>
                    @if(isset($gatewayInfo['config_fields']))
                        <p class="text-sm text-gray-600">
                            <strong>Required:</strong> 
                            {{ implode(', ', array_values($gatewayInfo['config_fields'])) }}
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>

<script>
async function testGateway(gatewayId) {
    try {
        const response = await fetch(`/admin/payment-gateways/${gatewayId}/test`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('✓ ' + result.message);
        } else {
            alert('✗ ' + result.message);
        }
    } catch (error) {
        alert('Error testing gateway configuration.');
    }
}
</script>
</x-admin-layout>
@endsection