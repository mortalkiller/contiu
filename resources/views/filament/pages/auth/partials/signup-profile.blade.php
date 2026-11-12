{{--
    Sign-up tax profile (RegisterAccount, OpenActivity; data from SignupQuestions::profileViewData):
    a tax card with who and the key codes, the year in one sentence, one seal per tax with its
    reason, and the dates that matter as a timeline. "Alterar" goes back to the step that answers
    it; "Já confirmei" confirms the Social Security exemption.
--}}
@php
    use App\Support\TaxProfileDeduction;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    /*
     * Who each seal answers to, where the user confirms it: Segurança Social Direta, or the Portal
     * das Finanças (Autoridade Tributária) for IRS and VAT. The institutions' own logos, from their
     * websites, in public/brand/entidades.
     */
    $sealEntities = [
        'social_security' => [asset('brand/entidades/seguranca-social-direta.svg'), __('Social Security Direct')],
        'irs' => [asset('brand/entidades/autoridade-tributaria.png'), __('Tax Authority portal')],
        'vat' => [asset('brand/entidades/autoridade-tributaria.png'), __('Tax Authority portal')],
    ];
@endphp

<div class="contiu-signup-profile">
    <div>
        <p class="contiu-signup-eyebrow">{{ $eyebrow }}</p>
        <h1 class="contiu-signup-title">{{ __('Your tax card') }}</h1>
    </div>

    <div class="contiu-signup-card-row">
        <div class="contiu-signup-card" role="img" aria-label="{{ __('Tax card of :name', ['name' => $name]) }}">
            <div class="contiu-signup-card-top">
                <img src="{{ $logo }}" alt="">
                <span>{{ $regime }}</span>
            </div>
            <div class="contiu-signup-card-who">
                <span class="contiu-signup-avatar" aria-hidden="true">{{ $initials }}</span>
                <div>
                    <p class="contiu-signup-card-name">{{ $name }}</p>
                    @if (filled($role))
                        <p class="contiu-signup-card-role">{{ $role }}</p>
                    @endif
                </div>
            </div>
            @if ($facts !== [])
                <dl class="contiu-signup-card-facts">
                    @foreach ($facts as $fact)
                        <div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>

        <div class="contiu-signup-card-intro">
            <p class="contiu-signup-about">{{ $summary }}</p>
            <p class="contiu-signup-lede">{{ __('It comes from your answers. Each seal says why.') }}</p>
            @isset ($stepIndexes['activity'])
                <x-filament::button color="gray" size="sm" type="button" :icon="Phosphor::PencilSimple" x-on:click="goToStep(getSteps()[{{ (int) $stepIndexes['activity'] }}])">
                    {{ __('Review the answers') }}
                </x-filament::button>
            @endisset
        </div>
    </div>

    @if ($regimeNote)
        <x-filament::callout color="warning" :icon="Phosphor::WarningCircle" :description="$regimeNote" />
    @endif

    <div class="contiu-signup-seals">
        @foreach ($cards as $card)
            @php
                $confirmsHere = $card['key'] === 'social_security' && $canConfirmExemption;
                $probable = $card['status'] === TaxProfileDeduction::PROBABLE;
            @endphp
            {{--
                Keyed by what it shows, so a new answer replaces the seal instead of patching it. The
                Social Security seal's data-tone follows "Já confirmei"; the CSS shows the parts
                marked data-when for that tone.
            --}}
            <section
                wire:key="seal-{{ $card['key'] }}-{{ $confirmsHere ? 'confirm' : $card['status'] }}"
                class="contiu-signup-seal"
                @if ($confirmsHere)
                    x-bind:data-tone="$wire.data?.exemption_confirmed ? 'confirmed' : 'todo'"
                @elseif ($probable)
                    data-tone="todo"
                @endif
            >
                @isset ($sealEntities[$card['key']])
                    @php([$entityLogo, $entityName] = $sealEntities[$card['key']])
                    <span class="contiu-signup-seal-logo">
                        <img src="{{ $entityLogo }}" alt="{{ $entityName }}">
                    </span>
                @endisset
                <span class="contiu-signup-result-kicker">{{ $card['title'] }}</span>
                <p class="contiu-signup-result-value">{{ $card['value'] }}</p>
                <p class="contiu-signup-result-why">{{ $card['reason'] }}</p>

                @if ($card['confirm'])
                    <div class="contiu-signup-seal-confirm" @if ($confirmsHere) data-when="todo" @endif>
                        <p><b>{{ __('To confirm:') }}</b> {{ $card['confirm'] }}</p>
                        @if ($confirmsHere)
                            <x-filament::button color="gray" size="sm" type="button" x-on:click="$wire.$set('data.exemption_confirmed', true, false)">
                                {{ __('I have confirmed') }}
                            </x-filament::button>
                        @endif
                    </div>
                @endif

                <div class="contiu-signup-seal-foot">
                    @if ($confirmsHere)
                        <x-filament::badge color="warning" data-when="todo">{{ __('Probable · confirm') }}</x-filament::badge>
                        <x-filament::badge color="success" data-when="confirmed">{{ __('Confirmed') }}</x-filament::badge>
                    @elseif ($probable)
                        <x-filament::badge color="warning">{{ __('Probable · confirm') }}</x-filament::badge>
                    @else
                        <x-filament::badge color="primary">{{ __('Deduced') }}</x-filament::badge>
                    @endif

                    @isset ($stepIndexes[$card['step']])
                        <x-filament::link tag="button" type="button" size="sm" color="gray" x-on:click="goToStep(getSteps()[{{ (int) $stepIndexes[$card['step']] }}])">
                            {{ __('Change answer') }}
                        </x-filament::link>
                    @endisset
                </div>
            </section>
        @endforeach
    </div>

    <div class="contiu-signup-block">
        <p class="contiu-signup-question">{{ __('Your dates') }}</p>
        <ol class="contiu-signup-timeline">
            @foreach ($dates as $date)
                <li><b>{{ $date['when'] }}</b><span>{{ $date['what'] }}</span></li>
            @endforeach
        </ol>
    </div>

    <p class="contiu-signup-hint">{{ __('It is an estimate made from your answers, to help you plan: it does not replace a certified accountant.') }}</p>
</div>
