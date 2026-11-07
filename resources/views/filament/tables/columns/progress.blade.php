@php
    $progress = (float) ($getState() ?? 0);
    $percent = (int) round($progress * 100);
    $tone = match (true) {
        $percent >= 100 => 'bg-success-500',
        $percent > 0 => 'bg-primary-500',
        default => 'bg-gray-300 dark:bg-gray-600',
    };
@endphp

<div class="flex w-full min-w-28 items-center gap-2 px-3 py-4">
    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
        <div class="{{ $tone }} h-full rounded-full" style="width: {{ max($percent, 0) }}%"></div>
    </div>
    <span class="w-9 text-end text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $percent }}%</span>
</div>
