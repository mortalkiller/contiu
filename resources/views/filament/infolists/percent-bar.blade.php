{{-- Infolist entry: a percentage as a bar (filament.partials.percent-bar). --}}
@include('filament.partials.percent-bar', ['percent' => $getState(), 'tone' => $tone ?? 'margin'])
