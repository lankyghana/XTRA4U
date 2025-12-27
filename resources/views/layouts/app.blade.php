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
                showAllPackages: true,
                step: 1,
                submitting: false,
                orderMessage: '',
                recipientPhone: '',
                payerPhone: '',
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

                    const raw = this.selectedService?.packages;
                    if (!raw) return [];

                    let pkgs = raw;

                    // Some payloads may arrive as an object (associative array) instead of an array.
                    if (!Array.isArray(pkgs) && typeof pkgs === 'object') {
                        pkgs = Object.values(pkgs);
                    }

                    if (!Array.isArray(pkgs)) return [];

                    return pkgs
                        .filter((p) => p && typeof p === 'object')
                        .map((p, idx) => {
                            const hasId = typeof p.id !== 'undefined' && p.id !== null && String(p.id).trim() !== '';
                            const id = hasId ? p.id : `${this.selectedService?.key || 'svc'}_${idx}`;
                            return { ...p, id };
                        });
                },

                get packagesToShow() {
                    const pkgs = this.availablePackages || [];
                    if (this.showAllPackages) return pkgs;
                    if (!this.selectedPackage) return pkgs;

                    const selectedId = String(this.selectedPackage.id);
                    return pkgs.filter((p) => p && typeof p.id !== 'undefined' && String(p.id) === selectedId);
                },

                get isCheckoutOnlyMode() {
                    return !!(this.selectedPackage && this.step >= 4 && this.showAllPackages === false);
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
                    this.showAllPackages = true;
                    this.step = 2;
                },

                selectService(svc) {
                    if (!svc) return;
                    
                    // If this is an AFA service, redirect to AFA registration page
                    if (svc.is_afa && svc.afa_url) {
                        window.location.href = svc.afa_url;
                        return;
                    }
                    
                    this.selectedService = svc;
                    this.selectedPackage = null;
                    this.showAllPackages = true;
                    this.step = 3;
                },

                selectPackage(pkg) {
                    if (!pkg) return;
                    
                    // If this is an AFA package, redirect to AFA registration page
                    if (pkg.is_afa && pkg.afa_url) {
                        window.location.href = pkg.afa_url;
                        return;
                    }
                    
                    this.selectedPackage = pkg;
                    this.showAllPackages = false;
                    this.step = 4;

                    // Bring checkout into view immediately after selection
                    this.scrollToCheckout();
                },

                expandPackages() {
                    // Show full package list again and return to package-selection step.
                    this.showAllPackages = true;
                    this.step = 3;

                    this.$nextTick(() => {
                        const el = this.$refs?.packageSection || document.getElementById('package-section');
                        if (el && typeof el.scrollIntoView === 'function') {
                            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });
                },

                scrollToCheckout() {
                    // Smooth scroll to checkout section (used by sticky mobile CTA)
                    this.$nextTick(() => {
                        const el = this.$refs?.checkoutSection || document.getElementById('checkout-section');
                        if (el && typeof el.scrollIntoView === 'function') {
                            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }

                        // Focus the first field to make the next step obvious
                        this.$nextTick(() => {
                            const input = this.$refs?.recipientPhoneInput;
                            if (input && typeof input.focus === 'function') {
                                input.focus();
                            }
                        });
                    });
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
                        service_name: this.selectedService?.name || null,
                        package_id: this.selectedPackage?.id,
                        package_name: this.selectedPackage?.name || this.selectedPackage?.title || null,
                        service_purchased: this.selectedPackage?.name || this.selectedPackage?.title || this.selectedService?.name || null,
                        amount: this.selectedPackage?.price,
                        recipient_phone: this.recipientPhone,
                        payer_phone: this.payerPhone,
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
    
    <!-- WhatsApp Channel Widget -->
    @include('components.whatsapp-widget')
    
    <!-- CSRF Token Auto-Refresh Script -->
    <script>
        (function() {
            // Refresh CSRF token when page becomes visible (after tab switch or wake from sleep)
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    refreshCsrfToken();
                }
            });

            // Also refresh when window regains focus
            window.addEventListener('focus', function() {
                refreshCsrfToken();
            });

            // Refresh token every 30 minutes to prevent expiration
            setInterval(refreshCsrfToken, 30 * 60 * 1000);

            function refreshCsrfToken() {
                fetch('/csrf-token', {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'application/json' }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('CSRF token refresh failed');
                    }
                    return response.json();
                })
                .then(data => {
                    const token = data.csrfToken || data.token;

                    if (token) {
                        // Update meta tag
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) meta.setAttribute('content', token);
                        
                        // Update all hidden CSRF inputs in forms
                        document.querySelectorAll('input[name="_token"]').forEach(input => {
                            input.value = token;
                        });
                    }
                })
                .catch(err => console.log('CSRF refresh skipped'));
            }
        })();
    </script>
    
    @stack('scripts')
</body>
</html>
