import './bootstrap';
import Alpine from 'alpinejs';

// Make Alpine available globally
window.Alpine = Alpine;

// Alpine.js global store for app state
document.addEventListener('alpine:init', () => {
    Alpine.store('app', {
        mobileMenuOpen: false,
        userMenuOpen: false,
        loading: false,
        
        toggleMobileMenu() {
            this.mobileMenuOpen = !this.mobileMenuOpen;
        },
        
        closeMobileMenu() {
            this.mobileMenuOpen = false;
        },
        
        toggleUserMenu() {
            this.userMenuOpen = !this.userMenuOpen;
        },
        
        closeUserMenu() {
            this.userMenuOpen = false;
        },
        
        setLoading(state) {
            this.loading = state;
        }
    });
    
    // Form validation component
    Alpine.data('formValidator', () => ({
        errors: {},
        
        validateField(field, value, rules) {
            this.errors[field] = [];
            
            if (rules.required && (!value || value.trim() === '')) {
                this.errors[field].push(`${field} is required`);
            }
            
            if (rules.email && value && !this.isValidEmail(value)) {
                this.errors[field].push('Please enter a valid email address');
            }
            
            if (rules.minLength && value && value.length < rules.minLength) {
                this.errors[field].push(`${field} must be at least ${rules.minLength} characters`);
            }
            
            if (rules.phone && value && !this.isValidPhone(value)) {
                this.errors[field].push('Please enter a valid phone number');
            }
        },
        
        isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },
        
        isValidPhone(phone) {
            const re = /^[\+]?[0-9]{10,15}$/;
            return re.test(phone.replace(/[\s\-\(\)]/g, ''));
        },
        
        hasErrors() {
            return Object.values(this.errors).some(fieldErrors => fieldErrors.length > 0);
        }
    }));
    
    // Checkout payment form component
    Alpine.data('paymentForm', (vendors = []) => ({
        vendors,
        selectedVendorId: '',
        selectedService: null,
        processing: false,

        get selectedVendor() {
            if (!this.selectedVendorId) {
                return null;
            }
            return this.vendors.find((vendor) => Number(vendor.id) === Number(this.selectedVendorId)) || null;
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
                alert("Please select one of the vendor's services (price) before completing payment.");
                return;
            }

            this.processing = true;

            // Simply submit the form - no need for fetch + submit
            event.target.submit();
        }
    }));
    
    // Notification component
    Alpine.data('notification', (type = 'info', message = '', duration = 5000) => ({
        show: true,
        type: type,
        message: message,
        
        init() {
            if (duration > 0) {
                setTimeout(() => {
                    this.show = false;
                }, duration);
            }
        },
        
        close() {
            this.show = false;
        }
    }));
});

// Global utility functions
window.utils = {
    // Format currency
    formatCurrency(amount, currency = 'GHS') {
        return new Intl.NumberFormat('en-GH', {
            style: 'currency',
            currency: currency,
            minimumFractionDigits: 2
        }).format(amount);
    },
    
    // Format date
    formatDate(date, options = {}) {
        const defaultOptions = {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };
        return new Intl.DateTimeFormat('en-GH', { ...defaultOptions, ...options }).format(new Date(date));
    },
    
    // Debounce function
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    // Copy to clipboard
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (err) {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                return true;
            } catch (err) {
                return false;
            } finally {
                document.body.removeChild(textArea);
            }
        }
    }
};

// Smooth scrolling for anchor links
document.addEventListener('DOMContentLoaded', function() {
    // Handle anchor links
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
    
    // Form submission handling
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('opacity-50');
                
                // Re-enable button after 3 seconds (in case of no redirect)
                setTimeout(() => {
                    submitButton.disabled = false;
                    submitButton.classList.remove('opacity-50');
                }, 3000);
            }
        });
    });
    
    // Auto-hide flash messages
    const alerts = document.querySelectorAll('[data-auto-hide]');
    alerts.forEach(alert => {
        const duration = parseInt(alert.dataset.autoHide) || 5000;
        setTimeout(() => {
            if (alert.parentNode) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateX(100%)';
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }
        }, duration);
    });
});

// Performance optimization: Lazy loading
const observerOptions = {
    root: null,
    rootMargin: '50px',
    threshold: 0.1
};

const imageObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            if (img.dataset.src) {
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        }
    });
}, observerOptions);

// Observe all images with data-src attribute
document.querySelectorAll('img[data-src]').forEach(img => {
    imageObserver.observe(img);
});

// Service Worker registration (for PWA capabilities)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => {
                console.log('SW registered: ', registration);
            })
            .catch((registrationError) => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}

// Start Alpine.js
Alpine.start();