{{--
    "Conta criada" (EmailVerificationPrompt right after the sign-up; data from RegisterAccount),
    as in the RegConcluido mocks: the sign-up side panel with every step done; the tax card, the
    email to confirm, what is agreed and the first steps.
--}}
@php
    use Illuminate\Support\HtmlString;
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<div class="contiu-signup contiu-signup-done">
    @include('filament.pages.auth.partials.signup-side', $accountCreated['side'])

    <main id="fi-main-content" class="contiu-signup-main">
        <div class="contiu-signup-done-top">
            <div class="contiu-signup-done-card">
                @include('filament.pages.auth.partials.signup-tax-card', $accountCreated['card'])

                <span class="contiu-signup-done-stamp"><x-filament::icon :icon="Phosphor::Check" />{{ __('Account created') }}</span>
            </div>

            <div>
                <p class="contiu-signup-eyebrow">
                    {{ $accountCreated['waiting'] ? __('Account created · waiting for the activity') : __('Account created') }}
                </p>
                <h1 class="contiu-signup-title">
                    {{ filled($accountCreated['firstName']) ? __('Your account is ready, :name', ['name' => $accountCreated['firstName']]) : __('Your account is ready') }}
                </h1>
                <p class="contiu-signup-lede">{{ $accountCreated['lede'] }}</p>
            </div>
        </div>

        <section class="contiu-signup-done-mail">
            <span class="contiu-signup-done-mark"><x-filament::icon :icon="Phosphor::EnvelopeSimple" /></span>
            <div>
                <h2>{{ __('Confirm your email to sign in') }}</h2>
                <p>{{ new HtmlString(__('We sent a link to :email. Open it on this device or another: the account is active as soon as you confirm.', ['email' => '<b>'.e($accountCreated['email']).'</b>'])) }}</p>
                <div class="contiu-signup-done-mail-actions">
                    {{ $this->resendNotificationAction }}
                    <span>{{ __('Did not arrive? Look in spam or promotions.') }} {{ __('Typed the email wrong?') }} {{ $this->changeEmailAction }}</span>
                </div>
            </div>
        </section>

        @if ($accountCreated['waiting'])
            @include('filament.pages.auth.partials.account-created-next', ['next' => $accountCreated['next']])
        @endif

        <section class="contiu-signup-done-block">
            <h2 class="contiu-signup-question">{{ __('What is agreed') }}</h2>
            <dl class="contiu-signup-done-deal">
                @foreach ($accountCreated['deal'] as $row)
                    <div>
                        <dt>{{ $row['label'] }}</dt>
                        <dd>{{ $row['value'] }}<small>{{ $row['note'] }}</small></dd>
                    </div>
                @endforeach
            </dl>
        </section>

        @unless ($accountCreated['waiting'])
            @include('filament.pages.auth.partials.account-created-next', ['next' => $accountCreated['next']])
        @endunless

        <div class="contiu-signup-foot">
            @if (filled(config('contiu.support_email')))
                <span class="contiu-signup-login">
                    {{ __('Need help?') }}
                    <x-filament::link :href="'mailto:'.config('contiu.support_email')">{{ __('Talk to us') }}</x-filament::link>
                </span>
            @endif

            <x-filament::button size="lg" tag="a" :href="filament()->getUrl()" :icon="Phosphor::SignIn" class="contiu-signup-next">
                {{ __('I have confirmed, sign in to Contiu') }}
            </x-filament::button>
        </div>
    </main>

    <x-filament-actions::modals />
</div>
