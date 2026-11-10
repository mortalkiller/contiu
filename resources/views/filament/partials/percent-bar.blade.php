{{--
    A percentage as a small bar with the number beside it. $percent may be null (nothing to
    compare) or negative (a loss). $tone picks the colour rule:
      margin: red below zero, amber below 30%, the palette colour otherwise;
      share:  amber from 50% up (dependence on one client), the palette colour otherwise.
--}}
@php
    $class = match (true) {
        $percent === null => 'bg-gray-300 dark:bg-gray-600',
        $tone === 'share' => $percent >= 50 ? 'bg-warning-500' : 'bg-primary-500',
        $percent < 0 => 'bg-danger-500',
        $percent < 30 => 'bg-warning-500',
        default => 'bg-primary-500',
    };
@endphp

<div class="contiu-percent-bar flex w-full min-w-32 items-center gap-2">
    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
        <div class="{{ $class }} h-full rounded-full" style="width: {{ $percent === null ? 0 : min(100, max(0, $percent)) }}%"></div>
    </div>
    <span @class([
        'w-14 text-end text-xs tabular-nums',
        'text-danger-700 dark:text-danger-400' => $percent !== null && $percent < 0,
        'text-gray-600 dark:text-gray-300' => $percent === null || $percent >= 0,
    ])>{{ $percent === null ? '—' : number_format($percent, 1, ',', ' ').'%' }}</span>
</div>
