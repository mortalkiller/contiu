<?php

return [

    // Public sign-up (/register and the login page's link). Off until going live is ready.
    'registration_open' => (bool) env('CONTIU_REGISTRATION_OPEN', false),

    'invoice_email_inbox_enabled' => (bool) env('CONTIU_INVOICE_EMAIL_INBOX_ENABLED', false),

    // Days a new account can use everything before it has to subscribe.
    'trial_days' => 14,

    // Version of the terms and privacy policy accepted at sign-up (stored with the account).
    'terms_version' => '2026-11-08',

    // Stripe prices (monthly 5 €, yearly 54 €; no VAT).
    'prices' => [
        'monthly' => env('STRIPE_PRICE_MONTHLY'),
        'yearly' => env('STRIPE_PRICE_YEARLY'),
    ],

    // Indexante dos Apoios Sociais by year, in cents.
    'ias' => [2025 => 52250, 2026 => 53713],

    /*
     * Account slugs are the first URL segment (/{slug}/…), so no account may take a name the
     * app's own routes use. CreateAccount also checks the router itself; a test fails when a
     * route outside the accounts is missing here, so the list stays the source of truth.
     */
    'reserved_slugs' => [
        'login', 'logout', 'register', 'password-reset', 'email-verification', 'profile',
        'termos', 'privacidade', 'stripe', 'livewire', 'up', 'storage', 'build', 'brand',
        'admin', 'api', 'filament', 'filament-excel', 'filament-fullcalendar',
    ],

];
