{{--
    Under the new password (ResetPassword): a strength bar and the rules, ticked as they are met.
    It follows what is typed in the browser; the server still validates on save.
--}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

<div
    class="contiu-auth-password-rules"
    x-data="{
        minimumLength: @js($minimumLength),
        get password() { return $wire.password ?? '' },
        get confirmation() { return $wire.passwordConfirmation ?? '' },
        get level() {
            const password = this.password
            if (password.length === 0) return 0
            if (password.length < this.minimumLength) return 1
            const kinds = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^a-zA-Z0-9]/].filter((kind) => kind.test(password)).length
            return password.length >= 14 || kinds >= 3 ? 4 : (kinds >= 2 ? 3 : 2)
        },
    }"
>
    <div class="contiu-auth-strength" x-bind:data-level="level" aria-hidden="true"><i></i><i></i><i></i><i></i></div>

    <p class="contiu-auth-strength-label" x-show="level > 0" x-cloak>
        <span x-show="level === 1">{{ __('Too short.') }}</span>
        <span x-show="level === 2">{{ __('Weak. Mix letters, numbers and symbols, or make it longer.') }}</span>
        <span x-show="level === 3">{{ __('Good. A little longer and it is strong.') }}</span>
        <span x-show="level === 4">{{ __('Strong. A sentence only you know is even better.') }}</span>
    </p>

    <ul class="contiu-auth-rules">
        <li x-bind:data-ok="password.length >= minimumLength">
            <x-filament::icon :icon="Phosphor::Check" />{{ __('At least :count characters', ['count' => $minimumLength]) }}
        </li>
        <li x-bind:data-ok="password.length > 0 && password === confirmation">
            <x-filament::icon :icon="Phosphor::Check" />{{ __('Both are the same') }}
        </li>
    </ul>
</div>
