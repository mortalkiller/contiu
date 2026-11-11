<x-filament-panels::page>
    @php
        $invoicingLogos = ['invoicexpress' => ['invoicexpress.png', 150, 22]];
        $invoicing = [];

        foreach ($invoicingProviders as $key => $provider) {
            $invoicing[$provider['label']] = [
                ...(isset($invoicingLogos[$key]) ? ['logo' => $invoicingLogos[$key]] : []),
                'settingsUrl' => $provider['url'],
                'configured' => $provider['connected'],
                'status' => $provider['connected'] ? 'Connected' : 'Not connected',
                'description' => 'Creates drafts, issues documents and keeps everything in sync.',
                'sync' => $provider['connected'] ? $provider : null,
            ];
        }

        $notes = ['Invoicing' => 'Only one invoicing provider can be connected at a time.'];

        $categories = [
            'Invoicing' => [
                ...$invoicing,
                'Moloni' => [
                    'logo' => ['moloni.svg', 129, 22],
                    'description' => 'Send drafts and link imported invoices to receivables.',
                ],
                'Moloni ON' => [
                    'logo' => ['moloni-on.svg', 142, 22],
                    'background' => '#080a14',
                    'description' => 'Send drafts and link imported invoices to receivables.',
                ],
            ],
            'Banks' => [
                'TrueLayer' => [
                    'icon' => 'truelayer.svg',
                    'description' => 'Import bank transactions and suggest matches.',
                ],
            ],
            'Expenses' => [
                'Invoices by email' => [
                    'symbol' => 'heroicon-o-envelope',
                    'description' => $invoiceEmailInboxUrl === null
                        ? 'Forward PDFs and review expenses before saving.'
                        : 'Review simulated invoice PDFs before saving.',
                    'settingsUrl' => $invoiceEmailInboxUrl,
                    'status' => 'Simulated email',
                    'action' => 'Open inbox',
                ],
            ],
            'Incoming payments' => [
                'Stripe' => [
                    'logo' => ['stripe.svg', 58, 24],
                    'description' => 'Match bank deposits with payments and fees.',
                ],
                'ifthenpay' => [
                    'logo' => ['ifthenpay.svg', 94, 24],
                    'description' => 'Match Multibanco and MB WAY payments to receivables.',
                ],
                'Eupago' => [
                    'logo' => ['eupago.webp', 105, 24],
                    'description' => 'Match Multibanco and MB WAY payments to receivables.',
                ],
            ],
            'Calendar' => [
                'Google Calendar' => [
                    'icon' => 'google-calendar.png',
                    'description' => 'See tax deadlines and due dates in your own calendar.',
                    'settingsUrl' => $calendarSubscriptionUrl,
                    'configured' => $calendarSubscriptionConfigured,
                ],
                'Apple Calendar' => [
                    'icon' => 'apple-calendar.png',
                    'description' => 'See tax deadlines and due dates in your own calendar.',
                    'settingsUrl' => $calendarSubscriptionUrl,
                    'configured' => $calendarSubscriptionConfigured,
                ],
            ],
            'Taxes' => [
                'e-Fatura' => [
                    'icon' => 'e-fatura.webp',
                    'description' => 'Find tax authority invoices missing from your expenses.',
                ],
            ],
            'Projects' => [
                'Clockify' => [
                    'icon' => 'clockify.svg',
                    'description' => 'Use tracked project hours to prepare charges.',
                ],
            ],
        ];
    @endphp

    @foreach ($categories as $category => $integrations)
        @php($headingId = str($category)->slug() . '-integrations-heading')

        <section aria-labelledby="{{ $headingId }}" class="space-y-4">
            <div class="space-y-1">
                <h2 id="{{ $headingId }}" class="text-xl font-semibold">
                    {{ __($category) }}
                </h2>

                @isset($notes[$category])
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __($notes[$category]) }}</p>
                @endisset
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($integrations as $name => $integration)
                    <x-filament::section>
                        <div class="flex flex-col items-start gap-4">
                            @if (isset($integration['logo']))
                                @php([$file, $width, $height] = $integration['logo'])

                                <h3
                                    class="rounded-md bg-white px-3 py-2"
                                    @if (isset($integration['background'])) style="background-color: {{ $integration['background'] }};" @endif
                                >
                                    <img src="{{ asset('brand/' . $file) }}" alt="{{ __($name) }}" width="{{ $width }}" height="{{ $height }}">
                                </h3>
                            @else
                                <h3 class="flex items-center gap-3 font-semibold">
                                    @if (isset($integration['icon']))
                                        <span class="shrink-0 rounded-md bg-white p-2">
                                            <img src="{{ asset('brand/' . $integration['icon']) }}" alt="" width="24" height="24">
                                        </span>
                                    @elseif (isset($integration['symbol']))
                                        <x-filament::icon :icon="$integration['symbol']" class="h-6 w-6 shrink-0" />
                                    @endif

                                    {{ __($name) }}
                                </h3>
                            @endif

                            <p class="hidden text-sm text-gray-600 md:line-clamp-2 dark:text-gray-400">
                                {{ __($integration['description']) }}
                            </p>

                            @if (isset($integration['sync']))
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    {{ $integration['sync']['syncedAt'] === null ? __('Not synchronised yet') : __('Synchronised :when', ['when' => $integration['sync']['syncedAt']]) }}

                                    @if ($integration['sync']['differences'] !== null)
                                        ·
                                        <x-filament::link :href="$integration['sync']['syncUrl']" color="warning">{{ $integration['sync']['differences'] }}</x-filament::link>
                                    @endif
                                </p>
                            @endif

                            @if (isset($integration['settingsUrl']))
                                @php($configured = $integration['configured'] ?? false)

                                <div class="flex w-full flex-wrap items-center justify-between gap-3">
                                    <x-filament::badge :color="$configured ? 'success' : 'gray'">
                                        {{ __($integration['status'] ?? ($configured ? 'Configured' : 'Not configured')) }}
                                    </x-filament::badge>

                                    <x-filament::button tag="a" :href="$integration['settingsUrl']">
                                        {{ __($integration['action'] ?? ($configured ? 'Manage' : 'Configure')) }}
                                    </x-filament::button>
                                </div>
                            @else
                                <x-filament::badge color="gray">
                                    {{ __('Coming soon') }}
                                </x-filament::badge>
                            @endif
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        </section>
    @endforeach
</x-filament-panels::page>
