@extends('layouts.app')

@section('title', 'AFA Registration - ' . $vendor->name)
@section('description', 'Register for AFA services with ' . $vendor->name)

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-8 sm:py-12">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <a href="{{ route('storefront.vendor', $vendor->vendor_code) }}" class="inline-flex items-center text-gray-500 hover:text-gray-700 mb-4">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to {{ $vendor->name }}
            </a>
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-green-500 to-green-600 rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900">AFA Registration</h1>
            <p class="mt-2 text-gray-600">Complete your registration details below</p>
            <div class="mt-4 inline-flex items-center px-4 py-2 bg-green-100 rounded-full">
                <span class="text-sm font-medium text-green-700">Registration Fee: GH₵ {{ number_format($price, 2) }}</span>
            </div>
        </div>

        <!-- Error Messages -->
        @if(session('error'))
            <div class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
                <div class="flex items-center">
                    <svg class="h-5 w-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-red-700">{{ session('error') }}</p>
                </div>
            </div>
        @endif

        <!-- Registration Form -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="p-6 sm:p-8">
                <form action="{{ route('afa.store', $vendor->vendor_code) }}" method="POST" class="space-y-6">
                    @csrf

                    <!-- Full Name -->
                    <div>
                        <label for="full_name" class="block text-sm font-medium text-gray-700 mb-2">
                            Full Name on Ghana Card <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="full_name" 
                            id="full_name"
                            value="{{ old('full_name') }}"
                            placeholder="John Doe"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('full_name') border-red-500 @enderror"
                            required
                        >
                        @error('full_name')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- ID Type and Number -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="id_type" class="block text-sm font-medium text-gray-700 mb-2">
                                ID Type <span class="text-red-500">*</span>
                            </label>
                            <select 
                                name="id_type" 
                                id="id_type"
                                class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('id_type') border-red-500 @enderror"
                                required
                            >
                                @foreach($idTypes as $value => $label)
                                    <option value="{{ $value }}" {{ old('id_type') === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('id_type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label for="id_number" class="block text-sm font-medium text-gray-700 mb-2">
                                ID Number <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="text" 
                                name="id_number" 
                                id="id_number"
                                value="{{ old('id_number') }}"
                                placeholder="GHA-XXXXXXXXX-X"
                                class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 uppercase @error('id_number') border-red-500 @enderror"
                                required
                            >
                            <p id="id_hint" class="mt-1 text-xs text-gray-500">Format: GHA-123456789-0</p>
                            <p id="id_error" class="mt-1 text-sm text-red-600 hidden"></p>
                            @error('id_number')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Date of Birth -->
                    <div>
                        <label for="date_of_birth" class="block text-sm font-medium text-gray-700 mb-2">
                            Date of Birth <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="date" 
                            name="date_of_birth" 
                            id="date_of_birth"
                            value="{{ old('date_of_birth') }}"
                            max="{{ date('Y-m-d') }}"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('date_of_birth') border-red-500 @enderror"
                            required
                        >
                        @error('date_of_birth')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Phone Number -->
                    <div>
                        <label for="phone_number" class="block text-sm font-medium text-gray-700 mb-2">
                            Phone Number <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="tel" 
                            name="phone_number" 
                            id="phone_number"
                            value="{{ old('phone_number') }}"
                            placeholder="0544797799"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('phone_number') border-red-500 @enderror"
                            required
                        >
                        @error('phone_number')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Location -->
                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
                            Location (Town/City) <span class="text-red-500">*</span>
                        </label>
                        <input 
                            type="text" 
                            name="location" 
                            id="location"
                            value="{{ old('location') }}"
                            placeholder="Kumasi"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('location') border-red-500 @enderror"
                            required
                        >
                        @error('location')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Region -->
                    <div>
                        <label for="region" class="block text-sm font-medium text-gray-700 mb-2">
                            Region <span class="text-red-500">*</span>
                        </label>
                        <select 
                            name="region" 
                            id="region"
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('region') border-red-500 @enderror"
                            required
                        >
                            <option value="">Select Region</option>
                            @foreach($regions as $region)
                                <option value="{{ $region }}" {{ old('region') === $region ? 'selected' : '' }}>{{ $region }}</option>
                            @endforeach
                        </select>
                        @error('region')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Occupation -->
                    <div>
                        <label for="occupation" class="block text-sm font-medium text-gray-700 mb-2">
                            Occupation
                        </label>
                        <input 
                            type="text" 
                            name="occupation" 
                            id="occupation"
                            value="{{ old('occupation') }}"
                            placeholder="Farmer, Teacher, etc."
                            class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('occupation') border-red-500 @enderror"
                        >
                        @error('occupation')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <hr class="border-gray-200">

                    <!-- Payment Section -->
                    <div class="bg-gray-50 -mx-6 sm:-mx-8 px-6 sm:px-8 py-4">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Payment Details</h3>
                        
                        <!-- Payer Phone Number (for MoMo) -->
                        <div>
                            <label for="payer_phone" class="block text-sm font-medium text-gray-700 mb-2">
                                Mobile Money Number (for payment) <span class="text-red-500">*</span>
                            </label>
                            <input 
                                type="tel" 
                                name="payer_phone" 
                                id="payer_phone"
                                value="{{ old('payer_phone') }}"
                                placeholder="0244123456"
                                class="block w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 @error('payer_phone') border-red-500 @enderror"
                                required
                            >
                            @error('payer_phone')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <!-- Summary -->
                    <div class="bg-green-50 rounded-xl p-4">
                        <div class="flex items-center justify-between">
                            <span class="text-gray-700">Registration Fee</span>
                            <span class="text-xl font-bold text-green-700">GH₵ {{ number_format($price, 2) }}</span>
                        </div>
                        <p class="mt-2 text-sm text-gray-500">
                            By submitting, you agree to pay the registration fee via Mobile Money.
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <button 
                        type="submit"
                        class="w-full flex items-center justify-center px-6 py-4 bg-gradient-to-r from-green-500 to-green-600 text-white font-semibold text-lg rounded-xl hover:from-green-600 hover:to-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all"
                    >
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Proceed to Payment
                    </button>
                </form>
            </div>
        </div>

        <!-- Vendor Info -->
        <div class="mt-6 text-center text-sm text-gray-500">
            <p>Processing by: <span class="font-medium text-gray-700">{{ $vendor->name }}</span></p>
            @if($vendor->phone_number)
                <p class="mt-1">
                    Need help? 
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $vendor->phone_number) }}" target="_blank" class="text-green-600 hover:text-green-700">
                        Contact vendor on WhatsApp
                    </a>
                </p>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const idTypeSelect = document.getElementById('id_type');
    const idNumberInput = document.getElementById('id_number');
    const idHint = document.getElementById('id_hint');
    const idError = document.getElementById('id_error');
    
    const validationRules = {
        ghana_card: {
            pattern: /^GHA-[0-9]{9}-[0-9]$/i,
            placeholder: 'GHA-XXXXXXXXX-X',
            hint: 'Format: GHA-123456789-0',
            error: 'Ghana Card must be in format: GHA-123456789-0'
        },
        drivers_license: {
            pattern: /^[A-Z0-9]{9,12}$/i,
            placeholder: 'XXXXXXXXXXXX',
            hint: 'Enter 9-12 alphanumeric characters',
            error: 'Driver\'s License must be 9-12 alphanumeric characters'
        },
        voters_id: {
            pattern: /^[0-9]{10}$/,
            placeholder: 'XXXXXXXXXX',
            hint: 'Enter 10 digit Voter\'s ID number',
            error: 'Voter\'s ID must be exactly 10 digits'
        }
    };
    
    function updateIdField() {
        const selectedType = idTypeSelect.value;
        const rules = validationRules[selectedType];
        
        if (rules) {
            idNumberInput.placeholder = rules.placeholder;
            idHint.textContent = rules.hint;
            idHint.classList.remove('hidden');
            validateIdNumber();
        }
    }
    
    function validateIdNumber() {
        const selectedType = idTypeSelect.value;
        const rules = validationRules[selectedType];
        const value = idNumberInput.value.trim().toUpperCase();
        
        idError.classList.add('hidden');
        idNumberInput.classList.remove('border-red-500', 'border-green-500');
        
        if (!value) return true;
        
        if (rules && !rules.pattern.test(value)) {
            idError.textContent = rules.error;
            idError.classList.remove('hidden');
            idNumberInput.classList.add('border-red-500');
            return false;
        } else if (rules) {
            idNumberInput.classList.add('border-green-500');
        }
        
        return true;
    }
    
    // Auto-format Ghana Card number
    idNumberInput.addEventListener('input', function(e) {
        let value = e.target.value.toUpperCase();
        
        if (idTypeSelect.value === 'ghana_card') {
            // Remove all non-alphanumeric characters except hyphens
            value = value.replace(/[^A-Z0-9-]/g, '');
            
            // Auto-add hyphens for Ghana Card format
            if (value.length === 3 && !value.includes('-')) {
                value = value + '-';
            } else if (value.length === 13 && value.charAt(12) !== '-') {
                value = value.substring(0, 13) + '-' + value.substring(13);
            }
            
            // Limit to 15 characters (GHA-XXXXXXXXX-X)
            if (value.length > 15) {
                value = value.substring(0, 15);
            }
        }
        
        e.target.value = value;
        validateIdNumber();
    });
    
    idTypeSelect.addEventListener('change', function() {
        idNumberInput.value = '';
        updateIdField();
    });
    
    // Form submission validation
    document.querySelector('form').addEventListener('submit', function(e) {
        if (!validateIdNumber()) {
            e.preventDefault();
            idNumberInput.focus();
        }
    });
    
    // Initialize
    updateIdField();
});
</script>
@endpush
@endsection
