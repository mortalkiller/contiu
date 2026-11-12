{{--
    Sign-up tax profile (RegisterAccount, TaxProfileDeduction): who, the year in one sentence, the
    three values with their reason, and the dates that matter. "Alterar" goes back to the step that
    answers it; "Já confirmei" confirms the Social Security exemption.
--}}
@php
    use App\Support\TaxProfileDeduction;
    use Illuminate\Support\HtmlString;
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<div class="contiu-signup-profile">
    <div class="contiu-signup-id">
        <div class="contiu-signup-avatar" aria-hidden="true">{{ $initials }}</div>
        <div>
            <p class="contiu-signup-eyebrow">{{ $eyebrow }}</p>
            <h1 class="contiu-signup-title">{{ $name }}</h1>
            @if (filled($subtitle))
                <p class="contiu-signup-lede">{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    <div class="contiu-signup-chips">
        @foreach ($chips as $chip)
            <x-filament::badge color="gray">{{ $chip }}</x-filament::badge>
        @endforeach
    </div>

    <p class="contiu-signup-about">{{ $summary }}</p>

    @if ($regimeNote)
        <x-filament::callout color="warning" :icon="Phosphor::WarningCircle" :description="$regimeNote" />
    @endif

    <div class="contiu-signup-results">
        @foreach ($cards as $card)
            <section class="contiu-signup-result" @if ($card['key'] === 'social_security') data-wide="true" @endif>
                <div class="contiu-signup-result-head">
                    <span class="contiu-signup-result-kicker">{{ $card['title'] }}</span>
                    <span class="contiu-signup-result-actions">
                        @if ($card['key'] === 'social_security' && $canConfirmExemption)
                            <x-filament::badge color="warning" x-show="! $wire.data?.exemption_confirmed">{{ __('Probable · confirm') }}</x-filament::badge>
                            <x-filament::badge color="success" x-show="$wire.data?.exemption_confirmed" x-cloak>{{ __('Confirmed') }}</x-filament::badge>
                        @elseif ($card['status'] === TaxProfileDeduction::PROBABLE)
                            <x-filament::badge color="warning">{{ __('Probable · confirm') }}</x-filament::badge>
                        @else
                            <x-filament::badge color="primary">{{ __('Deduced') }}</x-filament::badge>
                        @endif

                        @isset ($stepIndexes[$card['step']])
                            <x-filament::link tag="button" type="button" size="sm" color="gray" x-on:click="goToStep(getSteps()[{{ (int) $stepIndexes[$card['step']] }}])">
                                {{ __('Change answer') }}
                            </x-filament::link>
                        @endisset
                    </span>
                </div>
                <p class="contiu-signup-result-value">{{ $card['value'] }}</p>
                <p class="contiu-signup-result-why">{{ $card['reason'] }}</p>

                @if ($card['confirm'])
                    <x-filament::callout
                        color="warning"
                        :icon="Phosphor::WarningCircle"
                        :description="new HtmlString('<b>'.e(__('To confirm:')).'</b> '.e($card['confirm']))"
                    >
                        @if ($card['key'] === 'social_security' && $canConfirmExemption)
                            <x-slot name="controls">
                                <x-filament::button color="gray" size="sm" type="button" x-show="! $wire.data?.exemption_confirmed" x-on:click="$wire.$set('data.exemption_confirmed', true, false)">
                                    {{ __('I have confirmed') }}
                                </x-filament::button>
                                <x-filament::badge color="success" x-show="$wire.data?.exemption_confirmed" x-cloak>
                                    {{ __('Confirmed') }}
                                </x-filament::badge>
                            </x-slot>
                        @endif
                    </x-filament::callout>
                @endif
            </section>
        @endforeach
    </div>

    <div class="contiu-signup-block">
        <p class="contiu-signup-question">{{ __('Your dates') }}</p>
        <div class="contiu-signup-dates">
            @foreach ($dates as $date)
                <div><span>{{ $date['when'] }}</span><span>{{ $date['what'] }}</span></div>
            @endforeach
        </div>
    </div>

    <p class="contiu-signup-hint">{{ __('It is an estimate made from your answers, to help you plan: it does not replace a certified accountant.') }}</p>
</div>
