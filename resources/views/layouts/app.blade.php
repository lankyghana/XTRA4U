<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <title>@yield('title', 'XTRA4U - Digital Services Platform')</title>
    <meta name="description" content="@yield('description', 'XTRA4U - Your trusted platform for digital services, vendor management, and secure transactions.')">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    
    <!-- Vite Assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    
    {{-- Global Alpine.js component definitions --}}
    <script>
        function vendorStore(opts = {}) {
            return {
                // State properties
                vendorId: opts.vendorId || null,
                categories: opts.categories || [],
                services: opts.services || [],
                selectedCategory: null,
                selectedService: null,
                selectedPackage: null,
                step: 1,
                submitting: false,
                orderMessage: '',
                recipientPhone: '',
                payerPhone: '',
                customerEmail: '',
                loadingServices: false,
                loadingPackages: false,
                orderRoute: opts.orderRoute || '',

                // Getters
                get filteredServices() {
                    if (!this.selectedCategory) return [];
                    try {
                        const cat = String(this.selectedCategory.value || '').toLowerCase().trim();
                        return (this.services || []).filter((s) => {
                            const sc = String(s.category || '').toLowerCase().trim();
                            if (!sc && !cat) return true;
                            if (sc === cat) return true;
                            if (sc.includes(cat) || cat.includes(sc)) return true;
                            return false;
                        });
                    } catch (e) {
                        return [];
                    }
                },

                get availablePackages() {
                    return this.selectedService?.packages || [];
                },

                // Initialization
                init() {
                    this.selectedCategory = null;
                    // Auto-select first available category after Alpine binds
                    this.$nextTick(() => {
                        const firstAvailable = this.categories.find((cat) => {
                            const key = String(cat.value || '').toLowerCase().trim();
                            return (this.services || []).some((service) => {
                                const sc = String(service.category || '').toLowerCase().trim();
                                return sc === key || sc.includes(key) || key.includes(sc);
                            });
                        });
                        if (firstAvailable) {
                            this.selectCategory(firstAvailable);
                            // Optionally pre-select the first service under that category
                            const matches = this.filteredServices;
                            if (matches && matches.length) {
                                this.selectedService = matches[0];
                            }
                        }
                    });
                },

                // Methods
                selectCategory(cat) {
                    if (!cat) return;
                    this.selectedCategory = cat;
                    this.selectedService = null;
                    this.selectedPackage = null;
                    this.step = 2;
                },

                selectService(svc) {
                    if (!svc) return;
                    this.selectedService = svc;
                    this.selectedPackage = null;
                    this.step = 3;
                },

                selectPackage(pkg) {
                    if (!pkg) return;
                    this.selectedPackage = pkg;
                    this.step = 4;
                },

                formatCurrency(v) {
                    if (v === null || typeof v === 'undefined') return '';
                    try {
                        return new Intl.NumberFormat('en-GH', { style: 'currency', currency: 'GHS' }).format(v);
                    } catch (error) {
                        return 'GHS ' + Number(v).toFixed(2);
                    }
                },

                async submitOrder() {
                    if (!this.selectedPackage) {
                        this.orderMessage = 'Please select a package first.';
                        return;
                    }

                    this.submitting = true;
                    this.orderMessage = '';

                    const payload = {
                        vendor_id: this.vendorId,
                        category_id: this.selectedCategory?.value,
                        service_id: this.selectedService?.key,
                        package_id: this.selectedPackage?.id,
                        amount: this.selectedPackage?.price,
                        recipient_phone: this.recipientPhone,
                        payer_phone: this.payerPhone,
                        customer_email: this.customerEmail,
                        is_reseller_product: this.selectedPackage?.is_reseller_product ? 1 : 0,
                        reseller_product_id: this.selectedPackage?.reseller_product_id || null,
                        original_product_id: this.selectedPackage?.original_product_id || this.selectedPackage?.id,
                    };

                    try {
                        const res = await fetch(this.orderRoute, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify(payload)
                        });

                        if (!res.ok) {
                            const text = await res.text();
                            throw new Error(text || 'Order submission failed');
                        }

                        const resp = await res.json();
                        if (resp.success) {
                            if (resp.redirect) {
                                window.location.href = resp.redirect;
                                return;
                            }
                            this.orderMessage = resp.message || 'Order submitted successfully';
                        } else {
                            this.orderMessage = resp.message || 'Order failed';
                        }
                    } catch (err) {
                        console.error(err);
                        this.orderMessage = err.message || 'An error occurred while submitting the order';
                    } finally {
                        this.submitting = false;
                    }
                }
            };
        }
    </script>
    
    @stack('head-scripts')
    
    @stack('styles')
</head>
<body class="h-full bg-gray-50 antialiased" x-data="{ mobileMenuOpen: false }">
    <!-- Mobile Menu Overlay -->
    <div x-show="mobileMenuOpen" 
         x-transition:enter="transition-opacity ease-linear duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 bg-gray-900/80 lg:hidden"
         @click="mobileMenuOpen = false"></div>

    <!-- Header/Navigation -->
    @include('components.navigation')
    
    <!-- Main Content -->
    <main class="min-h-screen">
        <!-- Alert Messages -->
        @if(session('success'))
            @include('components.alert', ['type' => 'success', 'message' => session('success')])
        @endif
        
        @if(session('error'))
            @include('components.alert', ['type' => 'error', 'message' => session('error')])
        @endif
        
        @if($errors->any())
            @include('components.alert', ['type' => 'error', 'message' => 'Please fix the errors below.'])
        @endif
        
        @yield('content')
    </main>
    
    <!-- Footer -->
    @include('components.footer')
    
    @stack('scripts')
</body>
</html>
