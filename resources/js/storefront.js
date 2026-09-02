/**
 * XTRA4U storefront homepage interactions.
 *
 * Deliberately small and dependency-free apart from Alpine, which the
 * application already ships. Everything here is presentational: no data
 * fetching, no form handling, no checkout logic.
 *
 * All behaviour degrades safely — if this module never runs, the page is
 * still fully readable and every link still works.
 */

const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Reveal-on-scroll.
 *
 * The hidden state is applied here rather than in the stylesheet so the
 * page renders fully visible when JavaScript is unavailable.
 */
function initReveal() {
    const targets = document.querySelectorAll('.x4 [data-x4-reveal]');

    if (!targets.length) {
        return;
    }

    if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('x4-visible'));
        return;
    }

    targets.forEach((el) => el.classList.add('x4-reveal-armed'));

    const reveal = (el) => {
        el.classList.add('x4-visible');
        observer.unobserve(el);
    };

    // A ratio threshold is unreliable here: a section taller than the
    // viewport can never reach it, and an element still being laid out
    // reports a zero-area intersection. Fire on any overlap instead and
    // use the bottom margin to hold the animation until the element has
    // properly entered the screen.
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    reveal(entry.target);
                }
            });
        },
        { threshold: 0, rootMargin: '0px 0px -40px 0px' }
    );

    targets.forEach((el) => observer.observe(el));

    // Safety net: once images have settled, anything already on screen
    // that somehow never got a callback is revealed outright, so content
    // can never be left stranded at opacity 0.
    window.addEventListener('load', () => {
        targets.forEach((el) => {
            if (el.classList.contains('x4-visible')) {
                return;
            }

            const box = el.getBoundingClientRect();

            if (box.top < window.innerHeight && box.bottom > 0) {
                reveal(el);
            }
        });
    });
}

/**
 * Count-up statistics.
 *
 * Reads its target from `data-x4-count` and animates once, the first time
 * the number scrolls into view.
 */
function initCounters() {
    const counters = document.querySelectorAll('.x4 [data-x4-count]');

    if (!counters.length) {
        return;
    }

    const format = (el, value) => {
        const decimals = parseInt(el.dataset.x4Decimals || '0', 10);
        const prefix = el.dataset.x4Prefix || '';
        const suffix = el.dataset.x4Suffix || '';
        const shown =
            decimals > 0
                ? value.toFixed(decimals)
                : Math.floor(value).toLocaleString();

        el.textContent = `${prefix}${shown}${suffix}`;
    };

    if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
        counters.forEach((el) => format(el, parseFloat(el.dataset.x4Count)));
        return;
    }

    const run = (el) => {
        const target = parseFloat(el.dataset.x4Count);
        const duration = 1600;
        const start = performance.now();

        const step = (now) => {
            const progress = Math.min((now - start) / duration, 1);
            // easeOutCubic
            format(el, (1 - Math.pow(1 - progress, 3)) * target);

            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                format(el, target);
            }
        };

        requestAnimationFrame(step);
    };

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                run(entry.target);
                observer.unobserve(entry.target);
            });
        },
        { threshold: 0.4 }
    );

    counters.forEach((el) => {
        format(el, 0);
        observer.observe(el);
    });
}

export function registerStorefrontComponents(Alpine) {
    /** Sticky header: hairline + shadow once the page has scrolled. */
    Alpine.data('x4Header', () => ({
        scrolled: false,
        mobileOpen: false,

        init() {
            this.onScroll();
            window.addEventListener('scroll', () => this.onScroll(), {
                passive: true,
            });
        },

        onScroll() {
            this.scrolled = window.scrollY > 16;
        },

        toggleMobile() {
            this.mobileOpen = !this.mobileOpen;
        },
    }));

    /**
     * Hero image carousel.
     *
     * Only tracks which slide is active — the cross-fade itself is CSS.
     * Driving opacity through an Alpine `:style` string would call
     * setAttribute() and wipe both the element's own styles and x-show's
     * display toggle, so the binding here is `:class` only.
     */
    Alpine.data('x4Hero', (slideCount = 1) => ({
        active: 0,
        timer: null,
        // Set once the visitor picks a slide themselves; autoplay then
        // stays out of the way rather than yanking the slide back.
        pinned: false,

        init() {
            if (slideCount < 2 || prefersReducedMotion()) {
                return;
            }

            this.resume();

            // Do not animate against a hidden tab.
            document.addEventListener('visibilitychange', () => {
                document.hidden ? this.pause() : this.resume();
            });
        },

        resume() {
            if (this.timer || this.pinned || slideCount < 2 || prefersReducedMotion()) {
                return;
            }

            this.timer = setInterval(() => {
                this.active = (this.active + 1) % slideCount;
            }, 7000);
        },

        pause() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },

        go(index) {
            this.active = index;
            this.pinned = true;
            this.pause();
        },
    }));
}

export function initStorefront() {
    initReveal();
    initCounters();
}
