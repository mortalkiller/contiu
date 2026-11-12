{{--
    Layout of the sign-in pages (Login, RequestPasswordReset, ResetPassword, EmailVerificationPrompt),
    as in the "Autenticação" mocks: the logo, the page and the legal links on the left; Contiu's
    showcase, in the palette's navigation colour, on the right. On a phone only the left half shows.
--}}
@php
    use App\Enums\Language;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    $currentLanguage = Language::resolve(app()->getLocale());
@endphp

<x-filament-panels::layout.base :livewire="$livewire ?? null">
    <div class="contiu-auth">
        <div class="contiu-auth-form">
            <a href="{{ filament()->getLoginUrl() ?? url('/') }}" class="contiu-auth-logo">
                <img src="{{ asset('brand/contiu/contiu-logo-black.svg') }}" alt="Contiu" class="contiu-auth-logo-light">
                <img src="{{ asset('brand/contiu/contiu-logo-white.svg') }}" alt="Contiu" class="contiu-auth-logo-dark">
            </a>

            <main id="fi-main-content" class="contiu-auth-main">
                {{ $slot }}
            </main>

            <footer class="contiu-auth-foot">
                <span>© {{ now()->year }} TrekDev, Lda.</span>
                <a href="{{ route('legal.terms') }}">{{ __('Terms') }}</a>
                <a href="{{ route('legal.privacy') }}">{{ __('Privacy') }}</a>

                <x-filament::dropdown placement="top-end" class="contiu-auth-language">
                    <x-slot name="trigger">
                        <button type="button" class="contiu-auth-language-button" aria-label="{{ __('Language') }}">
                            <x-filament::icon :icon="Phosphor::Globe" />{{ Language::options()[$currentLanguage->value] }}
                        </button>
                    </x-slot>

                    <x-filament::dropdown.list>
                        @foreach (Language::cases() as $language)
                            <x-filament::dropdown.list.item tag="a" :href="route('guest-language', $language)" :color="$language === $currentLanguage ? 'primary' : 'gray'">
                                {{ Language::options()[$language->value] }}
                            </x-filament::dropdown.list.item>
                        @endforeach
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </footer>
        </div>

        <aside class="contiu-auth-show" aria-label="Contiu">
            <div>
                <p class="contiu-auth-kicker">{{ __('Contiu for freelancers') }}</p>
                <p class="contiu-auth-show-title">{{ __('Invoices, expenses and taxes, with no surprises at the end of the month.') }}</p>
            </div>

            <img class="contiu-auth-illustration" src="{{ asset('brand/ilustracoes/contabilista.webp') }}" alt="">

            <ul class="contiu-auth-points">
                @foreach ([
                    __('You know how much to set aside, every month'),
                    __('The VAT, Social Security and IRS deadlines in your calendar'),
                    __('A certified accountant, if you want one'),
                ] as $point)
                    <li><x-filament::icon :icon="Phosphor::Check" />{{ $point }}</li>
                @endforeach
            </ul>
        </aside>
    </div>
</x-filament-panels::layout.base>
