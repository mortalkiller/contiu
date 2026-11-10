{{-- Table column: a percentage as a bar (filament.partials.percent-bar). --}}
<div class="w-full px-3 py-4">
    @include('filament.partials.percent-bar', ['percent' => $getState(), 'tone' => $tone ?? 'margin'])
</div>
