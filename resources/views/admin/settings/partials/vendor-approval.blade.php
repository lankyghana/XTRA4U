<div class="max-w-4xl">
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <svg class="w-5 h-5 mr-2 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                </svg>
                Vendor Approval Contact Number
            </h2>
        </div>

        <form action="{{ route('admin.settings.vendor-approval.update') }}" method="POST" class="p-6 space-y-6">
            @csrf
            @method('PUT')

            <div>
                <label for="vendor_approval_contact_number" class="block text-sm font-medium text-gray-700 mb-2">
                    Contact Number <span class="text-red-500">*</span>
                </label>
                <input type="text" name="vendor_approval_contact_number" id="vendor_approval_contact_number"
                       value="{{ old('vendor_approval_contact_number', $vendorApprovalSettings['vendor_approval_contact_number'] ?? '') }}"
                       class="block w-full border-gray-300 rounded-lg shadow-sm focus:ring-brand-deep-blue focus:border-brand-deep-blue"
                       placeholder="e.g., +233 XX XXX XXXX"
                       required>
                <p class="mt-1 text-xs text-gray-500">
                    Shown to vendors after they submit a registration request, and if they try to log in before being approved.
                </p>
            </div>

            <div class="pt-4 border-t border-gray-200 flex justify-end">
                <button type="submit"
                        class="inline-flex items-center px-6 py-3 bg-brand-deep-blue text-white font-semibold rounded-lg shadow-sm hover:bg-brand-bright-blue focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-deep-blue transition-colors">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>
