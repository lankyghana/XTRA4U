<!-- Navigation Component -->
<nav class="bg-white shadow-lg sticky top-0 z-[60]" x-data="{ userMenuOpen: false }">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <!-- Logo & Primary Nav -->
            <div class="flex items-center">
                <!-- Logo -->
                <div class="shrink-0">
                    <a href="<?php echo e(route('storefront.index')); ?>" class="flex items-center">
                        <div class="w-8 h-8 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                            <span class="text-white font-bold text-sm">X4U</span>
                        </div>
                        <span class="ml-2 text-xl font-bold text-gray-900">XTRA4U</span>
                    </a>
                </div>
                
                <!-- Desktop Navigation -->
                <div class="hidden md:ml-10 md:flex md:space-x-8">
                    <a href="<?php echo e(route('storefront.index')); ?>" 
                       class="border-brand-deep-blue text-gray-900 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium hover:border-brand-green transition-colors">
                        Home
                    </a>
                    <a href="<?php echo e(route('checkout.show')); ?>" 
                       class="border-transparent text-gray-500 hover:border-brand-bright-blue hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium transition-colors">
                        Services
                    </a>
                    <a href="<?php echo e(route('storefront.index')); ?>#vendors" 
                       class="border-transparent text-gray-500 hover:border-brand-bright-blue hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium transition-colors">
                        Vendors
                    </a>
                    <a href="<?php echo e(route('about')); ?>" 
                       class="border-transparent text-gray-500 hover:border-brand-bright-blue hover:text-gray-700 inline-flex items-center px-1 pt-1 border-b-2 text-sm font-medium transition-colors">
                        About
                    </a>
                </div>
            </div>
            
            <!-- Right Side Actions -->
            <div class="flex items-center space-x-4">
                <!-- Vendor Login Button -->
                <a href="<?php echo e(route('vendor.login.form')); ?>" 
                   class="hidden md:inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-brand-deep-blue bg-blue-50 hover:bg-blue-100 transition-colors">
                    Vendor Portal
                </a>
                
                <!-- Become Vendor Button -->
                <a href="<?php echo e(route('vendor.request.form')); ?>" 
                   class="hidden md:inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-brand-xtra-green hover:bg-brand-bright-green transition-all duration-200 shadow-sm">
                    Become Vendor
                </a>
                
                <!-- Mobile menu button -->
                <button @click="mobileMenuOpen = !mobileMenuOpen" 
                        class="md:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-brand-deep-blue">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>
</nav>

<!-- Mobile Navigation Slide-out Panel -->
<div x-show="mobileMenuOpen" 
     x-cloak
     class="fixed inset-0 z-50 md:hidden"
     role="dialog" 
     aria-modal="true">
    
    <!-- Backdrop -->
    <div x-show="mobileMenuOpen"
         x-transition:enter="transition-opacity ease-linear duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-gray-900/80"
         @click="mobileMenuOpen = false"></div>
    
    <!-- Panel -->
    <div x-show="mobileMenuOpen"
         x-transition:enter="transform transition ease-out duration-300"
         x-transition:enter-start="translate-x-full"
         x-transition:enter-end="translate-x-0"
         x-transition:leave="transform transition ease-in duration-200"
         x-transition:leave-start="translate-x-0"
         x-transition:leave-end="translate-x-full"
         class="fixed inset-y-0 right-0 w-full max-w-xs bg-white shadow-xl">
        
        <!-- Panel Header -->
        <div class="flex items-center justify-between p-4 border-b border-gray-200">
            <a href="<?php echo e(route('storefront.index')); ?>" class="flex items-center" @click="mobileMenuOpen = false">
                <div class="w-8 h-8 bg-brand-deep-blue rounded-lg flex items-center justify-center">
                    <span class="text-white font-bold text-sm">X4U</span>
                </div>
                <span class="ml-2 text-xl font-bold text-gray-900">XTRA4U</span>
            </a>
            <button @click="mobileMenuOpen = false" 
                    class="p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-brand-deep-blue">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <!-- Navigation Links -->
        <div class="px-2 pt-4 pb-3 space-y-1">
            <a href="<?php echo e(route('storefront.index')); ?>" 
               @click="mobileMenuOpen = false"
               class="bg-blue-50 border-brand-deep-blue text-brand-deep-blue block pl-3 pr-4 py-2 border-l-4 text-base font-medium">
                Home
            </a>
            <a href="<?php echo e(route('checkout.show')); ?>" 
               @click="mobileMenuOpen = false"
               class="border-transparent text-gray-600 hover:bg-gray-50 hover:border-brand-bright-blue hover:text-gray-800 block pl-3 pr-4 py-2 border-l-4 text-base font-medium transition-colors">
                Services
            </a>
            <a href="<?php echo e(route('storefront.index')); ?>#vendors" 
               @click="mobileMenuOpen = false"
               class="border-transparent text-gray-600 hover:bg-gray-50 hover:border-brand-bright-blue hover:text-gray-800 block pl-3 pr-4 py-2 border-l-4 text-base font-medium transition-colors">
                Vendors
            </a>
            <a href="<?php echo e(route('about')); ?>" 
               @click="mobileMenuOpen = false"
               class="border-transparent text-gray-600 hover:bg-gray-50 hover:border-brand-bright-blue hover:text-gray-800 block pl-3 pr-4 py-2 border-l-4 text-base font-medium transition-colors">
                About
            </a>
        </div>
        
        <!-- Action Buttons -->
        <div class="pt-4 pb-3 border-t border-gray-200">
            <div class="px-4 space-y-3">
                <a href="<?php echo e(route('vendor.login.form')); ?>" 
                   @click="mobileMenuOpen = false"
                   class="block w-full px-4 py-2 text-center text-base font-medium text-brand-deep-blue bg-blue-50 hover:bg-blue-100 rounded-md transition-colors">
                    Vendor Portal
                </a>
                <a href="<?php echo e(route('vendor.request.form')); ?>" 
                   @click="mobileMenuOpen = false"
                   class="block w-full px-4 py-2 text-center text-base font-medium text-white bg-brand-xtra-green hover:bg-brand-bright-green rounded-md transition-all duration-200">
                    Become Vendor
                </a>
            </div>
        </div>
    </div>
</div><?php /**PATH C:\Users\dktakyi001\Desktop\XTRA4U\resources\views/components/navigation.blade.php ENDPATH**/ ?>