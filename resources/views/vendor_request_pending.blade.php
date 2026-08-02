@extends('layouts.app')

@section('title', 'Request Submitted - XTRA4U')
@section('description', 'Your vendor registration request has been submitted and is awaiting admin approval.')

@section('content')
<div class="min-h-screen bg-gradient-to-br from-blue-50 via-white to-green-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl mx-auto">
        <!-- Header Section -->
        <div class="text-center mb-10">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-r from-brand-deep-blue to-brand-green rounded-full mb-4">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 mb-3">Request Submitted!</h1>
            <p class="text-lg text-gray-600 max-w-xl mx-auto">
                @if($vendorName)
                    Thanks, {{ $vendorName }} &mdash; your vendor registration request has been received.
                @else
                    Your vendor registration request has been received.
                @endif
            </p>
        </div>

        <!-- Content Card -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden">
            <div class="px-6 py-8 sm:px-10 text-center">
                <p class="text-gray-700 mb-6">
                    Your account is now pending approval. To speed up the review process, please contact admin using the number below.
                </p>

                @if($whatsappUrl)
                    <div class="bg-gray-50 border border-gray-200 rounded-xl p-6 mb-6">
                        <div class="flex items-center justify-center gap-2 text-gray-500 text-sm font-medium mb-4">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                            </svg>
                            Contact Admin For Approval
                        </div>
                        <p class="text-xl font-bold text-gray-900 mb-4">{{ $contactNumber }}</p>
                        <a href="{{ $whatsappUrl }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="inline-flex items-center justify-center gap-2 px-6 py-3 bg-[#25D366] text-white font-semibold rounded-lg shadow-md hover:bg-[#1ebe57] hover:shadow-lg transition-all duration-200">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.472-.148-.67.15-.198.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                                <path d="M12.001 2C6.478 2 2 6.477 2 12c0 1.99.583 3.845 1.588 5.401L2 22l4.735-1.556A9.953 9.953 0 0012.001 22C17.523 22 22 17.523 22 12S17.523 2 12.001 2zm0 18.148a8.09 8.09 0 01-4.129-1.132l-.296-.176-3.06.921.949-2.965-.194-.306A8.087 8.087 0 013.913 12c0-4.462 3.63-8.088 8.088-8.088 4.457 0 8.087 3.626 8.087 8.088 0 4.462-3.63 8.148-8.087 8.148z"/>
                            </svg>
                            Chat on WhatsApp
                        </a>
                    </div>
                @else
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-6 text-sm text-yellow-800">
                        Please contact admin for approval.
                    </div>
                @endif

                <p class="text-sm text-gray-500 mb-8">
                    You'll be able to log in as soon as your account is approved.
                </p>

                <div class="flex flex-col sm:flex-row justify-center gap-3">
                    <a href="{{ route('vendor.login.form') }}" class="inline-flex justify-center items-center px-6 py-3 bg-gradient-to-r from-brand-deep-blue to-brand-green text-white font-semibold rounded-lg shadow-md hover:shadow-lg transition-all duration-200">
                        Go to Vendor Login
                    </a>
                    <a href="{{ route('storefront.index') }}" class="inline-flex justify-center items-center px-6 py-3 bg-white border border-gray-300 text-gray-700 font-semibold rounded-lg hover:bg-gray-50 transition-colors">
                        Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
