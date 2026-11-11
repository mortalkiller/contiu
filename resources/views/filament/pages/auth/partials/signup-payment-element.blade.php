{{--
    Card for the plan (contiu.signup_payment): Stripe's Payment Element, in setup mode so nothing is
    charged now. On submit it creates the PaymentMethod in the browser (the card never reaches
    Contiu) and then registers; StartSignupSubscription subscribes with it.
--}}
@php
    use ToneGabes\Filament\Icons\Enums\Phosphor;
@endphp

@if (blank($publishableKey))
    <x-filament::callout color="danger" :icon="Phosphor::WarningCircle" :description="__('Card payments are not set up yet (Stripe keys missing).')" />
@else
    <div
        wire:ignore
        class="contiu-signup-pay"
        x-data="{
            stripe: null,
            elements: null,
            error: null,
            busy: false,
            async init() {
                if (! window.Stripe) {
                    await new Promise((resolve, reject) => {
                        const script = document.createElement('script')
                        script.src = 'https://js.stripe.com/v3/'
                        script.onload = resolve
                        script.onerror = reject
                        document.head.appendChild(script)
                    })
                }

                this.stripe = window.Stripe(@js($publishableKey), { locale: @js($locale) })
                this.elements = this.stripe.elements({ mode: 'setup', currency: 'eur', paymentMethodCreation: 'manual', paymentMethodTypes: ['card'] })
                this.elements.create('payment').mount(this.$refs.element)
            },
            async pay() {
                if (this.busy || ! this.elements) {
                    return
                }

                this.busy = true
                this.error = null

                try {
                    const { error: submitError } = await this.elements.submit()

                    if (submitError) {
                        this.error = submitError.message

                        return
                    }

                    const { error, paymentMethod } = await this.stripe.createPaymentMethod({
                        elements: this.elements,
                        params: { billing_details: { name: $wire.data.name, email: $wire.data.email } },
                    })

                    if (error) {
                        this.error = error.message

                        return
                    }

                    $wire.$set('data.payment_method', paymentMethod.id, false)
                    await $wire.register()
                } finally {
                    this.busy = false
                }
            },
        }"
        x-on:contiu-signup-pay.window="pay()"
    >
        <div x-ref="element"></div>
        <p x-show="error" x-text="error" x-cloak class="contiu-signup-pay-error" role="alert"></p>
    </div>
@endif

<p class="contiu-signup-pay-secure">
    <x-filament::icon :icon="Phosphor::Lock" />
    {{ __('Card details go straight to Stripe. Contiu never sees nor stores them.') }}
</p>
