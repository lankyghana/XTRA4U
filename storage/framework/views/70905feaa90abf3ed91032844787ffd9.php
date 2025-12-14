<!-- Footer Component -->
<footer class="bg-gray-900 text-white">
    <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:py-16 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <!-- Company Info -->
            <div class="col-span-1 lg:col-span-2">
                <div class="flex items-center mb-4">
                    <div class="w-8 h-8 bg-linear-to-r from-blue-600 to-purple-600 rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-sm">X4U</span>
                    </div>
                    <span class="ml-2 text-xl font-bold">XTRA4U</span>
                </div>
                <p class="text-gray-300 text-sm max-w-md">
                    Your trusted platform for digital services, vendor management, and secure transactions. 
                    Connecting customers with verified vendors across Ghana.
                </p>
                <div class="mt-6 flex space-x-4">
                    <!-- Social Media Links -->
                    <a href="#" class="text-gray-400 hover:text-white transition-colors">
                        <span class="sr-only">Facebook</span>
                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/>
                        </svg>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-white transition-colors">
                        <span class="sr-only">Twitter</span>
                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8.29 20.251c7.547 0 11.675-6.253 11.675-11.675 0-.178 0-.355-.012-.53A8.348 8.348 0 0022 5.92a8.19 8.19 0 01-2.357.646 4.118 4.118 0 001.804-2.27 8.224 8.224 0 01-2.605.996 4.107 4.107 0 00-6.993 3.743 11.65 11.65 0 01-8.457-4.287 4.106 4.106 0 001.27 5.477A4.072 4.072 0 012.8 9.713v.052a4.105 4.105 0 003.292 4.022 4.095 4.095 0 01-1.853.07 4.108 4.108 0 003.834 2.85A8.233 8.233 0 012 18.407a11.616 11.616 0 006.29 1.84"/>
                        </svg>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-white transition-colors">
                        <span class="sr-only">Instagram</span>
                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.017 0C5.396 0 .029 5.367.029 11.987c0 6.62 5.367 11.987 11.988 11.987s11.987-5.367 11.987-11.987C24.004 5.367 18.637.001 12.017.001zM8.449 16.988c-1.297 0-2.448-.49-3.323-1.297C4.198 14.793 3.708 13.643 3.708 12.345s.49-2.448 1.418-3.323C6.001 8.054 7.152 7.564 8.449 7.564s2.448.49 3.323 1.458c.928.875 1.418 2.025 1.418 3.323s-.49 2.448-1.418 3.323c-.875.807-2.026 1.297-3.323 1.297z"/>
                        </svg>
                    </a>
                </div>
            </div>
            
            <!-- Quick Links -->
            <div>
                <h3 class="text-sm font-semibold text-gray-400 tracking-wider uppercase mb-4">Quick Links</h3>
                <ul class="space-y-3">
                    <li><a href="<?php echo e(route('storefront.index')); ?>" class="text-gray-300 hover:text-white transition-colors">Home</a></li>
                    <li><a href="<?php echo e(route('storefront.index')); ?>#services" class="text-gray-300 hover:text-white transition-colors">Services</a></li>
                    <li><a href="<?php echo e(route('storefront.index')); ?>#vendors" class="text-gray-300 hover:text-white transition-colors">Find Vendors</a></li>
                    <li><a href="<?php echo e(route('about')); ?>" class="text-gray-300 hover:text-white transition-colors">About Us</a></li>
                    <li><a href="<?php echo e(route('storefront.index')); ?>#contact" class="text-gray-300 hover:text-white transition-colors">Contact</a></li>
                </ul>
            </div>
            
            <!-- For Vendors -->
            <div>
                <h3 class="text-sm font-semibold text-gray-400 tracking-wider uppercase mb-4">For Vendors</h3>
                <ul class="space-y-3">
                    <li><a href="<?php echo e(route('vendor.request.form')); ?>" class="text-gray-300 hover:text-white transition-colors">Become a Vendor</a></li>
                    <li><a href="<?php echo e(route('vendor.login.form')); ?>" class="text-gray-300 hover:text-white transition-colors">Vendor Login</a></li>
                    <li><a href="#vendor-dashboard" class="text-gray-300 hover:text-white transition-colors">Dashboard</a></li>
                    <li><a href="#pricing" class="text-gray-300 hover:text-white transition-colors">Pricing</a></li>
                    <li><a href="#support" class="text-gray-300 hover:text-white transition-colors">Support</a></li>
                </ul>
            </div>
        </div>
        
        <div class="mt-12 pt-8 border-t border-gray-800 flex flex-col md:flex-row justify-between items-center">
            <p class="text-gray-400 text-sm">
                &copy; <?php echo e(date('Y')); ?> XTRA4U. All rights reserved.
            </p>
            <div class="mt-4 md:mt-0 flex space-x-6">
                <a href="<?php echo e(route('privacy')); ?>" class="text-gray-400 hover:text-white text-sm transition-colors">Privacy Policy</a>
                <a href="<?php echo e(route('terms')); ?>" class="text-gray-400 hover:text-white text-sm transition-colors">Terms of Service</a>
            </div>
        </div>
    </div>
</footer><?php /**PATH C:\Users\dktakyi001\Desktop\XTRA4U\resources\views/components/footer.blade.php ENDPATH**/ ?>