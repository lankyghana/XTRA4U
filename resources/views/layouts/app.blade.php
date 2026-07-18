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
                vendorPhone: opts.vendorPhone || '',
                categories: opts.categories || [],
                services: opts.services || [],
                selectedCategory: null,
                selectedService: null,
                selectedPackage: null,
                quantity: 1,
                showAllPackages: true,
                step: 1,
                submitting: false,
                orderMessage: '',
                recipientPhone: '',
                requiresInlineMomo: !!opts.requiresInlineMomo,
                initialPaymentFailed: !!opts.initialPaymentFailed,
                initialPaymentFailureMessage: opts.initialPaymentFailureMessage || '',
                momoModalOpen: false,
                payerPhone: '',
                payerNetwork: '',
                verifyRoute: opts.verifyRoute || '',
                paymentPollTimer: null,
                paymentPolling: false,
                paymentInitiating: false,
                paymentVerifyInFlight: false,
                activePaymentReference: null,
                paymentFailed: false,
                paymentFailureMessage: '',
                loadingServices: false,
                loadingPackages: false,
                orderRoute: opts.orderRoute || '',
                resultCheckerOrderRoute: opts.resultCheckerOrderRoute || '',
                initialCategory: opts.initialCategory || null,

                // Getters
                get filteredServices() {
                    if (!this.selectedCategory) return [];
                    try {
                        const cat = String(this.selectedCategory.value || '').toLowerCase().trim();
                        return (this.services || []).filter((s) => {
                            const sc = String(s.category || '').toLowerCase().trim();
                            // Exact category match only (no fuzzy includes matching)
                            return sc === cat;
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
                    // Auto-select a category after Alpine binds: prefer the
                    // server-requested one (e.g. 'results' when the store is
                    // opened via /store/{vendor}/result-checkers), otherwise
                    // the first category that actually has services.
                    this.$nextTick(() => {
                        const hasServices = (cat) => {
                            const key = String(cat.value || '').toLowerCase().trim();
                            return (this.services || []).some((service) => {
                                const sc = String(service.category || '').toLowerCase().trim();
                                return sc === key;  // Exact match only
                            });
                        };
                        const requested = String(this.initialCategory || '').toLowerCase().trim();
                        const preferred = requested
                            ? this.categories.find((cat) => String(cat.value || '').toLowerCase().trim() === requested && hasServices(cat))
                            : null;
                        const firstAvailable = preferred || this.categories.find(hasServices);
                        if (firstAvailable) {
                            this.selectCategory(firstAvailable);
                            // Optionally pre-select the first service under that category
                            const matches = this.filteredServices;
                            if (matches && matches.length) {
                                this.selectedService = matches[0];
                            }
                        }

						if (this.initialPaymentFailed && this.initialPaymentFailureMessage) {
							this.showPaymentFailure(this.initialPaymentFailureMessage);
						}
                    });
                },

                // Methods
                confirmMomoDetails() {
                    if (!this.payerPhone || !this.payerNetwork) {
                        this.orderMessage = 'Please enter your MoMo number and select network.';
                        return;
                    }

                    this.momoModalOpen = false;
                    // Re-attempt submit with payer details.
                    this.submitOrder();
                },

                cancelMomoDetails() {
                    this.momoModalOpen = false;
                },

                showPaymentFailure(message) {
                    this.paymentFailureMessage = message || 'Payment failed. Please try again.';
                    this.paymentFailed = true;

                    // Auto-return user to checkout after a short moment.
                    setTimeout(() => {
                        if (this.paymentFailed) {
                            this.dismissPaymentFailure();
                        }
                    }, 2500);
                },

                dismissPaymentFailure() {
                    this.paymentFailed = false;
                    this.paymentFailureMessage = '';
                    this.orderMessage = '';
                    this.paymentInitiating = false;
                    this.paymentPolling = false;
                    this.submitting = false;
                    this.scrollToCheckout();
                },

                selectCategory(cat) {
                    if (!cat) return;
                    this.selectedCategory = cat;
                    this.selectedService = null;
                    this.selectedPackage = null;
                    this.showAllPackages = true;
                    // Reveal services for the selected category.
                    // If there are matching services, auto-select the first one and show packages (step 3)
                    const matches = this.filteredServices;
                    if (matches && matches.length) {
                        this.selectedService = matches[0];
                        this.step = 3;
                    } else {
                        this.step = 2;
                    }
                },

                selectService(svc) {
                    if (!svc) return;
                    
                    // If this is an AFA service, redirect to AFA registration page
                    if (svc.is_afa && svc.afa_url) {
                        window.location.href = svc.afa_url;
                        return;
                    }
                    
                    // If this is a result checker service, proceed normally but mark it
                    this.selectedService = svc;
                    this.selectedPackage = null;
                    this.showAllPackages = true;
                    this.step = 3;
                },

                selectPackage(pkg) {
                    if (!pkg) return;
                    
                    // If this is a result checker package, proceed normally
                    // Result checker checkout will be handled by the form submission
                    
                    // If this is an AFA package, redirect to AFA registration page
                    if (pkg.is_afa && pkg.afa_url) {
                        window.location.href = pkg.afa_url;
                        return;
                    }
                    
                    this.selectedPackage = pkg;
                    this.quantity = 1;
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
                    if (this.paymentPolling) {
                        return;
                    }

                    if (this.paymentFailed) {
                        return;
                    }

                    if (!this.selectedPackage) {
                        this.orderMessage = 'Please select a package first.';
                        return;
                    }

                    if (this.requiresInlineMomo && (!this.payerPhone || !this.payerNetwork)) {
                        this.momoModalOpen = true;
                        this.orderMessage = '';
                        return;
                    }

                    this.submitting = true;
                    this.orderMessage = '';

                    const shouldShowInlineOverlayEarly = this.requiresInlineMomo && this.payerPhone && this.payerNetwork;
                    if (shouldShowInlineOverlayEarly) {
                        // Give immediate feedback; the gateway initiation call can take a few seconds.
                        this.paymentInitiating = true;
                        this.paymentPolling = true;
                        this.orderMessage = 'Initiating payment… Please wait for the MoMo prompt.';
                    }

                    const isResultChecker = !!this.selectedPackage?.is_results_checker;
                    let targetRoute = this.orderRoute;
                    let payload = {};

                    if (isResultChecker) {
                        if (this.quantity > 2) {
                            this.orderMessage = 'For bulk orders (more than 2), please contact the vendor at ' + (this.vendorPhone || 'their phone number') + '.';
                            this.submitting = false;
                            this.paymentInitiating = false;
                            this.paymentPolling = false;
                            return;
                        }

                        targetRoute = this.resultCheckerOrderRoute;
                        payload = {
                            service_id: this.selectedPackage.service_id,
                            quantity: this.quantity,
                            customer_phone: this.recipientPhone,
                            customer_name: ''
                        };
                    } else {
                        payload = {
                            vendor_id: this.vendorId,
                            category_id: this.selectedCategory?.value,
                            service_id: this.selectedService?.key,
                            service_name: this.selectedService?.name || null,
                            package_id: this.selectedPackage?.id,
                            package_name: this.selectedPackage?.name || this.selectedPackage?.title || null,
                            service_purchased: this.selectedPackage?.name || this.selectedPackage?.title || this.selectedService?.name || null,
                            amount: this.selectedPackage?.price,
                            recipient_phone: this.recipientPhone,
                            is_reseller_product: this.selectedPackage?.is_reseller_product ? 1 : 0,
                            reseller_product_id: this.selectedPackage?.reseller_product_id || null,
                            original_product_id: this.selectedPackage?.original_product_id || this.selectedPackage?.id,
                        };
                    }

                    if (this.requiresInlineMomo) {
                        payload.payer_phone = this.payerPhone;
                        payload.payer_network = this.payerNetwork;
                    }

                    try {
                        const res = await fetch(targetRoute, {
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
                            let message = '';
                            try {
                                const data = await res.json();
                                if (data?.message) {
                                    message = data.message;
                                } else if (data?.errors && typeof data.errors === 'object') {
                                    const firstField = Object.keys(data.errors)[0];
                                    const firstMsg = Array.isArray(data.errors[firstField]) ? data.errors[firstField][0] : String(data.errors[firstField]);
                                    message = firstMsg;
                                }
                            } catch (e) {
                                // Fallback for non-JSON responses
                                message = await res.text();
                            }
                            throw new Error(message || 'Order submission failed');
                        }

                        const resp = await res.json();
                        if (resp.success) {
                            // Inline gateways (e.g. BulkClix): do not redirect away; poll for completion.
                            if (this.requiresInlineMomo && resp.reference && this.verifyRoute) {
                                this.paymentInitiating = false;
                                this.orderMessage = resp.message || 'Payment initiated. Check your phone and approve the MoMo prompt.';
                                this.startPaymentPolling(resp.reference);
                                return;
                            }

                            if (resp.redirect) {
                                window.location.href = resp.redirect;
                                return;
                            }

                            // Fallback: if a gateway returned no redirect but did return a reference, poll.
                            if (resp.reference && this.verifyRoute) {
                                this.paymentInitiating = false;
                                this.orderMessage = resp.message || 'Payment initiated. Waiting for payment confirmation.';
                                this.startPaymentPolling(resp.reference);
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
                        // If initiation failed before we got a reference, drop the overlay.
                        if (this.paymentInitiating && !this.activePaymentReference) {
                            this.paymentInitiating = false;
                            this.paymentPolling = false;
                        }
                    }
                },

                stopPaymentPolling(message = null) {
                    if (this.paymentPollTimer) {
                        clearTimeout(this.paymentPollTimer);
                        this.paymentPollTimer = null;
                    }
                    this.paymentVerifyInFlight = false;
                    this.paymentInitiating = false;
                    this.paymentPolling = false;
                    this.activePaymentReference = null;

                    if (message) {
                        this.showPaymentFailure(message);
                    }
                },

                startPaymentPolling(reference) {
                    // Reset any previous poller.
                    this.stopPaymentPolling();

                    this.paymentPolling = true;
                    this.paymentInitiating = false;
                    this.activePaymentReference = reference;

                    const startedAt = Date.now();
                    const maxMs = 2 * 60 * 1000; // 2 minutes

                    const nextIntervalMs = (attempt) => {
                        // Faster early polling (gateway often updates within the first ~10–20s)
                        if (attempt < 6) return 1500;
                        if (attempt < 14) return 2000;
                        return 3000;
                    };

                    const pollOnce = async (attempt = 0) => {
                        if (!this.verifyRoute) {
                            this.stopPaymentPolling('Unable to confirm payment status at the moment.');
                            return;
                        }

                        // If another payment has started, stop this one.
                        if (this.activePaymentReference !== reference) {
                            return;
                        }

                        // Stop after timeout.
                        if (Date.now() - startedAt > maxMs) {
                            this.stopPaymentPolling('Payment confirmation timed out. Please try again or use "Track Your Order".');
                            return;
                        }

                        // Prevent overlapping verify calls.
                        if (this.paymentVerifyInFlight) {
                            this.paymentPollTimer = setTimeout(() => pollOnce(attempt + 1), nextIntervalMs(attempt));
                            return;
                        }

                        this.paymentVerifyInFlight = true;
                        try {
                            const res = await fetch(this.verifyRoute, {
                                method: 'POST',
                                credentials: 'same-origin',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                    'Accept': 'application/json'
                                },
                                body: JSON.stringify({ reference })
                            });

                            if (res.ok) {
                                const data = await res.json();

                                if (data?.status === 'success' && data?.redirect) {
                                    // Keep overlay visible while navigating.
                                    this.orderMessage = data.message || 'Payment confirmed. Redirecting…';
                                    this.activePaymentReference = reference;
                                    if (this.paymentPollTimer) {
                                        clearTimeout(this.paymentPollTimer);
                                        this.paymentPollTimer = null;
                                    }
                                    window.location.href = data.redirect;
                                    return;
                                }

                                if (data?.status === 'failed') {
                                    this.stopPaymentPolling(data.message || 'Payment failed. Please try again.');
                                    return;
                                }

                                // Pending: keep waiting.
                                if (data?.message) {
                                    this.orderMessage = data.message;
                                }
                            }
                        } catch (e) {
                            // Ignore transient network errors.
                        } finally {
                            this.paymentVerifyInFlight = false;
                        }

                        this.paymentPollTimer = setTimeout(() => pollOnce(attempt + 1), nextIntervalMs(attempt));
                    };

                    pollOnce(0);
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
