{{--
    Sign-up "Contabilista" step (RegisterAccount, AccountantService): what Contiu's certified
    accountant includes, how it works, and who provides it. Organised accounting adds the accounts
    and the IES; an account on hold adds the help to open the activity.
--}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<div class="contiu-signup-stack">
    <div class="contiu-signup-block">
        <p class="contiu-signup-question">{{ $includesTitle }}</p>
        <ul class="contiu-signup-list">
            @foreach ($includes as $item)
                <li>
                    <x-filament::icon :icon="Phosphor::Check" class="contiu-signup-list-check" />
                    {{ $item }}
                </li>
            @endforeach
        </ul>
    </div>

    <div class="contiu-signup-block">
        <p class="contiu-signup-question">{{ __('How it works') }}</p>
        <ol class="contiu-signup-howto">
            @foreach ($steps as [$title, $text])
                <li><b>{{ $title }}</b>{{ $text }}</li>
            @endforeach
        </ol>
    </div>

    @if ($notice)
        <x-filament::callout :icon="Phosphor::Info" :description="$notice" />
    @endif

    <p class="contiu-signup-hint">{{ __('The service is provided by a certified accountant from a Contiu partner, registered with the Ordem dos Contabilistas Certificados.') }}</p>
</div>
