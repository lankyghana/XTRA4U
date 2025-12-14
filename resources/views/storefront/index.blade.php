@extends('layouts.app')

@section('title', 'XTRA4U - Your Digital Services Platform')
@section('description', 'Connect with trusted vendors across Ghana. Secure transactions, verified services, and seamless digital experiences.')

@section('content')
<!-- Hero Section -->
<section class="relative bg-linear-to-br from-blue-50 via-white to-green-50 overflow-hidden">
    <div class="absolute inset-0">
        <div class="absolute inset-0 bg-linear-to-br from-brand-deep-blue/10 to-brand-green/10"></div>
        <!-- Decorative patterns -->
        <div class="absolute top-0 left-0 w-64 h-64 bg-linear-to-br from-brand-bright-blue/20 to-transparent rounded-full -translate-x-32 -translate-y-32"></div>
        <div class="absolute bottom-0 right-0 w-96 h-96 bg-linear-to-tl from-brand-green/20 to-transparent rounded-full translate-x-32 translate-y-32"></div>
    </div>
    
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 lg:py-24">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <!-- Hero Content -->
            <div class="text-center lg:text-left">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-gray-900 leading-tight">
                    Your Gateway to
                    <span class="bg-linear-to-r from-brand-deep-blue to-brand-green bg-clip-text text-transparent">
                        Digital Services
                    </span>
                </h1>
                <p class="mt-6 text-lg sm:text-xl text-gray-600 max-w-2xl">
                    Connect with verified vendors across Ghana. Secure transactions, reliable services, 
                    and seamless digital experiences - all in one platform.
                </p>
                
                <!-- CTA Buttons -->
                <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                    <x-button href="{{ route('vendor.request.form') }}" 
                              variant="primary" 
                              size="lg"
                              class="w-full sm:w-auto">
                        Get Started
                        <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </x-button>
                    
                    <x-button href="{{ route('order.status') }}" 
                              variant="outline" 
                              size="lg"
                              class="w-full sm:w-auto">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        Order Status
                    </x-button>
                </div>
                
                <!-- Stats -->
                <div class="mt-12 grid grid-cols-3 gap-4 sm:gap-8">
                    <div class="text-center">
                        <div class="text-2xl sm:text-3xl font-bold text-gray-900">500+</div>
                        <div class="text-sm text-gray-600">Verified Vendors</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl sm:text-3xl font-bold text-gray-900">10K+</div>
                        <div class="text-sm text-gray-600">Transactions</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl sm:text-3xl font-bold text-gray-900">99.9%</div>
                        <div class="text-sm text-gray-600">Uptime</div>
                    </div>
                </div>
            </div>
            
            <!-- Hero Image/Visual -->
            <div class="relative">
                <div class="relative mx-auto w-full max-w-lg lg:max-w-xl">
                    <!-- Hero Image -->
                    <div class="relative transform hover:scale-105 transition-transform duration-500">
                        <img 
                            src="{{ asset('images/hero-image.png') }}" 
                            alt="XTRA4U - Your Gateway to Digital Services" 
                            class="w-full h-auto rounded-2xl shadow-2xl object-cover"
                            loading="eager"
                        >
                        <!-- Decorative glow effect behind image -->
                        <div class="absolute -inset-4 bg-gradient-to-r from-brand-deep-blue/20 to-brand-xtra-green/20 rounded-3xl blur-2xl -z-10"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Features Section -->
