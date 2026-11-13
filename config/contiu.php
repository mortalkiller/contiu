<?php

return [

    'demo_admin_password' => env('CONTIU_DEMO_ADMIN_PASSWORD'),

    // Public sign-up (/register and the login page's link). Off until going live is ready.
    'registration_open' => (bool) env('CONTIU_REGISTRATION_OPEN', false),

    /*
     * Where the panel lives. Without a domain it sits at /app on the site's own domain (locally,
     * http://localhost:8000/app); with CONTIU_PANEL_DOMAIN (production: the "app" subdomain) it
     * takes the root of that domain, and the public site stays on the APP_URL host.
     */
    'panel_domain' => env('CONTIU_PANEL_DOMAIN'),

    'invoice_email_inbox_enabled' => (bool) env('CONTIU_INVOICE_EMAIL_INBOX_ENABLED', false),

    // The help email: the site's help page and "Precisas de ajuda? Fala connosco" (e.g. on "Conta
    // criada"), which is hidden when empty.
    'support_email' => env('CONTIU_SUPPORT_EMAIL', 'ajuda@contiu.pt'),

    // Days a new account can use everything before it has to subscribe.
    'trial_days' => 14,

    /*
     * The plan and card as the last sign-up step (Stripe Payment Element, the card mandatory to
     * start the trial). Off for the beta, which takes no payments: the step does not exist and the
     * trial starts at sign-up without a card. Needs STRIPE_KEY, STRIPE_SECRET and the prices.
     */
    'signup_payment' => (bool) env('CONTIU_SIGNUP_PAYMENT', false),

    /*
     * Contiu is software of TrekDev Lda, which charges VAT on top of the subscription prices.
     * subscription_vat_rate is shown on the screens; stripe_tax_rate is the id of the matching
     * Stripe tax rate (exclusive, "IVA 23%"), added to every new subscription and Checkout. Without
     * it Stripe charges no VAT, so it must be set wherever payments are taken.
     */
    'subscription_vat_rate' => 0.23,
    'stripe_tax_rate' => env('STRIPE_TAX_RATE'),

    /*
     * The company behind Contiu, named on the terms and privacy pages as the provider of the service
     * and the data controller. The environment can override the company number, registered office
     * and email; a detail left empty there is left out of the pages.
     */
    'company' => [
        'name' => 'TrekDev - Tecnologias de Informação, Lda',
        'short_name' => 'TrekDev',
        'website' => 'https://trekdev.com',
        'vat_number' => env('CONTIU_COMPANY_VAT_NUMBER', '515873624'),
        'address' => env('CONTIU_COMPANY_ADDRESS', 'Rua Manuel Teixeira Gomes, n.º 11, 5.º Esq.º, 2790-104 Carnaxide, Oeiras'),
        'email' => env('CONTIU_COMPANY_EMAIL', 'info@trekdev.com'),
    ],

    // Version of the terms and privacy policy accepted at sign-up (stored with the account).
    'terms_version' => '2026-11-13',

    // Stripe prices (monthly 5,69 €, yearly 61,46 €), without VAT: VAT is added on top (above), for
    // 7,00 € a month or 75,60 € a year.
    'prices' => [
        'monthly' => env('STRIPE_PRICE_MONTHLY'),
        'yearly' => env('STRIPE_PRICE_YEARLY'),
    ],

    /*
     * The certified-accountant service (App\Support\AccountantService), monthly: 30 € with VAT in
     * the simplified regime, 50 € with organised accounting. The Stripe prices are without VAT
     * (24,39 € and 40,65 €), the stripe_tax_rate adding it as for the plans.
     */
    'accountant_prices' => [
        'simplified' => env('STRIPE_PRICE_ACCOUNTANT_SIMPLIFIED'),
        'organised' => env('STRIPE_PRICE_ACCOUNTANT_ORGANISED'),
    ],

    /*
     * Fiscal values that change every year, keyed by the year they apply to. Read them through
     * App\Support\YearlyParameter. Sources: docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md
     * (rule IDs in brackets). Amounts in cents.
     */

    // Indexante dos Apoios Sociais (SS080).
    'ias' => [2023 => 48043, 2024 => 50926, 2025 => 52250, 2026 => 53713],

    // Art. 53.º CIVA exemption limit (IVA07) and, from 2025, the immediate-exit limit (IVA10).
    'vat_exemption_limit' => [2023 => 1350000, 2024 => 1450000, 2025 => 1500000, 2026 => 1500000],
    'vat_exemption_tolerance' => [2025 => 1875000, 2026 => 1875000],

    // Art. 25.º, n.º 1, a), CIRS: the automatic part of the 15 % expenses check (DES003, HIST001).
    'irs_specific_deduction' => [2023 => 410400, 2024 => 435024, 2025 => 446215, 2026 => 458709],

    // Art. 102.º CIRS: share of the computed amount paid on account (PPC002).
    'irs_prepayment_factor' => [2023 => 0.765, 2024 => 0.765, 2025 => 0.65, 2026 => 0.65],

    /*
     * Art. 68.º CIRS, by territory of fiscal residence and then by year: upper limit of each
     * bracket in cents, then the marginal and the average rate in thousandths of a percent, as
     * published (never rebuilt from another table). The last bracket has no limit nor average rate.
     * 'PT': the national table, 2026 after DL 212/2026 (HIST002, HIST003). 'PT-MA': Madeira,
     * DLR 17/2026/M, art. 2.º, effects from 1 January 2026 (RA002, RAM04). The Azores (RA001) have
     * no table here: their reduction is confirmed but the published regional table is not loaded.
     */
    'irs_brackets' => [
        'PT' => [
            2026 => [
                [834200, 12200, 12200],
                [1258700, 15200, 13212],
                [1783800, 20700, 15416],
                [2308900, 23600, 17277],
                [2939700, 30600, 20136],
                [4309000, 34600, 24732],
                [4656600, 43100, 26103],
                [8663400, 44600, 34658],
                [null, 48000, null],
            ],
        ],
        'PT-MA' => [
            2026 => [
                [834200, 8540, 8540],
                [1258700, 10640, 9248],
                [1783800, 14490, 10791],
                [2308900, 16520, 12094],
                [2939700, 21420, 14095],
                [4309000, 24220, 17313],
                [4656600, 30170, 18272],
                [8663400, 31220, 24261],
                [null, 33600, null],
            ],
        ],
    ],

    /*
     * Account slugs are the first URL segment after the panel's prefix (/app/{slug}/…), so no account may take a name the
     * app's own routes use. CreateAccount also checks the router itself; a test fails when a
     * route outside the accounts is missing here, so the list stays the source of truth.
     */
    'reserved_slugs' => [
        'login', 'logout', 'register', 'password-reset', 'email-verification', 'profile',
        'termos', 'privacidade', 'idioma', 'stripe', 'livewire', 'up', 'storage', 'build', 'brand',
        'admin', 'api', 'filament', 'filament-excel', 'filament-fullcalendar', 'dev',
        // The panel's prefix and the public site's pages (routes/web.php).
        'app', 'funcionalidades', 'precos', 'contabilista', 'blogue', 'ajuda', 'ferramentas', 'sitemap.xml', 'robots.txt',
    ],

];
