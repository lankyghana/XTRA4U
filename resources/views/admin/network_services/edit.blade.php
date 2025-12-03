@php use Illuminate\Support\Str; @endphp

<x-admin-layout title="Network Services" subtitle="Update network information" active="network-services">
    <div class="max-w-2xl rounded-lg border border-gray-100 bg-white p-6 shadow">
        <h2 class="text-lg font-semibold text-gray-900">Edit {{ $service->name }}</h2>
        <p class="text-sm text-gray-500">Change the name, category, or status for this option.</p>

        <form action="{{ route('admin.network-services.update', $service) }}" method="POST" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-semibold text-gray-700">Name</label>
                <input type="text" id="name" name="name" value="{{ old('name', $service->name) }}" required
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
                        <option value="{{ $categoryKey }}" {{ old('category', $service->category) === $categoryKey ? 'selected' : '' }}>{{ Str::title(str_replace(['-', '_'], ' ', $categoryKey)) }}</option>
                    @endforeach
                </select>
                @error('category')
                    <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="image" class="block text-sm font-semibold text-gray-700 mb-2">Image</label>
                @if($service->image_path)
                    <div class="mb-3 flex items-center gap-3 p-3 bg-gray-50 rounded-lg border border-gray-200">
                        <img src="{{ $service->image_url }}" alt="{{ $service->name }}" class="h-16 w-16 rounded object-cover">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Current image</p>
                            <p class="text-xs text-gray-500">Upload a new image to replace</p>
                        </div>
                    </div>
                @endif
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
                <input type="checkbox" name="is_active" id="is_active" value="1" {{ old('is_active', $service->is_active) ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                <label for="is_active" class="text-sm text-gray-700">Active</label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-5 py-2 text-sm font-semibold text-white hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500">
                    Save Changes
                </button>
                <a href="{{ route('admin.network-services.index') }}" class="text-sm font-semibold text-gray-600 underline">Cancel</a>
            </div>
        </form>
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