<section id="services" class="py-16 lg:py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-gray-900">
                Everything You Need in One Platform
            </h2>
            <p class="mt-4 text-lg text-gray-600 max-w-2xl mx-auto">
                From digital services to vendor management, we've got you covered with secure, reliable solutions.
            </p>
        </div>
        
        <div class="mt-16 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <!-- Feature 1 -->
            <x-card hover="true" class="text-center group">
                <div class="w-16 h-16 bg-brand-deep-blue rounded-full flex items-center justify-center mx-auto group-hover:scale-110 transition-transform duration-200">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                    </svg>
                </div>
                <h3 class="mt-6 text-xl font-semibold text-gray-900">Secure Transactions</h3>
                <p class="mt-3 text-gray-600">
                    End-to-end encrypted payments with multiple secure payment options and fraud protection.
                </p>
            </x-card>
            
            <!-- Feature 2 -->
            <x-card hover="true" class="text-center group">
                <div class="w-16 h-16 bg-green-600 rounded-full flex items-center justify-center mx-auto group-hover:scale-110 transition-transform duration-200">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h3 class="mt-6 text-xl font-semibold text-gray-900">Verified Vendors</h3>
                <p class="mt-3 text-gray-600">
                    All vendors go through our strict verification process to ensure quality and reliability. 
                    Every vendor is thoroughly vetted, background checked, and continuously monitored to maintain 
                    the highest standards of service delivery across our platform.
                </p>
                <div class="mt-4 flex items-center justify-center space-x-4 text-sm text-green-600 font-semibold">
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        ID Verified
                    </span>
                    <span class="flex items-center">
                        <svg class="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                        Quality Assured
                    </span>
                </div>
            </x-card>
            
            <!-- Feature 3 -->
            <x-card hover="true" class="text-center group">
                <div class="w-16 h-16 bg-brand-bright-blue rounded-full flex items-center justify-center mx-auto group-hover:scale-110 transition-transform duration-200">
                    <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <h3 class="mt-6 text-xl font-semibold text-gray-900">Lightning Fast</h3>
                <p class="mt-3 text-gray-600">
                    Quick processing times and instant notifications keep you updated every step of the way.
                </p>
            </x-card>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="py-16 lg:py-24 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-gray-900">How It Works</h2>
            <p class="mt-4 text-lg text-gray-600">Simple steps to get started with XTRA4U</p>
        </div>
        
        <div class="mt-16">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 lg:gap-12">
                <!-- Step 1 -->
                <div class="text-center relative">
                    <div class="flex items-center justify-center w-16 h-16 bg-brand-deep-blue text-white rounded-full text-xl font-bold mx-auto">1</div>
                    <h3 class="mt-6 text-xl font-semibold text-gray-900">Choose Service</h3>
                    <p class="mt-3 text-gray-600">Browse our verified vendors and select the service you need.</p>
                    
                    <!-- Arrow (hidden on mobile) -->
                    <div class="hidden md:block absolute top-8 left-full w-full">
                        <svg class="w-6 h-6 text-gray-400 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                </div>
                
                <!-- Step 2 -->
                <div class="text-center relative">
                    <div class="flex items-center justify-center w-16 h-16 bg-brand-bright-blue text-white rounded-full text-xl font-bold mx-auto">2</div>
                    <h3 class="mt-6 text-xl font-semibold text-gray-900">Make Payment</h3>
                    <p class="mt-3 text-gray-600">Securely pay using your preferred payment method.</p>
                    
                    <!-- Arrow (hidden on mobile) -->
                    <div class="hidden md:block absolute top-8 left-full w-full">
                        <svg class="w-6 h-6 text-gray-400 mx-auto" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </div>
                </div>
                
                <!-- Step 3 -->
                <div class="text-center">
                    <div class="flex items-center justify-center w-16 h-16 bg-brand-bright-blue text-white rounded-full text-xl font-bold mx-auto">3</div>
                    <h3 class="mt-6 text-xl font-semibold text-gray-900">Get Service</h3>
                    <p class="mt-3 text-gray-600">
                        Receive your service instantly and track the progress in real-time. Get SMS notifications, 
                        email confirmations, and access to 24/7 customer support throughout your service delivery.
                    </p>
                    <div class="mt-4 inline-flex items-center text-sm text-green-600 font-semibold">
                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Usually within 5 minutes
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="bg-linear-to-r from-brand-deep-blue to-brand-green">
    <div class="max-w-7xl mx-auto py-16 px-4 sm:px-6 lg:px-8 lg:py-24">
        <div class="text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-white">
                Ready to Get Started?
            </h2>
                            <p class="mt-4 text-lg text-blue-100">
                Join thousands of satisfied customers and vendors on our platform.
            </p>
            
            <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                <x-button href="{{ route('checkout.show') }}" 
                          variant="secondary" 
                          size="lg"
                          class="w-full sm:w-auto bg-white text-brand-deep-blue hover:bg-gray-50">
                    Start Shopping
                </x-button>
                
                <x-button href="{{ route('vendor.request.form') }}" 
                          variant="outline" 
                          size="lg"
                          class="w-full sm:w-auto border-white text-white hover:bg-white hover:text-brand-deep-blue">
                    Become a Vendor
                </x-button>
            </div>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
    // Smooth scrolling for anchor links
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    });
</script>
@endpush
