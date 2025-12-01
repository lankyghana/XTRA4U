@props([
    'headers' => [],
    'rows' => [],
    'striped' => true,
    'hoverable' => true,
    'responsive' => true
])

@php
    $tableClasses = 'min-w-full bg-white shadow-sm rounded-lg overflow-hidden';
    $headerClasses = 'bg-gray-50 border-b border-gray-200';
    $bodyClasses = '';
    $rowClasses = $striped ? 'even:bg-gray-50' : '';
    $cellClasses = 'px-6 py-4 text-sm';
@endphp

<div {{ $attributes->merge(['class' => $responsive ? 'overflow-x-auto shadow rounded-lg' : 'shadow rounded-lg']) }}>
    <table class="{{ $tableClasses }}">
        @if(!empty($headers))
            <thead class="{{ $headerClasses }}">
                <tr>
                    @foreach($headers as $header)
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            {{ $header }}
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif
        
        <tbody class="{{ $bodyClasses }} divide-y divide-gray-200">
            @if($slot->isNotEmpty())
                {{ $slot }}
            @else
                @foreach($rows as $row)
                    <tr class="{{ $rowClasses }} {{ $hoverable ? 'hover:bg-gray-50' : '' }} transition-colors">
                        @foreach($row as $cell)
                            <td class="{{ $cellClasses }} text-gray-900">
                                {{ $cell }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endif
        </tbody>
    </table>
    
    @if(empty($rows) && $slot->isEmpty())
        <div class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2h2a2 2 0 002-2z"/>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">No data</h3>
            <p class="mt-1 text-sm text-gray-500">Get started by adding some data.</p>
        </div>
    @endif
</div>

{{-- Usage Examples:

<!-- Simple table with headers and data arrays -->
<x-table 
    :headers="['Name', 'Email', 'Status', 'Actions']"
    :rows="[
        ['John Doe', 'john@example.com', 'Active', 'Edit | Delete'],
        ['Jane Smith', 'jane@example.com', 'Inactive', 'Edit | Delete']
    ]"
/>

<!-- Custom table using slot content -->
<x-table :headers="['Product', 'Price', 'Stock', 'Status']">
    @foreach($products as $product)
        <tr class="even:bg-gray-50 hover:bg-gray-50 transition-colors">
            <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $product->name }}</td>
            <td class="px-6 py-4 text-sm text-gray-900">GHS {{ number_format($product->price, 2) }}</td>
            <td class="px-6 py-4 text-sm text-gray-900">{{ $product->stock }}</td>
            <td class="px-6 py-4 text-sm">
                <x-badge variant="{{ $product->status === 'active' ? 'completed' : 'pending' }}">
                    {{ ucfirst($product->status) }}
                </x-badge>
            </td>
        </tr>
    @endforeach
</x-table>

--}}