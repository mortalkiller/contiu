{{-- "Email confirmed": straight into Contiu, by the button or by itself after 5 seconds. --}}
<div class="contiu-auth-confirmed" x-data x-init="setTimeout(() => window.location.assign(@js($nextUrl)), 5000)">
    <x-filament::button tag="a" :href="$nextUrl" size="lg" class="contiu-auth-confirmed-button">
        {{ __('Go into Contiu') }}
    </x-filament::button>

    <p class="contiu-auth-redirect" role="status">
        <span class="contiu-auth-spinner" aria-hidden="true"></span>{{ __('We take you in by ourselves in 5 seconds.') }}
    </p>
</div>
