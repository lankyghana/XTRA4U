@props([
    'headers' => [],
])

{{-- Admin data table: bordered card, horizontal scroll on narrow screens (headers never wrap). --}}
<div {{ $attributes->merge(['class' => 'admin-table-card overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm']) }}>
    <div class="overflow-x-auto">
        <table class="admin-table">
            @if (! empty($headers))
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th scope="col">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
            @endif
            <tbody>
                {{ $slot }}
            </tbody>
        </table>
    </div>
</div>
