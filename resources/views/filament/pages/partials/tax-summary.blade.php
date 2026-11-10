{{--
    Row of indicators at the top of a tax page, with an optional notice above it. It reuses
    Filament's stat card classes, so the cards look like the ones on the lists.

    $stats:  list<array{label: string, value: string, note?: ?string, tone?: ?string, url?: ?string, percent?: ?float}>
             tone is danger|warning|success for the note; percent draws a progress bar.
    $notice: ?array{title: string, body?: ?string, tone?: string} (tone info|warning)
--}}
@php
    $columns = ['1' => 'xl:grid-cols-1', '2' => 'xl:grid-cols-2', '3' => 'xl:grid-cols-3', '4' => 'xl:grid-cols-4', '5' => 'xl:grid-cols-5'][(string) min(5, max(1, count($stats)))];
    $noteTone = [
        'danger' => 'text-danger-700 dark:text-danger-400',
        'warning' => 'text-warning-800 dark:text-warning-300',
        'success' => 'text-success-700 dark:text-success-400',
    ];
@endphp

<div class="contiu-tax-summary grid gap-6" @isset($live) wire:key="tax-summary" @endisset>
    @if (($notice ?? null) !== null)
        <div
            role="note"
            @class([
                'contiu-edge-to-edge rounded-xl p-4 text-sm ring-1',
                'bg-warning-50 text-warning-900 ring-warning-200 dark:bg-warning-400/10 dark:text-warning-200 dark:ring-warning-400/20' => ($notice['tone'] ?? 'info') === 'warning',
                'bg-white text-gray-700 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10' => ($notice['tone'] ?? 'info') !== 'warning',
            ])
        >
            <p class="font-semibold">{{ $notice['title'] }}</p>
            @if (filled($notice['body'] ?? null))
                <p class="mt-1">{{ $notice['body'] }}</p>
            @endif
        </div>
    @endif

    @if ($stats !== [])
        <div class="grid gap-6 md:grid-cols-2 {{ $columns }}">
            @foreach ($stats as $stat)
                <{{ filled($stat['url'] ?? null) ? 'a href='.e($stat['url']) : 'div' }} class="fi-wi-stats-overview-stat">
                    <div class="fi-wi-stats-overview-stat-content">
                        <div class="fi-wi-stats-overview-stat-label-ctn">
                            <span class="fi-wi-stats-overview-stat-label">{{ $stat['label'] }}</span>
                        </div>
                        <div class="fi-wi-stats-overview-stat-value">{{ $stat['value'] }}</div>
                        @if (isset($stat['percent']))
                            <span class="contiu-progress" role="img" aria-label="{{ number_format($stat['percent'], 0, ',', ' ') }}%">
                                <i style="width: {{ min(100, max(0, $stat['percent'])) }}%"></i>
                            </span>
                        @endif
                        @if (filled($stat['note'] ?? null))
                            <div class="fi-wi-stats-overview-stat-description {{ $noteTone[$stat['tone'] ?? ''] ?? '' }}">
                                <span>{{ $stat['note'] }}</span>
                            </div>
                        @endif
                    </div>
                </{{ filled($stat['url'] ?? null) ? 'a' : 'div' }}>
            @endforeach
        </div>
    @endif
</div>
