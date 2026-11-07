@php
    use Filament\Support\View\ComponentAttributeBag;

    $kind ??= 'generic';
    $items = max(1, (int) ($items ?? 4));
    $rows = max(1, (int) ($rows ?? 5));
    $chart ??= 'bars';
    $heading ??= null;
    $height ??= null;
    $columnSpan ??= [];
    $columnStart ??= [];
    $bars = [45, 70, 55, 85, 40, 65, 90, 50, 75, 60, 80, 48];
@endphp

<div
    role="status"
    aria-busy="true"
    {{
        (new ComponentAttributeBag)
            ->gridColumn($columnSpan, $columnStart)
            ->class(['fsl-skeleton', 'fsl-skeleton--'.$kind])
            ->style([$height ? 'min-height: '.e($height) : null])
    }}
>
    <span class="fi-sr-only">{{ __('filament::components/loading-section.label') }}</span>

    @if ($kind === 'stats')
        <div class="fsl-grid" style="grid-template-columns: repeat({{ $items }}, minmax(0, 1fr))" aria-hidden="true">
            @for ($i = 0; $i < $items; $i++)
                <div class="fi-section fsl-stat">
                    <span class="fsl-bone fsl-line" style="width: 45%"></span>
                    <span class="fsl-bone fsl-value" style="width: 65%"></span>
                    <span class="fsl-bone fsl-line" style="width: 55%"></span>
                </div>
            @endfor
        </div>
    @elseif ($kind === 'chart')
        <div class="fi-section fsl-card" aria-hidden="true">
            @if ($heading)
                <p class="fsl-heading">{{ $heading }}</p>
            @else
                <span class="fsl-bone fsl-title"></span>
            @endif

            @if ($chart === 'ring')
                <div class="fsl-chart-ring-wrap">
                    <span class="fsl-bone fsl-chart-ring"></span>
                    <div class="fsl-legend">
                        @for ($i = 0; $i < 4; $i++)
                            <span class="fsl-bone fsl-line" style="width: {{ [70, 55, 62, 40][$i] }}%"></span>
                        @endfor
                    </div>
                </div>
            @elseif ($chart === 'line')
                <svg class="fsl-chart-line" viewBox="0 0 300 120" preserveAspectRatio="none">
                    <path d="M0 90 C 30 70, 50 95, 80 70 S 130 30, 160 50 S 220 85, 250 40 S 285 25, 300 35" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" vector-effect="non-scaling-stroke"/>
                </svg>
            @else
                <div class="fsl-chart-bars">
                    @foreach ($bars as $bar)
                        <span class="fsl-bone" style="height: {{ $bar }}%"></span>
                    @endforeach
                </div>
            @endif
        </div>
    @elseif ($kind === 'table')
        <div class="fi-section fsl-card fsl-card--flush" aria-hidden="true">
            <div class="fsl-table-head">
                <span class="fsl-bone fsl-title"></span>
            </div>
            <div class="fsl-table">
                @for ($i = 0; $i < $rows; $i++)
                    <div class="fsl-row">
                        <span class="fsl-bone fsl-line" style="width: {{ [80, 65, 90, 70, 75][$i % 5] }}%"></span>
                        <span class="fsl-bone fsl-line" style="width: {{ [70, 85, 60, 90, 55][$i % 5] }}%"></span>
                        <span class="fsl-bone fsl-line" style="width: {{ [60, 50, 70, 45, 65][$i % 5] }}%"></span>
                        <span class="fsl-bone fsl-line" style="width: {{ [50, 70, 40, 60, 55][$i % 5] }}%"></span>
                    </div>
                @endfor
            </div>
        </div>
    @else
        <div class="fi-section fsl-card" aria-hidden="true">
            <span class="fsl-bone fsl-title"></span>
            <span class="fsl-bone fsl-line" style="width: 90%"></span>
            <span class="fsl-bone fsl-line" style="width: 75%"></span>
            <span class="fsl-bone fsl-line" style="width: 60%"></span>
        </div>
    @endif
</div>
