{{-- "Did not arrive?": what to check when an email from Contiu does not show up. --}}
<div class="contiu-auth-tips">
    <p>{{ __('Did not arrive?') }}</p>

    <ul>
        @foreach ($tips as $tip)
            <li>{{ $tip }}</li>
        @endforeach
    </ul>
</div>
