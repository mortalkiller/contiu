{{-- While the "Este mês" card loads: its own shape (summary block, one task, two limits), with the
     skeleton-loading bones so the shimmer matches the other widgets. --}}
<div role="status" aria-busy="true" class="fsl-skeleton col-span-full">
    <span class="fi-sr-only">{{ __('filament::components/loading-section.label') }}</span>

    <div class="contiu-edge-to-edge grid overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 md:grid-cols-[15rem_1fr] dark:bg-gray-900 dark:ring-white/10" aria-hidden="true">
        <div class="flex flex-col gap-2.5 bg-(--contiu-brand-surface) p-5" style="--fsl-bone: rgb(255 255 255 / 0.18)">
            <span class="fsl-bone fsl-line" style="width: 40%"></span>
            <span class="fsl-bone" style="width: 70%; height: 1.75rem"></span>
            <span class="fsl-bone fsl-line" style="width: 30%"></span>
            <span class="fsl-bone fsl-line mt-3" style="width: 65%"></span>
            <span class="fsl-bone fsl-line mt-auto" style="width: 60%"></span>
        </div>

        <div class="px-5 pb-4 pt-1">
            <div class="flex items-center gap-3 border-b border-gray-950/5 py-3 dark:border-white/10">
                <span class="fsl-bone size-2 rounded-full"></span>
                <span class="fsl-bone fsl-line flex-1" style="max-width: 45%"></span>
                <span class="fsl-bone fsl-line ms-auto" style="width: 4.5rem"></span>
                <span class="fsl-bone fsl-line" style="width: 4rem"></span>
                <span class="fsl-bone" style="width: 3.25rem; height: 1.75rem"></span>
            </div>

            <div class="flex gap-7 pt-4">
                @foreach ([60, 55] as $width)
                    <div class="flex flex-1 flex-col gap-2">
                        <span class="fsl-bone fsl-line" style="width: {{ $width }}%"></span>
                        <span class="fsl-bone" style="height: 0.25rem"></span>
                    </div>
                @endforeach
            </div>

            <span class="fsl-bone fsl-line mt-3 block" style="width: 40%"></span>
        </div>
    </div>
</div>
