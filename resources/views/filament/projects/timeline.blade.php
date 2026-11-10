{{-- "Linha do tempo" of a project (App\Filament\Resources\Projects\Schemas\ProjectTimeline). --}}
<ol class="contiu-timeline">
    @foreach ($events as $event)
        <li class="contiu-timeline-item" data-state="{{ $event['state'] }}">
            <span class="contiu-timeline-mark" aria-hidden="true"></span>
            <span class="contiu-timeline-text">
                <span class="contiu-timeline-title">{{ $event['title'] }}</span>
                <span class="contiu-timeline-meta">{{ $event['meta'] }}</span>
            </span>
            @if ($event['amount'] !== null)
                <span class="contiu-timeline-amount">{{ $event['amount'] }}</span>
            @endif
        </li>
    @endforeach
</ol>
