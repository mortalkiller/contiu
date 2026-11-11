{{--
    Fecho do mês: what is left to close the month, one step per kind of record (MonthClose::steps()).
    A step is done when nothing of its kind is pending. Styles are in theme.css (.contiu-steps).
--}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    use function Filament\Support\generate_icon_html;

    $done = count(array_filter($steps, fn (array $step): bool => $step['done']));
    $percent = (int) floor($done / max(1, count($steps)) * 100);
@endphp

<section class="contiu-steps fap-edge-to-edge" aria-labelledby="month-close-steps-heading">
    <div class="contiu-steps-head">
        <h2 id="month-close-steps-heading">{{ __('Still to do before closing') }}</h2>

        <div class="contiu-steps-progress">
            <span class="fap-progress" role="img" aria-label="{{ __(':done of :total steps complete', ['done' => $done, 'total' => count($steps)]) }}">
                <i style="width: {{ $percent }}%"></i>
            </span>
            <span>{{ __(':done of :total', ['done' => $done, 'total' => count($steps)]) }}</span>
        </div>
    </div>

    <ol class="contiu-steps-list" style="--contiu-steps: {{ count($steps) }}">
        @foreach ($steps as $index => $step)
            <li>
                @if ($step['url'] !== null && ! $step['done'])
                    <a href="{{ $step['url'] }}" class="contiu-step" data-done="false">
                @else
                    <div class="contiu-step" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                @endif
                    <span class="contiu-step-title">{{ $index + 1 }}. {{ $step['label'] }}</span>
                    <span class="contiu-step-state">
                        {{ generate_icon_html($step['done'] ? Phosphor::CheckCircle : Phosphor::Clock) }}
                        {{ $step['state'] }}
                    </span>
                @if ($step['url'] !== null && ! $step['done'])
                    </a>
                @else
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
