@extends('layouts.admin')

@section('title', 'Edit Payment Gateway')

@section('content')
<x-admin-layout title="Edit Payment Gateway" subtitle="Modify payment gateway configuration" active="payment-gateways">
    <x-slot name="actions">
        <a href="{{ route('admin.payment-gateways.index') }}" 
           class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700 font-medium">
            Back to Gateways
        </a>
    </x-slot>

    @if($errors->any())
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.payment-gateways.update', $gateway) }}" class="bg-white rounded-lg shadow p-6">
        @csrf
        @method('PUT')

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <!-- Gateway Info (Read-only) -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
                <div class="w-full border border-gray-200 bg-gray-50 rounded-md px-3 py-2 text-gray-600">
                    {{ $availableGateways[$gateway->gateway_name]['name'] ?? $gateway->gateway_name }}
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <div class="w-full border border-gray-200 bg-gray-50 rounded-md px-3 py-2 text-gray-600">
                    {{ $gatewayTypes[$gateway->gateway_type] ?? $gateway->gateway_type }}
                </div>
            </div>

            <!-- Environment -->
            <div>
                <label for="environment" class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
                <select name="environment" id="environment" required
                        class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="sandbox" {{ old('environment', $gateway->environment) === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                    <option value="live" {{ old('environment', $gateway->environment) === 'live' ? 'selected' : '' }}>Live</option>
                </select>
            </div>

            <!-- Status -->
            <div class="space-y-2">
                <div class="flex items-center">
                    <input type="checkbox" name="is_active" id="is_active" value="1" 
                           {{ old('is_active', $gateway->is_active) ? 'checked' : '' }}
                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <label for="is_active" class="ml-2 text-sm font-medium text-gray-700">Active</label>
                </div>

                <div class="flex items-center">
                    <input type="checkbox" name="is_default" id="is_default" value="1" 
                           {{ old('is_default', $gateway->is_default) ? 'checked' : '' }}
                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <label for="is_default" class="ml-2 text-sm font-medium text-gray-700">Set as Default</label>
                </div>
            </div>
        </div>

        <!-- Configuration Fields -->
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Configuration</h3>
            
            @php
                $gatewayInfo = $availableGateways[$gateway->gateway_name] ?? [];
                $configFields = $gatewayInfo['config_fields_by_type'][$gateway->gateway_type] ?? ($gatewayInfo['config_fields'] ?? []);
            @endphp

            @if($configFields)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($configFields as $key => $label)
                        <div>
                            <label for="config_{{ $key }}" class="block text-sm font-medium text-gray-700 mb-1">
                                {{ $label }}
                            </label>
                            <input type="{{ str_contains($key, 'secret') || str_contains($key, 'key') ? 'password' : 'text' }}" 
                                   name="config[{{ $key }}]" 
                                   id="config_{{ $key }}"
                                   value="{{ old('config.' . $key, $gateway->getConfig($key)) }}"
                                   placeholder="{{ $gatewayInfo['default_config'][$key] ?? '' }}"
                                   class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            
                            @if(str_contains($key, 'secret') || str_contains($key, 'key'))
                                <div class="mt-1 text-xs text-gray-500">
                                    Leave blank to keep existing value
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-gray-500">No additional configuration required.</div>
            @endif
        </div>

        <!-- Supported Features -->
        @if(isset($gateway->supported_features) && $gateway->supported_features)
            <div class="mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Supported Features</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                    @foreach($gateway->supported_features as $feature => $supported)
                        @if($supported)
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                {{ ucfirst(str_replace('_', ' ', $feature)) }}
                            </span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        <div class="flex justify-end space-x-4">
            <a href="{{ route('admin.payment-gateways.index') }}" 
               class="bg-gray-300 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-400 font-medium">
                Cancel
            </a>
            <button type="button" onclick="testGateway()" 
                    class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 font-medium">
                Test Configuration
            </button>
            <button type="submit" 
                    class="bg-blue-600 text-white px-6 py-2 rounded-md hover:bg-blue-700 font-medium">
                Update Gateway
            </button>
        </div>
    </form>
</div>

<script>
async function testGateway() {
    try {
        const response = await fetch(`{{ route('admin.payment-gateways.test', $gateway) }}`, {
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