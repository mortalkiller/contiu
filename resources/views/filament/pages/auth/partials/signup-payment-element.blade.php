{{--
    Payment for the plan (contiu.signup_payment): Stripe's Payment Element, in setup mode so nothing
    is charged now, with the payment methods turned on in the Stripe dashboard (it shows the SEPA
    mandate itself). On submit: the server checks the whole sign-up and makes a SetupIntent
    (prepareCardSetup), Stripe.js confirms it here, asking the bank's authentication when needed,
    and only then the account is created; StartSignupSubscription subscribes with that payment
    method. The card details never reach Contiu.
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
                this.elements = this.stripe.elements({ mode: 'setup', currency: 'eur' })
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

                    // Null when the sign-up has errors: they are already on the form.
                    const clientSecret = await $wire.prepareCardSetup()

                    if (! clientSecret) {
                        return
                    }

                    const { error, setupIntent } = await this.stripe.confirmSetup({
                        elements: this.elements,
                        clientSecret,
                        redirect: 'if_required',
                        confirmParams: {
                            return_url: window.location.href,
                            payment_method_data: { billing_details: { name: $wire.data.name, email: $wire.data.email } },
                        },
                    })

                    if (error) {
                        this.error = error.message

                        return
                    }

                    $wire.$set('data.payment_method', setupIntent.payment_method, false)
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
    {{ __('Payment details go straight to Stripe. Contiu never sees nor stores them.') }}
</p>
