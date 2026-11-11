{{-- Sign-up screen buttons. Each step is a form: submitting it goes to the next step, or registers on the last one. --}}
<div class="contiu-signup-foot">
    @if ($first)
        <span class="contiu-signup-login">
            {{ __('Already have an account?') }}
            <x-filament::link :href="$loginUrl">{{ __('Sign in') }}</x-filament::link>
        </span>
    @else
        <x-filament::button color="gray" size="lg" type="button" x-on:click="goToPreviousStep()">
            {{ __('Back') }}
        </x-filament::button>
    @endif

    <x-filament::button size="lg" type="submit" wire:loading.attr="disabled" class="contiu-signup-next">
        {{ $last ? $submitLabel : $nextLabel }}
    </x-filament::button>
</div>
