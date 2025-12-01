@extends('layouts.app')

@section('title', 'Checkout - XTRA4U')
@section('description', 'Complete your purchase securely with XTRA4U')

@section('content')
<div
    class="min-h-screen bg-gray-50 py-8 lg:py-16"
    x-data="paymentForm(@json($vendors))"
>
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Complete Your Purchase</h1>
            <p class="mt-2 text-gray-600">Secure checkout powered by XTRA4U</p>
        </div>
        
        <!-- Progress Steps -->
        <div class="mb-8">
            <div class="flex items-center justify-center space-x-8">
                <div class="flex items-center">
                    <div class="flex items-center justify-center w-8 h-8 bg-brand-deep-blue text-white rounded-full text-sm font-medium">
                        1
                    </div>
                    <span class="ml-2 text-sm font-medium text-brand-deep-blue">Service Details</span>
                </div>
                <div class="w-16 h-1 bg-gray-200 rounded"></div>
                <div class="flex items-center">
                    <div class="flex items-center justify-center w-8 h-8 bg-gray-300 text-gray-600 rounded-full text-sm font-medium">
                        2
                    </div>
                    <span class="ml-2 text-sm font-medium text-gray-500">Payment</span>
                </div>
                <div class="w-16 h-1 bg-gray-200 rounded"></div>
                <div class="flex items-center">
                    <div class="flex items-center justify-center w-8 h-8 bg-gray-300 text-gray-600 rounded-full text-sm font-medium">
                        3
                    </div>
                    <span class="ml-2 text-sm font-medium text-gray-500">Confirmation</span>
                </div>
            </div>
        </div>

        <x-card class="overflow-hidden">
            <!-- Form Header -->
            <div class="bg-linear-to-r from-blue-50 to-green-50 px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Service Purchase</h2>
                <p class="mt-1 text-sm text-gray-600">Please fill in the details below to complete your purchase</p>
            </div>
            
            <!-- Form Body -->
            <div class="p-6">
                @if ($errors->any())
                    <div class="mb-6 bg-red-50 border border-red-200 rounded-lg p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                            </svg>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800">Please correct the following errors:</h3>
                                <ul class="mt-2 text-sm text-red-700 list-disc list-inside">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endif

                                <form method="POST" 
                                            action="{{ route('purchase') }}" 
                                            @submit.prevent="submitPayment($event)"
                                            class="space-y-6">
                    @csrf
                                        <input type="hidden" name="service_purchased" :value="selectedService?.name || ''">
                                        <input type="hidden" name="amount_paid" :value="selectedService?.price || ''">
                                        <input type="hidden" name="vendor_service_id" :value="selectedService?.id || ''">
                    
                    <!-- Service Information -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Service Information</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="vendor_id" class="block text-sm font-medium text-gray-700">Choose Vendor</label>
                                <select
                                    id="vendor_id"
                                    name="vendor_id"
                                    x-model="selectedVendorId"
                                    @change="handleVendorChange()"
                                    required
                                    class="mt-1 block w-full rounded-md border border-gray-300 bg-white py-2 px-3 shadow-sm focus:border-brand-deep-blue focus:outline-none focus:ring-brand-deep-blue"
                                >
                                    <option value="">Select a vendor...</option>
                                    @foreach ($vendors as $vendor)
                                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-2 text-sm text-gray-500" x-show="selectedVendor" x-cloak>
                                    <span class="font-medium" x-text="selectedVendor?.email"></span> ·
                                    <span x-text="selectedVendor?.phone_number"></span>
                                </p>
                            </div>

                            <div x-show="selectedVendor" x-cloak>
                                <div class="flex items-center justify-between mb-3">
                                    <h4 class="text-md font-semibold text-gray-900">Select a service from <span x-text="selectedVendor?.name"></span></h4>
                                    <span class="text-sm text-gray-500" x-text="selectedVendor?.products.length + ' available'">
                                    </span>
                                </div>
                                <template x-if="selectedVendor?.products.length">
                                    <div class="space-y-3">
                                        <template x-for="service in selectedVendor.products" :key="service.id">
                                            <button type="button"
                                                @click="selectService(service)"
                                                class="w-full text-left border rounded-lg p-4 transition hover:border-brand-deep-blue"
                                                :class="selectedService?.id === service.id ? 'border-brand-deep-blue bg-blue-50/50' : 'border-gray-200'"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="text-base font-semibold text-gray-900" x-text="service.name"></p>
                                                        <p class="text-sm text-gray-600 mt-1" x-text="service.description"></p>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-lg font-bold text-brand-deep-blue" x-text="formatCurrency(service.price)"></p>
                                                        <p class="text-xs text-gray-500">Tap to select</p>
                                                    </div>
                                                </div>
                                            </button>
                                        </template>
                                    </div>
                                </template>
                                <p class="text-sm text-gray-500" x-show="!selectedVendor?.products.length" x-cloak>
                                    This vendor has no active services yet. Please choose another vendor.
                                </p>
                            </div>

                            <div x-show="selectedService" x-cloak class="bg-green-50 border border-green-200 rounded-lg p-4">
                                <p class="text-sm text-green-900"><strong>Selected Service:</strong> <span x-text="selectedService?.name"></span></p>
                                <p class="text-sm text-green-900"><strong>Price:</strong> <span x-text="formatCurrency(selectedService?.price)"></span></p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Contact Information -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Contact Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <x-form.input 
                                name="recipient_phone_number"
                                type="tel"
                                label="Recipient Phone Number"
                                placeholder="+233 XX XXX XXXX"
                                required
                                :error="$errors->first('recipient_phone_number')"
                                help-text="The phone number that will receive the service"
                            />
                            
                            <x-form.input 
                                name="mobile_money_number"
                                type="tel"
                                label="Mobile Money Number"
                                placeholder="+233 XX XXX XXXX"
                                required
                                :error="$errors->first('mobile_money_number')"
                                help-text="Your mobile money number for payment"
                            />
                        </div>
                    </div>
                    
                    <!-- Vendor Selection -->
                    <div>
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Vendor Selection</h3>
                        <!-- Vendor selection moved above -->
                        <div class="text-sm text-gray-500">
                            <p>Vendors and their offerings are pulled directly from the marketplace. Please select a vendor above to continue.</p>
                        </div>
                    </div>
                    
                    <!-- Payment Summary -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h3 class="text-lg font-medium text-gray-900 mb-3">Payment Summary</h3>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Service Amount:</span>
                                <span class="text-gray-900" x-text="selectedService ? formatCurrency(selectedService.price) : 'GHS 0.00'"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600">Processing Fee:</span>
                                <span class="text-gray-900">GHS 0.00</span>
                            </div>
                            <div class="border-t border-gray-200 pt-2 flex justify-between font-semibold">
                                <span class="text-gray-900">Total Amount:</span>
                                <span class="text-gray-900" x-text="selectedService ? formatCurrency(selectedService.price) : 'GHS 0.00'"></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Notice -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-brand-deep-blue shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
                            </svg>
                            <div class="ml-3">
                                <p class="text-sm text-brand-deep-blue">
                                    <strong>Secure Payment:</strong> Your transaction is protected by end-to-end encryption 
                                    and our fraud protection system.
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="flex flex-col sm:flex-row gap-4 pt-6">
                        <x-button 
                            type="submit" 
                            variant="primary" 
                            size="lg"
                            class="w-full sm:flex-1"
                            x-bind:disabled="processing"
                            x-bind:class="{ 'opacity-50 cursor-not-allowed': processing }"
                        >
                            <span x-show="!processing">Complete Payment</span>
                            <span x-show="processing" class="flex items-center">
                                <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Processing...
                            </span>
                        </x-button>
                        
                        <x-button 
                            href="{{ route('storefront.index') }}" 
                            variant="outline" 
                            size="lg"
                            class="w-full sm:w-auto"
                        >
                            Cancel
                        </x-button>
                    </div>
                </form>
            </div>
        </x-card>
        
        <!-- Security Badges -->
        <div class="mt-8 text-center">
            <p class="text-sm text-gray-500 mb-4">Secured by industry-leading encryption</p>
            <div class="flex justify-center items-center space-x-6 opacity-60">
                <div class="text-xs font-semibold text-gray-500">SSL SECURED</div>
                <div class="text-xs font-semibold text-gray-500">256-BIT ENCRYPTION</div>
                <div class="text-xs font-semibold text-gray-500">FRAUD PROTECTED</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('paymentForm', (vendors = []) => ({
        vendors,
        selectedVendorId: '',
        selectedService: null,
        processing: false,

        get selectedVendor() {
            if (!this.selectedVendorId) {
                return null;
            }
            return this.vendors.find(v => Number(v.id) === Number(this.selectedVendorId)) || null;
        },

        formatCurrency(value) {
            const amount = Number(value) || 0;
            return 'GHS ' + amount.toFixed(2);
        },

        handleVendorChange() {
            this.selectedService = null;
        },

        selectService(service) {
            this.selectedService = service;
        },

        async submitPayment(event) {
            if (!this.selectedVendorId) {
                alert('Please select a vendor to continue.');
                return;
            }

            if (!this.selectedService) {
                alert('Please select one of the vendor\'s services (price) before completing payment.');
                return;
            }

            this.processing = true;

            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);

            try {
                const response = await fetch(event.target.action, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify(data)
                });

                if (response.ok) {
                    event.target.submit();
                } else {
                    const errorData = await response.json();
                    alert(errorData.message || 'Payment failed. Please try again.');
                }
            } catch (error) {
                alert('Network error. Please check your connection and try again.');
            } finally {
                this.processing = false;
            }
        }
    }));
});
</script>
@endpush
