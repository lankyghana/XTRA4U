@php use Illuminate\Support\Str; @endphp

<x-admin-layout title="Network Services" subtitle="Manage the official networks and services vendors can select" active="network-services">
    @if(session('success'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif

    <div class="max-w-6xl mx-auto space-y-6">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)]">
            <section class="bg-white shadow rounded-lg p-6 border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Add Network / Service</h2>
                        <p class="text-sm text-gray-500">Pick a category and give it a friendly name.</p>
                    </div>
                </div>

                <form action="{{ route('admin.network-services.store') }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="name" class="block text-sm font-semibold text-gray-700">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name') }}" required
                                   class="mt-1 block w-full rounded-lg border-gray-200 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                            @error('name')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="category" class="block text-sm font-semibold text-gray-700">Category</label>
                            <select id="category" name="category" required
                                    class="mt-1 block w-full rounded-lg border-gray-200 shadow-sm focus:border-purple-500 focus:ring-purple-500">
                                @foreach($categories as $categoryKey)
                                    <option value="{{ $categoryKey }}" {{ old('category') === $categoryKey ? 'selected' : '' }}>{{ Str::title(str_replace(['-', '_'], ' ', $categoryKey)) }}</option>
                                @endforeach
                            </select>
                            @error('category')
                                <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div>
                        <label for="image" class="block text-sm font-semibold text-gray-700 mb-2">Image</label>
                        <div class="relative border-2 border-dashed border-gray-300 rounded-lg p-6 hover:border-purple-400 transition-colors bg-gray-50 hover:bg-purple-50">
                            <input type="file" id="image" name="image" accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                   onchange="displayFileName(this)">
                            <div class="text-center pointer-events-none">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <p class="mt-2 text-sm text-gray-600 font-medium">
                                    <span class="text-purple-600">Click to upload</span> or drag and drop
                                </p>
                                <p class="mt-1 text-xs text-gray-500" id="file-name">Max 2MB. Supports: JPEG, PNG, GIF, WebP</p>
                            </div>
                        </div>
                        @error('image')
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', true) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                        <label for="is_active" class="text-sm text-gray-700">Active</label>
                    </div>
                    <div>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-5 py-2 text-sm font-semibold text-white hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500">
                            Add Network / Service
                        </button>
                    </div>
                </form>
            </section>

            <section class="bg-white shadow rounded-lg border border-gray-100 overflow-hidden">
                <div class="border-b border-gray-100 px-6 py-4">
                    <h3 class="text-lg font-semibold text-gray-900">Configured Networks</h3>
                    <p class="text-sm text-gray-500">Vendors will choose from these when creating products.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3 text-left">Image</th>
                                <th class="px-6 py-3 text-left">Network / Service</th>
                                <th class="px-6 py-3 text-left">Category</th>
                                <th class="px-6 py-3 text-left">Status</th>
                                <th class="px-6 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            @forelse($services as $service)
                                <tr>
                                    <td class="px-6 py-3">
                                        @if($service->image_path)
                                            <img src="{{ $service->image_url }}" alt="{{ $service->name }}" class="h-10 w-10 rounded object-cover">
                                        @else
                                            <div class="h-10 w-10 rounded bg-gray-200 flex items-center justify-center">
                                                <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 font-medium text-gray-900">{{ $service->name }}</td>
                                    <td class="px-6 py-3 text-gray-600">{{ Str::title(str_replace(['-', '_'], ' ', $service->category)) }}</td>
                                    <td class="px-6 py-3">
                                        @if($service->is_active)
                                            <span class="rounded-full bg-green-50 px-2 py-1 text-xs font-semibold text-green-700">Active</span>
                                        @else
                                            <span class="rounded-full bg-yellow-50 px-2 py-1 text-xs font-semibold text-yellow-700">Inactive</span>
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-right space-x-2">
                                        <a href="{{ route('admin.network-services.edit', $service) }}" class="text-xs font-semibold text-purple-600 hover:text-purple-700">Edit</a>
                                        <form action="{{ route('admin.network-services.destroy', $service) }}" method="POST" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700" onclick="return confirm('Remove this service?')">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">No services have been configured yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    @push('scripts')
    <script>
        function displayFileName(input) {
            const fileName = input.files[0]?.name;
            const fileNameDisplay = document.getElementById('file-name');
            if (fileName) {
                fileNameDisplay.textContent = '✓ ' + fileName;
                fileNameDisplay.classList.add('text-purple-600', 'font-semibold');
            }
        }
    </script>
    @endpush
</x-admin-layout>
