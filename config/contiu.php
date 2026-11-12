<?php

return [

    // Public sign-up (/register and the login page's link). Off until going live is ready.
    'registration_open' => (bool) env('CONTIU_REGISTRATION_OPEN', false),

    'invoice_email_inbox_enabled' => (bool) env('CONTIU_INVOICE_EMAIL_INBOX_ENABLED', false),

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

    // Version of the terms and privacy policy accepted at sign-up (stored with the account).
    'terms_version' => '2026-11-08',

    // Stripe prices (monthly 5 €, yearly 54 €), without VAT: VAT is added on top (above).
    'prices' => [
        'monthly' => env('STRIPE_PRICE_MONTHLY'),
        'yearly' => env('STRIPE_PRICE_YEARLY'),
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
     * Art. 68.º CIRS, mainland: upper limit of each bracket in cents, then the marginal and the
     * average rate in thousandths of a percent (2026 after DL 212/2026). The last bracket has no
     * limit nor average rate.
     */
    'irs_brackets' => [
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

    /*
     * Account slugs are the first URL segment (/{slug}/…), so no account may take a name the
     * app's own routes use. CreateAccount also checks the router itself; a test fails when a
     * route outside the accounts is missing here, so the list stays the source of truth.
     */
    'reserved_slugs' => [
        'login', 'logout', 'register', 'password-reset', 'email-verification', 'profile',
        'termos', 'privacidade', 'stripe', 'livewire', 'up', 'storage', 'build', 'brand',
        'admin', 'api', 'filament', 'filament-excel', 'filament-fullcalendar', 'dev',
    ],

];
