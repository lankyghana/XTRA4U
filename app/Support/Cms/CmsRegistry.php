<?php

namespace App\Support\Cms;

/**
 * Code-defined catalogue of what the CMS can manage.
 *
 * The defaults below are the content that used to be hardcoded in the Blade
 * views, copied verbatim. They serve two purposes:
 *   - the one-time backfill (CmsDefaults::install) that seeds the database, and
 *   - the render-time fallback when a section row does not exist yet, so a
 *     partially migrated environment never shows an empty page.
 *
 * Field types: text | textarea | link | image | select | number | repeater.
 * In any text meant for headings, [[double brackets]] mark the highlighted words.
 */
final class CmsRegistry
{
    public const ICONS = ['shield', 'zap', 'phone', 'star', 'check', 'clock', 'users', 'target', 'wifi', 'grad', 'ticket', 'document', 'lock', 'mail'];

    public const SOCIAL = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X (Twitter)',
        'tiktok' => 'TikTok',
        'youtube' => 'YouTube',
        'linkedin' => 'LinkedIn',
    ];

    public const NAV_LOCATIONS = [
        'header' => 'Header menu',
        'footer_quick' => 'Footer: Quick Links',
        'footer_legal' => 'Footer: Legal',
    ];

    public const BUNDLED_PREFIX = 'bundled:';

    // ------------------------------------------------------------------ pages

    /** Structured pages whose sections are managed field-by-field. */
    public static function structuredPages(): array
    {
        return [
            'home' => ['title' => 'Homepage', 'slug' => 'home', 'url' => '/'],
            'about' => ['title' => 'About Us', 'slug' => 'about', 'url' => '/about'],
        ];
    }

    /** Default SEO for the structured pages. */
    public static function structuredSeo(string $key): array
    {
        return match ($key) {
            'home' => [
                'seo_title' => 'XTRA4U - Your Digital Services Platform',
                'meta_description' => 'Connect with trusted vendors across Ghana. Secure transactions, verified services, and seamless digital experiences.',
            ],
            'about' => [
                'seo_title' => 'About Us - XTRA4U',
                'meta_description' => 'Learn about XTRA4U - Your reliable digital platform for fast and affordable online services in Ghana.',
            ],
            default => [],
        };
    }

    /** System rich pages: fixed slug/route, seeded from the former Blade copy. */
    public static function systemRichPages(): array
    {
        return [
            'privacy' => [
                'title' => 'Privacy Policy',
                'icon' => 'lock',
                'seo_title' => 'Privacy Policy - XTRA4U',
                'meta_description' => 'Privacy Policy for XTRA4U - Learn how we collect, use, and protect your personal information.',
                'body' => <<<'MD'
At **XTRA4U**, we value your privacy and are committed to protecting your personal information.

## Information We Collect

We may collect the following information:

- Name, phone number, and email address
- Account and transaction details
- Login and usage data

## How We Use Your Information

Your information is used to:

- Provide and improve our services
- Process transactions securely
- Communicate important updates and support
- Manage reseller and vendor accounts

## Data Protection

We take appropriate security measures to protect your data against unauthorized access, alteration, or disclosure.

## Third Parties

XTRA4U does not sell or share your personal information with third parties, except where required by law or necessary to deliver our services.

---

By using XTRA4U, you agree to the collection and use of your information as described in this policy.
MD,
            ],
            'terms' => [
                'title' => 'Terms of Service',
                'icon' => 'document',
                'seo_title' => 'Terms of Service - XTRA4U',
                'meta_description' => 'Terms of Service for XTRA4U - Read our terms and conditions for using our platform.',
                'body' => <<<'MD'
By accessing or using **XTRA4U**, you agree to the following terms:

## Use of Services

- You must provide accurate information when registering.
- All services are for lawful use only.
- Any attempt to abuse, hack, or manipulate the platform will result in account suspension.

## Payments & Transactions

- All payments made on XTRA4U are final once services are delivered.
- Users are responsible for confirming details before completing transactions.

## Reseller & Vendor Accounts

- Resellers and vendors must follow XTRA4U pricing and policies.
- Any fraudulent activity will lead to immediate termination of the account without refund.

## Service Availability

We aim to provide uninterrupted service, but XTRA4U is not liable for delays or outages caused by network issues, system maintenance, or third-party providers.

## Account Termination

XTRA4U reserves the right to suspend or terminate any account that violates these terms.

---

By continuing to use XTRA4U, you acknowledge that you have read, understood, and agree to be bound by these Terms of Service.
MD,
            ],
        ];
    }

    // --------------------------------------------------------------- sections

    /** Section definitions per structured page, in default display order. */
    public static function sections(string $page): array
    {
        return match ($page) {
            'home' => self::homeSections(),
            'about' => self::aboutSections(),
            default => [],
        };
    }

    public static function section(string $page, string $key): ?array
    {
        return self::sections($page)[$key] ?? null;
    }

    /** Default data for one section, built from its field defaults. */
    public static function defaults(string $page, string $key): array
    {
        $def = self::section($page, $key);

        return $def ? self::fieldDefaults($def['fields']) : [];
    }

    public static function fieldDefaults(array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            $out[$name] = $field['type'] === 'repeater'
                ? ($field['default'] ?? [])
                : ($field['default'] ?? '');
        }

        return $out;
    }

    private static function homeSections(): array
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'help' => 'The first thing visitors see. The slideshow beside it is managed under Banners.',
                'movable' => false,
                'hideable' => false,
                'fields' => [
                    'badge' => self::text('Badge text', "Ghana's Digital Services Marketplace", 80),
                    'title' => self::text('Title', 'Your Gateway to [[Digital Services]]', 120, 'Wrap words in [[double brackets]] to highlight them.'),
                    'description' => self::textarea('Description', 'Connect with verified vendors across Ghana. Secure transactions, reliable services, and seamless digital experiences all in one place.', 300),
                    'primary_text' => self::text('Primary button', 'Buy Now', 40),
                    'primary_link' => self::link('Primary button link', '{shop}'),
                    'secondary_text' => self::text('Secondary button', 'Order Status', 40),
                    'secondary_link' => self::link('Secondary button link', '/order-status'),
                    'points' => self::repeater('Checklist', ['text' => self::text('Text', '', 60)], [
                        ['text' => 'Verified Vendors'],
                        ['text' => 'Mobile Money Payments'],
                        ['text' => 'Under 5 minutes'],
                    ], 0, 5),
                ],
            ],
            'trust' => [
                'label' => 'Trust strip',
                'help' => 'The dark row of four reassurances under the hero.',
                'movable' => false,
                'hideable' => true,
                'fields' => [
                    'items' => self::repeater('Items', [
                        'icon' => self::icon('Icon', 'shield'),
                        'title' => self::text('Title', '', 40),
                        'desc' => self::text('Description', '', 80),
                    ], [
                        ['icon' => 'shield', 'title' => 'Secure & Reliable', 'desc' => 'Your transactions are safe with us.'],
                        ['icon' => 'zap', 'title' => 'Instant Delivery', 'desc' => 'Get what you need, instantly.'],
                        ['icon' => 'phone', 'title' => '24/7 Support', 'desc' => "We're here for you, anytime."],
                        ['icon' => 'star', 'title' => 'Trusted by Thousands', 'desc' => 'Join thousands of satisfied customers.'],
                    ], 2, 4),
                ],
            ],
            'stats' => [
                'label' => 'Payment networks & stats',
                'help' => 'The accepted-payment logos strip. Only the three figures are editable; the logos stay fixed.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'stats' => self::repeater('Figures', self::statFields(), [
                        ['value' => '500', 'decimals' => '0', 'prefix' => '', 'suffix' => '+', 'label' => 'Verified Vendors'],
                        ['value' => '10', 'decimals' => '0', 'prefix' => '', 'suffix' => 'K+', 'label' => 'Transactions'],
                        ['value' => '99.9', 'decimals' => '1', 'prefix' => '', 'suffix' => '%', 'label' => 'Uptime'],
                    ], 0, 3),
                ],
            ],
            'services' => [
                'label' => 'Services intro',
                'help' => 'Heading above the service cards. The cards themselves come from the platform service configuration.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'eyebrow' => self::text('Small label', 'Services', 40),
                    'title' => self::text('Title', 'Find the Digital Service [[You Need]]', 120),
                    'description' => self::textarea('Description', 'Discover services from verified vendors all delivered digitally, all backed by secure payments.', 240),
                ],
            ],
            'featured' => [
                'label' => 'Featured service',
                'help' => 'Results-checker spotlight. The sample order card on the right is an illustration and stays fixed.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'image' => self::image('Banner image', self::BUNDLED_PREFIX.'images/storefront/feature-results.jpg'),
                    'image_alt' => self::text('Image description (alt text)', 'Students in Ghana checking their exam results', 160),
                    'eyebrow' => self::text('Small label', 'Featured Service', 40),
                    'title' => self::text('Title', 'Results Checker PINs, [[Delivered Instantly]]', 120),
                    'side_title' => self::text('Side headline', 'WAEC · BECE · NOVDEC', 60),
                    'side_caption' => self::text('Side caption', 'All major exam boards covered', 80),
                    'description' => self::textarea('Description', 'Buy WAEC, BECE, and NOVDEC results checker PINs securely. PINs are stored safely — retrieve yours anytime you need it.', 300),
                    'benefits' => self::repeater('Benefits', [
                        'label' => self::text('Title', '', 40),
                        'desc' => self::text('Description', '', 80),
                    ], [
                        ['label' => 'Instant Delivery', 'desc' => 'PINs sent immediately after payment'],
                        ['label' => 'Securely Stored', 'desc' => 'Your PIN saved for later retrieval'],
                        ['label' => 'Retrieve Anytime', 'desc' => 'Access your PIN whenever you need it'],
                        ['label' => 'Buy in Bulk', 'desc' => 'For schools, cafés, and agents'],
                    ], 0, 6),
                    'primary_text' => self::text('Primary button', 'Buy a Results Checker', 40),
                    'primary_link' => self::link('Primary button link', '/results-checkers'),
                    'secondary_text' => self::text('Secondary button', 'Retrieve My PIN', 40),
                    'secondary_link' => self::link('Secondary button link', '/results-checker/status'),
                ],
            ],
            'how' => [
                'label' => 'How it works',
                'help' => 'Three numbered steps and a button.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'eyebrow' => self::text('Small label', 'How it works', 40),
                    'title' => self::text('Title', 'Three steps. Under 5 minutes.', 120),
                    'description' => self::text('Description', 'No complicated process. Just find, pay, and receive.', 200),
                    'steps' => self::repeater('Steps', [
                        'title' => self::text('Title', '', 60),
                        'body' => self::textarea('Text', '', 220),
                    ], [
                        ['title' => 'Choose Your Service', 'body' => 'Browse our marketplace, pick a vendor, and select the service or product you need.'],
                        ['title' => 'Make Payment', 'body' => 'Pay securely via Mobile Money — MTN, Telecel, or AirtelTigo. Your payment is protected.'],
                        ['title' => 'Get It Instantly', 'body' => 'Receive your service within minutes. Track order status and get SMS/email notifications.'],
                    ], 1, 4),
                    'button_text' => self::text('Button', 'Get Started', 40),
                    'button_link' => self::link('Button link', '{shop}'),
                ],
            ],
            'why' => [
                'label' => 'Why XTRA4U',
                'help' => 'Image, pitch, four feature tiles and the small platform-health card.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'image' => self::image('Image', self::BUNDLED_PREFIX.'images/storefront/why-customer.jpg'),
                    'image_alt' => self::text('Image description (alt text)', 'A customer buying digital services on XTRA4U', 160),
                    'eyebrow' => self::text('Small label', 'Why XTRA4U', 40),
                    'title' => self::text('Title', 'Built for speed, [[backed by trust.]]', 120),
                    'description' => self::textarea('Description', 'XTRA4U makes buying digital services in Ghana fast, safe, and simple. Every vendor is verified. Every transaction is protected. From data bundles to exam PINs; find it, pay, and receive it in minutes.', 400),
                    'features' => self::repeater('Feature tiles', [
                        'icon' => self::icon('Icon', 'shield'),
                        'title' => self::text('Title', '', 40),
                        'body' => self::textarea('Text', '', 160),
                    ], [
                        ['icon' => 'shield', 'title' => 'Verified Vendors', 'body' => 'Every vendor is ID-verified and quality-assured before listing on XTRA4U.'],
                        ['icon' => 'check', 'title' => 'Secure Transactions', 'body' => 'Encrypted payments with fraud protection. Your money is safe at every step.'],
                        ['icon' => 'zap', 'title' => 'Lightning Fast', 'body' => 'Most orders complete in under 5 minutes. Instant processing and notifications.'],
                        ['icon' => 'clock', 'title' => '24/7 Support', 'body' => 'Support available around the clock via WhatsApp, email, and the platform.'],
                    ], 0, 6),
                    'metrics' => self::repeater('Platform health card', self::statFields(), [
                        ['value' => '99.9', 'decimals' => '1', 'prefix' => '', 'suffix' => '%', 'label' => 'Uptime'],
                        ['value' => '5', 'decimals' => '0', 'prefix' => '< ', 'suffix' => ' min', 'label' => 'Avg. Delivery'],
                        ['value' => '4.8', 'decimals' => '1', 'prefix' => '', 'suffix' => ' / 5', 'label' => 'Vendor Rating'],
                    ], 0, 4),
                ],
            ],
            'vendors' => [
                'label' => 'Vendor recruitment',
                'help' => 'The dark "sell on XTRA4U" panel. The dashboard mock-up beside it is an illustration and stays fixed.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'image' => self::image('Image', self::BUNDLED_PREFIX.'images/storefront/vendor-person.jpg'),
                    'image_alt' => self::text('Image description (alt text)', 'An XTRA4U vendor managing their storefront', 160),
                    'badge' => self::text('Badge', 'For Vendors', 30),
                    'title' => self::text('Title', 'Have a Digital Service to Sell?', 120),
                    'description' => self::textarea('Description', 'Join XTRA4U, reach customers across Ghana, and manage your services from one platform. Get paid through Mobile Money with no complications.', 300),
                    'stats' => self::repeater('Figures', self::statFields(), [
                        ['value' => '500', 'decimals' => '0', 'prefix' => '', 'suffix' => '+', 'label' => 'Active Vendors'],
                        ['value' => '1', 'decimals' => '0', 'prefix' => 'GH₵', 'suffix' => 'M+', 'label' => 'Paid Out'],
                        ['value' => '24', 'decimals' => '0', 'prefix' => '', 'suffix' => '/7', 'label' => 'Support'],
                    ], 0, 3),
                    'primary_text' => self::text('Primary button', 'Become a Vendor', 40),
                    'primary_link' => self::link('Primary button link', '/vendor/request'),
                    'secondary_text' => self::text('Secondary button', 'Vendor Login', 40),
                    'secondary_link' => self::link('Secondary button link', '/vendor/login'),
                ],
            ],
            'cta' => [
                'label' => 'Closing call to action',
                'help' => 'Last block before the footer.',
                'movable' => true,
                'hideable' => true,
                'fields' => [
                    'image' => self::image('Image', self::BUNDLED_PREFIX.'images/storefront/ghana-street.jpg'),
                    'image_alt' => self::text('Image description (alt text)', 'A busy street market in Ghana', 160),
                    'title' => self::text('Title', 'Ready to Get Started?', 120),
                    'description' => self::textarea('Description', 'Find the service you need from trusted vendors on XTRA4U. Quick, secure, and delivered within minutes.', 240),
                    'primary_text' => self::text('Primary button', 'Start Shopping', 40),
                    'primary_link' => self::link('Primary button link', '{shop}'),
                    'secondary_text' => self::text('Secondary button', 'Become a Vendor', 40),
                    'secondary_link' => self::link('Secondary button link', '/vendor/request'),
                    'rating_text' => self::text('Text beside the stars', 'Trusted by thousands across Ghana', 80),
                    'image_caption' => self::text('Caption on the image', 'Proudly serving customers across Ghana', 80),
                ],
            ],
        ];
    }

    private static function aboutSections(): array
    {
        return [
            'hero' => [
                'label' => 'Hero',
                'help' => 'Top of the About page.',
                'movable' => false,
                'hideable' => false,
                'fields' => [
                    'eyebrow' => self::text('Small label', 'About Us', 40),
                    'title' => self::text('Title', 'Your trusted partner for [[digital services]] in Ghana', 140),
                    'description' => self::textarea('Description', 'Welcome to XTRA4U — your reliable digital platform for fast and affordable online services.', 240),
                ],
            ],
            'mission' => [
                'label' => 'What we do',
                'help' => 'Three cards and the value-proposition banner underneath.',
                'movable' => false,
                'hideable' => true,
                'fields' => [
                    'cards' => self::repeater('Cards', [
                        'icon' => self::icon('Icon', 'zap'),
                        'title' => self::text('Title', '', 60),
                        'body' => self::textarea('Text', '', 400),
                    ], [
                        ['icon' => 'zap', 'title' => 'What We Do', 'body' => 'At XTRA4U, we provide a wide range of services including data bundles for all networks, airtime recharge, results checker services, and many more digital solutions designed to make life easier for individuals and businesses.'],
                        ['icon' => 'users', 'title' => 'Empowering Entrepreneurs', 'body' => 'We also empower entrepreneurs by registering resellers and vendors, giving them the opportunity to earn by offering our services to their customers at competitive prices.'],
                        ['icon' => 'target', 'title' => 'Our Mission', 'body' => 'Our mission is to deliver speed, reliability, and convenience while maintaining excellent customer support and secure transactions.'],
                    ], 0, 6),
                    'value_text' => self::textarea('Banner text', 'With XTRA4U, you get more value, more convenience, and more opportunities — all in one place.', 240),
                    'tagline' => self::text('Banner tagline', 'Where trust meets value', 60),
                ],
            ],
            'values' => [
                'label' => 'Core values',
                'help' => 'Four value tiles.',
                'movable' => false,
                'hideable' => true,
                'fields' => [
                    'eyebrow' => self::text('Small label', 'Our Core Values', 40),
                    'title' => self::text('Title', 'What drives us every day', 120),
                    'items' => self::repeater('Values', [
                        'icon' => self::icon('Icon', 'zap'),
                        'title' => self::text('Title', '', 40),
                        'body' => self::textarea('Text', '', 160),
                    ], [
                        ['icon' => 'zap', 'title' => 'Speed', 'body' => 'Fast and instant service delivery to save your time.'],
                        ['icon' => 'shield', 'title' => 'Trust', 'body' => 'Secure transactions and verified vendors you can rely on.'],
                        ['icon' => 'target', 'title' => 'Value', 'body' => 'Affordable prices with maximum benefits for you.'],
                        ['icon' => 'users', 'title' => 'Opportunity', 'body' => 'Earn money as a vendor or reseller on our platform.'],
                    ], 0, 6),
                ],
            ],
            'cta' => [
                'label' => 'Closing call to action',
                'help' => 'Last block before the footer.',
                'movable' => false,
                'hideable' => true,
                'fields' => [
                    'title' => self::text('Title', 'Ready to Get Started?', 120),
                    'description' => self::textarea('Description', 'Join thousands of satisfied customers and vendors on our platform.', 240),
                    'primary_text' => self::text('Primary button', 'Start Shopping', 40),
                    'primary_link' => self::link('Primary button link', '/'),
                    'secondary_text' => self::text('Secondary button', 'Become a Vendor', 40),
                    'secondary_link' => self::link('Secondary button link', '/vendor/request'),
                ],
            ],
        ];
    }

    // --------------------------------------------------------------- settings

    /** Whitelisted global values. Nothing secret belongs here. */
    public static function settings(): array
    {
        $s = [
            'site.logo_media_id' => ['group' => 'Branding', 'label' => 'Platform logo', 'type' => 'image', 'max' => 20, 'default' => '', 'help' => 'Replaces the XTRA4U badge and wordmark in the website header, footer and homepage. A wide PNG or WebP with a transparent background works best (about 28 px tall is shown). Remove it to go back to the default logo.'],
            'contact.support_phone' => ['group' => 'Contact', 'label' => 'Support phone', 'type' => 'phone', 'max' => 30, 'default' => ''],
            'contact.support_email' => ['group' => 'Contact', 'label' => 'Support email', 'type' => 'email', 'max' => 120, 'default' => ''],
            'contact.whatsapp_channel_url' => ['group' => 'Contact', 'label' => 'WhatsApp channel link', 'type' => 'url', 'max' => 255, 'default' => 'https://whatsapp.com/channel/0029Vb6ZXJuL7UVQeZ7L5D3v', 'help' => 'Used by the floating WhatsApp button and the footer Support link.'],
            'contact.business_address' => ['group' => 'Contact', 'label' => 'Business address', 'type' => 'textarea', 'max' => 300, 'default' => ''],
            'contact.working_hours' => ['group' => 'Contact', 'label' => 'Working hours', 'type' => 'text', 'max' => 120, 'default' => ''],
            'footer.description' => ['group' => 'Footer', 'label' => 'Short company description', 'type' => 'textarea', 'max' => 240, 'default' => "Ghana's digital services marketplace. Where trust meets value."],
            'footer.copyright_name' => ['group' => 'Footer', 'label' => 'Copyright name', 'type' => 'text', 'max' => 80, 'default' => 'XTRA4U'],
            'footer.credit_name' => ['group' => 'Footer', 'label' => '"Developed by" name', 'type' => 'text', 'max' => 80, 'default' => 'Lanky iTech Ghana', 'help' => 'Leave empty to hide the "Developed by" line.'],
        ];

        foreach (self::SOCIAL as $key => $label) {
            $s["social.$key"] = ['group' => 'Social links', 'label' => $label, 'type' => 'url', 'max' => 255, 'default' => ''];
        }

        return $s;
    }

    public static function settingDefaults(): array
    {
        return array_map(fn ($def) => $def['default'], self::settings());
    }

    // ------------------------------------------------------------- navigation

    public static function navigationDefaults(): array
    {
        return [
            'header' => [
                ['Home', '/'],
                ['Results Checker', '/results-checkers'],
                ['Retrieve PIN', '/order-status'],
                ['About', '/about'],
            ],
            'footer_quick' => [
                ['Home', '/'],
                ['Services', '{shop}'],
                ['Results Checker', '/results-checkers'],
                ['Order Status', '/order-status'],
                ['About Us', '/about'],
            ],
            'footer_legal' => [
                ['Privacy Policy', '/privacy'],
                ['Terms of Service', '/terms'],
            ],
        ];
    }

    /** Hero slides that used to be hardcoded. [bundled path, title]. */
    public static function defaultBanners(): array
    {
        return [
            ['images/storefront/hero-team.jpg', 'The XTRA4U team delivering digital services in Ghana'],
            ['images/storefront/hero-services.jpg', 'XTRA4U services and the mobile money networks accepted at checkout'],
        ];
    }

    // ---------------------------------------------------------------- helpers

    private static function text(string $label, string $default = '', int $max = 160, ?string $hint = null): array
    {
        return ['type' => 'text', 'label' => $label, 'default' => $default, 'max' => $max, 'hint' => $hint];
    }

    private static function textarea(string $label, string $default = '', int $max = 400): array
    {
        return ['type' => 'textarea', 'label' => $label, 'default' => $default, 'max' => $max];
    }

    private static function link(string $label, string $default = ''): array
    {
        return ['type' => 'link', 'label' => $label, 'default' => $default, 'max' => 255];
    }

    private static function image(string $label, string $default = ''): array
    {
        return ['type' => 'image', 'label' => $label, 'default' => $default];
    }

    private static function icon(string $label, string $default): array
    {
        return ['type' => 'select', 'label' => $label, 'default' => $default, 'options' => self::ICONS];
    }

    private static function repeater(string $label, array $fields, array $default, int $min, int $max): array
    {
        return ['type' => 'repeater', 'label' => $label, 'fields' => $fields, 'default' => $default, 'min' => $min, 'max' => $max];
    }

    private static function statFields(): array
    {
        return [
            'value' => ['type' => 'number', 'label' => 'Number', 'default' => '0'],
            'decimals' => ['type' => 'select', 'label' => 'Decimals', 'default' => '0', 'options' => ['0', '1', '2']],
            'prefix' => self::text('Before', '', 8),
            'suffix' => self::text('After', '', 8),
            'label' => self::text('Label', '', 40),
        ];
    }
}
