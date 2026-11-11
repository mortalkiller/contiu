{{--
    A row of steps in a card: "O que falta para fechar" on the Fecho do mês (MonthClose::steps()),
    "O ciclo deste trimestre" on the Segurança Social. Styles are in theme.css (.contiu-steps).

    $heading:  the card's heading
    $aside:    ?string, a line beside the heading; without it, a bar of the steps done
    $steps:    list<array{label: string, state: string, done: bool, url?: ?string, tone?: ?string}>
               tone (danger|warning|gray) colours an open step's state; by default it is amber
    $current:  ?int, the step to outline; by default the first one still open
--}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    use function Filament\Support\generate_icon_html;

    $done = count(array_filter($steps, fn (array $step): bool => $step['done']));
    $current ??= array_key_first(array_filter($steps, fn (array $step): bool => ! $step['done']));
    $percent = (int) floor($done / max(1, count($steps)) * 100);
    $headingId = 'steps-'.\Illuminate\Support\Str::slug($heading);
@endphp

<section class="contiu-steps fap-edge-to-edge" aria-labelledby="{{ $headingId }}">
    <div class="contiu-steps-head">
        <h2 id="{{ $headingId }}">{{ $heading }}</h2>

        @if (filled($aside ?? null))
            <span class="contiu-steps-aside">{{ $aside }}</span>
        @else
            <div class="contiu-steps-progress">
                <span class="fap-progress" role="img" aria-label="{{ __(':done of :total steps complete', ['done' => $done, 'total' => count($steps)]) }}">
                    <i style="width: {{ $percent }}%"></i>
                </span>
                <span>{{ __(':done of :total', ['done' => $done, 'total' => count($steps)]) }}</span>
            </div>
        @endif
    </div>

    <ol class="contiu-steps-list" style="--contiu-steps: {{ count($steps) }}">
        @foreach ($steps as $index => $step)
            @php
                $url = $step['done'] ? null : ($step['url'] ?? null);
                $tone = $step['done'] ? null : ($step['tone'] ?? null);
                $icon = match (true) {
                    $step['done'] => Phosphor::CheckCircle,
                    $tone === 'danger' => Phosphor::Warning,
                    $tone === 'gray' => null,
                    default => Phosphor::Clock,
                };
            @endphp
            <li>
                <{!! $url !== null ? 'a href="'.e($url).'"' : 'div' !!} class="contiu-step" data-done="{{ $step['done'] ? 'true' : 'false' }}" {!! $tone !== null ? 'data-tone="'.e($tone).'"' : '' !!} {!! $index === $current ? 'aria-current="step"' : '' !!}>
                    <span class="contiu-step-title">{{ $index + 1 }}. {{ $step['label'] }}</span>
                    <span class="contiu-step-state">
                        @if ($icon !== null)
                            {{ generate_icon_html($icon) }}
                        @endif
                        {{ $step['state'] }}
                    </span>
                </{{ $url !== null ? 'a' : 'div' }}>
            </li>
        @endforeach
    </ol>
</section>
