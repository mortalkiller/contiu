{{-- Sign-up side panel: the steps of the path, then what Contiu has worked out (or the plan). --}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<aside class="contiu-signup-side">
    <img class="contiu-signup-logo" src="{{ $logo }}" alt="Contiu">

    <ol class="contiu-signup-steps">
        @foreach ($steps as $step)
            <li @if ($step['state']) data-state="{{ $step['state'] }}" @endif @if ($step['state'] === 'current') aria-current="step" @endif>
                <b>
                    @if ($step['state'] === 'done')
                        <x-filament::icon :icon="Phosphor::Check" class="contiu-signup-step-check" />
                    @else
                        {{ $step['number'] }}
                    @endif
                </b>
                <span>{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>

    @if ($panel)
        <section class="contiu-signup-know" aria-label="{{ $panel['title'] }}">
            <p class="contiu-signup-know-title">{{ $panel['title'] }}</p>

            @if (isset($panel['plans']))
                @foreach ($panel['plans'] as $plan => $items)
                    <dl x-show="$wire.data?.plan === @js($plan)" x-cloak>
                        @foreach ($items as $item)
                            <div><dt>{{ $item['label'] }}</dt><dd @if ($item['new']) data-new="true" @endif>{{ $item['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endforeach
            @elseif ($panel['items'] === [])
                <p class="contiu-signup-know-empty">{{ $panel['empty'] }}</p>
            @else
                <dl>
                    @foreach ($panel['items'] as $item)
                        <div><dt>{{ $item['label'] }}</dt><dd @if ($item['new']) data-new="true" @endif>{{ $item['value'] }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </section>
    @endif

    @if ($foot)
        <p class="contiu-signup-side-foot">{{ $foot }}</p>
    @endif
</aside>
