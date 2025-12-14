@extends('layouts.app')

@section('title', 'About Us - XTRA4U')
@section('description', 'Learn about XTRA4U - Your reliable digital platform for fast and affordable online services in Ghana.')

@section('content')
<!-- Hero Section -->
<section class="relative bg-gradient-to-br from-brand-deep-blue to-blue-800 overflow-hidden">
    <div class="absolute inset-0">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-deep-blue/90 to-blue-900/90"></div>
        <!-- Decorative patterns -->
        <div class="absolute top-0 right-0 w-96 h-96 bg-white/5 rounded-full translate-x-32 -translate-y-32"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-white/5 rounded-full -translate-x-16 translate-y-16"></div>
    </div>
    
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-20 lg:py-28">
        <div class="text-center">
            <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-white leading-tight">
                About Us
            </h1>
            <p class="mt-6 text-xl text-blue-100 max-w-3xl mx-auto">
                Your trusted partner for digital services in Ghana
            </p>
        </div>
    </div>
</section>

<!-- Main About Content -->
<section class="py-16 lg:py-24 bg-white">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="prose prose-lg max-w-none">
            <!-- Welcome -->
            <div class="text-center mb-12">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-gradient-to-r from-brand-deep-blue to-brand-green rounded-2xl mb-6">
                    <span class="text-white font-bold text-2xl">X4U</span>
                </div>
                <h2 class="text-3xl font-bold text-gray-900 mb-4">Welcome to Xtra4U</h2>
                <p class="text-xl text-gray-600">
                    Your reliable digital platform for fast and affordable online services.
                </p>
            </div>

            <!-- Content Sections -->
            <div class="space-y-8 text-gray-700 text-lg leading-relaxed">
                <div class="bg-gray-50 rounded-2xl p-8">
                    <h3 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-6 h-6 text-brand-deep-blue mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        What We Do
                    </h3>
                    <p>
                        At Xtra4U, we provide a wide range of services including <strong>data bundles for all networks</strong>, 
                        <strong>airtime recharge</strong>, <strong>results checker services</strong>, and many more digital solutions 
                        designed to make life easier for individuals and businesses.
                    </p>
                </div>

                <div class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl p-8 border border-green-100">
                    <h3 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-6 h-6 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Empowering Entrepreneurs
                    </h3>
                    <p>
                        We also empower entrepreneurs by registering <strong>resellers and vendors</strong>, giving them the 
                        opportunity to earn by offering our services to their customers at competitive prices.
                    </p>
                </div>

                <div class="bg-blue-50 rounded-2xl p-8 border border-blue-100">
                    <h3 class="text-xl font-semibold text-gray-900 mb-4 flex items-center">
                        <svg class="w-6 h-6 text-brand-deep-blue mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                        Our Mission
                    </h3>
                    <p>
                        Our mission is to deliver <strong>speed, reliability, and convenience</strong> while maintaining 
                        excellent customer support and secure transactions.
                    </p>
                </div>
            </div>

            <!-- Value Proposition -->
            <div class="mt-12 text-center bg-gradient-to-r from-brand-deep-blue/5 to-brand-green/5 rounded-2xl p-8 lg:p-12">
                <p class="text-xl text-gray-800 font-medium mb-6">
                    With Xtra4U, you get more value, more convenience, and more opportunities — all in one place.
                </p>
                
                <!-- Tagline -->
                <div class="inline-flex items-center bg-white px-8 py-4 rounded-full shadow-lg">
                    <div class="w-10 h-10 bg-gradient-to-r from-brand-deep-blue to-brand-green rounded-lg flex items-center justify-center mr-4">
                        <span class="text-white font-bold text-sm">X4U</span>
                    </div>
                    <span class="text-xl font-bold text-gray-800">
                        Xtra4U
                    </span>
                    <span class="mx-3 text-gray-300">|</span>
                    <span class="text-lg italic text-brand-deep-blue font-medium">
                        Where trust meets value
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Core Values Section -->
<section class="py-16 lg:py-24 bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900">Our Core Values</h2>
            <p class="mt-4 text-lg text-gray-600">What drives us every day</p>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <!-- Value 1 -->
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center transform hover:scale-105 transition-transform duration-200">
                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-brand-deep-blue" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Speed</h3>
                <p class="text-gray-600">Fast and instant service delivery to save your time</p>
            </div>
            
            <!-- Value 2 -->
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center transform hover:scale-105 transition-transform duration-200">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Trust</h3>
                <p class="text-gray-600">Secure transactions and verified vendors you can rely on</p>
            </div>
            
            <!-- Value 3 -->
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center transform hover:scale-105 transition-transform duration-200">
                <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Value</h3>
                <p class="text-gray-600">Affordable prices with maximum benefits for you</p>
            </div>
            
            <!-- Value 4 -->
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center transform hover:scale-105 transition-transform duration-200">
                <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 mb-2">Opportunity</h3>
                <p class="text-gray-600">Earn money as a vendor or reseller on our platform</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="bg-gradient-to-r from-brand-deep-blue to-brand-green">
    <div class="max-w-7xl mx-auto py-16 px-4 sm:px-6 lg:px-8 lg:py-20">
        <div class="text-center">
            <h2 class="text-3xl sm:text-4xl font-bold text-white">
                Ready to Get Started?
            </h2>
            <p class="mt-4 text-lg text-blue-100">
                Join thousands of satisfied customers and vendors on our platform.
            </p>
            
            <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
                <a href="{{ route('storefront.index') }}" 
                   class="inline-flex items-center justify-center px-8 py-3 bg-white text-brand-deep-blue font-medium rounded-lg hover:bg-gray-100 transition-colors">
                    Start Shopping
                </a>
                
                <a href="{{ route('vendor.request.form') }}" 
                   class="inline-flex items-center justify-center px-8 py-3 border-2 border-white text-white font-medium rounded-lg hover:bg-white hover:text-brand-deep-blue transition-colors">
                    Become a Vendor
                </a>
            </div>
        </div>
    </div>
</section>
@endsection
