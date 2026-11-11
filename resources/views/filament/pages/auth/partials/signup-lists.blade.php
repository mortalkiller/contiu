{{-- What the account can use now and what comes later (accounts on hold and with organised accounting). --}}
<div class="contiu-signup-stack">
    <div class="contiu-signup-cols">
        @foreach ($lists as [$title, $items, $later])
            <div>
                <p class="contiu-signup-list-title">{{ $title }}</p>
                <ul class="contiu-signup-list" @if ($later) data-tone="later" @endif>
                    @foreach ($items as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    @if ($notice)
        @include('filament.pages.auth.partials.signup-notice', ['notice' => $notice])
    @endif
</div>
