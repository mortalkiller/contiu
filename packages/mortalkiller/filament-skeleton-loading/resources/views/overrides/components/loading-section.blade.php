{{-- Replaces Filament's grey pulsing box (lazy widgets and sections) with a generic skeleton. --}}
@props([
    'columnSpan' => [],
    'columnStart' => [],
    'height' => null,
    'loadingLabel' => null,
])

@php
    use Filament\Support\View\ComponentAttributeBag;
@endphp

<div
    role="status"
    aria-busy="true"
    {{
        ($attributes ?? new ComponentAttributeBag)
            ->gridColumn($columnSpan, $columnStart)
            ->class(['fi-section fsl-skeleton fsl-skeleton--generic fsl-card'])
            ->style(['min-height: '.e($height ?? '8rem')])
    }}
>
    <span class="fi-sr-only">
        {{ $loadingLabel ?? __('filament::components/loading-section.label') }}
    </span>

    <span class="fsl-bone fsl-title" aria-hidden="true"></span>
    <span class="fsl-bone fsl-line" style="width: 90%" aria-hidden="true"></span>
    <span class="fsl-bone fsl-line" style="width: 70%" aria-hidden="true"></span>
</div>
