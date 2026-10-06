@extends('layouts.admin')

@section('title', 'Add Payment Gateway')

@section('content')
<x-admin-layout title="Add Payment Gateway" subtitle="Create a new payment gateway configuration" active="payment-gateways">
    <x-slot name="actions">
        <a href="{{ route('admin.payment-gateways.index') }}" 
           class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-violet focus:ring-offset-2">
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

    <form method="POST" action="{{ route('admin.payment-gateways.store') }}" class="bg-white rounded-lg shadow p-6">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <!-- Gateway Selection -->
            <div>
                <label for="gateway_name" class="block text-sm font-medium text-gray-700 mb-1">Gateway</label>
                <select name="gateway_name" id="gateway_name" required
                        class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-violet"
                        onchange="updateGatewayFields()">
                    <option value="">Select Gateway</option>
                    @foreach($availableGateways as $key => $info)
                        <option value="{{ $key }}" {{ old('gateway_name') === $key ? 'selected' : '' }}>
                            {{ $info['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Gateway Type -->
            <div>
                <label for="gateway_type" class="block text-sm font-medium text-gray-700 mb-1">Type</label>
                <select name="gateway_type" id="gateway_type" required
                    class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-violet"
                    onchange="updateConfigFields()">
                    <option value="">Select Type</option>
                    @foreach($gatewayTypes as $key => $name)
                        <option value="{{ $key }}" {{ old('gateway_type') === $key ? 'selected' : '' }}>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Environment -->
            <div>
                <label for="environment" class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
                <select name="environment" id="environment" required
                        class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-violet">
                    <option value="sandbox" {{ old('environment', 'sandbox') === 'sandbox' ? 'selected' : '' }}>Sandbox</option>
                    <option value="live" {{ old('environment') === 'live' ? 'selected' : '' }}>Live</option>
                </select>
            </div>

            <!-- Status -->
            <div class="space-y-2">
                <div class="flex items-center">
                    <input type="checkbox" name="is_active" id="is_active" value="1" 
                           {{ old('is_active') ? 'checked' : '' }}
                           class="h-4 w-4 text-brand-violet focus:ring-brand-violet border-gray-300 rounded">
                    <label for="is_active" class="ml-2 text-sm font-medium text-gray-700">Active</label>
                </div>

                <div class="flex items-center">
                    <input type="checkbox" name="is_default" id="is_default" value="1" 
                           {{ old('is_default') ? 'checked' : '' }}
                           class="h-4 w-4 text-brand-violet focus:ring-brand-violet border-gray-300 rounded">
                    <label for="is_default" class="ml-2 text-sm font-medium text-gray-700">Set as Default</label>
                </div>
            </div>
        </div>

        <!-- Dynamic Configuration Fields -->
        <div id="config-fields" class="mb-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Configuration</h3>
            <div class="text-gray-500">Select a gateway to see configuration options.</div>
        </div>

        <div class="flex justify-end space-x-4">
            <a href="{{ route('admin.payment-gateways.index') }}" 
               class="bg-gray-300 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-400 font-medium">
                Cancel
            </a>
            <button type="submit" 
                    class="inline-flex items-center justify-center rounded-lg font-medium shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 px-4 py-2 text-sm bg-brand-violet text-white hover:bg-brand-violet-deep focus:ring-brand-violet">
                Create Gateway
            </button>
        </div>
    </form>
</div>

<script>
const availableGateways = @json($availableGateways);
const gatewayTypes = @json($gatewayTypes);

function updateGatewayFields() {
    const gatewaySelect = document.getElementById('gateway_name');
    const typeSelect = document.getElementById('gateway_type');
    const configFieldsDiv = document.getElementById('config-fields');
    
    const selectedGateway = gatewaySelect.value;
    
    if (!selectedGateway) {
        typeSelect.innerHTML = '<option value="">Select Type</option>';
        configFieldsDiv.innerHTML = '<h3 class="text-lg font-semibold text-gray-900 mb-4">Configuration</h3><div class="text-gray-500">Select a gateway to see configuration options.</div>';
        return;
    }
    
    const gatewayInfo = availableGateways[selectedGateway];
    
    // Update type options
    typeSelect.innerHTML = '<option value="">Select Type</option>';
    gatewayInfo.types.forEach(type => {
        const option = document.createElement('option');
        option.value = type;
        option.textContent = gatewayTypes[type];
        typeSelect.appendChild(option);
    });
    
    // Update config fields
    updateConfigFields();
}

function updateConfigFields() {
    const gatewaySelect = document.getElementById('gateway_name');
    const typeSelect = document.getElementById('gateway_type');
    let fieldsHtml = '<h3 class="text-lg font-semibold text-gray-900 mb-4">Configuration</h3>';

    const selectedGateway = gatewaySelect.value;
    if (!selectedGateway) {
        document.getElementById('config-fields').innerHTML = fieldsHtml + '<div class="text-gray-500">Select a gateway to see configuration options.</div>';
        return;
    }

    const gatewayInfo = availableGateways[selectedGateway];
    const selectedType = typeSelect.value;

    const configFields = (gatewayInfo.config_fields_by_type && selectedType && gatewayInfo.config_fields_by_type[selectedType])
        ? gatewayInfo.config_fields_by_type[selectedType]
        : gatewayInfo.config_fields;

    if (configFields) {
        fieldsHtml += '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';

        Object.entries(configFields).forEach(([key, label]) => {
            const isSecret = key.includes('secret') || key.includes('key') || key.includes('pin');
            const inputType = isSecret ? 'password' : 'text';
            const placeholder = gatewayInfo.default_config && gatewayInfo.default_config[key] || '';

            fieldsHtml += `
                <div>
                    <label for="config_${key}" class="block text-sm font-medium text-gray-700 mb-1">${label}</label>
                    <input type="${inputType}"
                           name="config[${key}]"
                           id="config_${key}"
                           placeholder="${placeholder}"
                           value="{{ old('config.${key}') }}"
                           autocomplete="off"
                           class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand-violet">
                </div>
            `;
        });
        
        fieldsHtml += '</div>';
    } else {
        fieldsHtml += '<div class="text-gray-500">No additional configuration required.</div>';
    }

    document.getElementById('config-fields').innerHTML = fieldsHtml;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('gateway_name').value) {
        updateGatewayFields();
    }
});
</script>
</x-admin-layout>
@endsection