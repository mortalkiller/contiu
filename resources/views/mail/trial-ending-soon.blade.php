<x-mail::message>
# {{ __('Your free trial ends on :date', ['date' => $date]) }}

{{ __('The free days of :account end soon. On :date we charge :amount (VAT included) for the :plan plan, with the payment method you gave at sign-up.', ['account' => $accountName, 'date' => $date, 'amount' => $amount, 'plan' => $plan]) }}

{{ __('The subscription then renews :renews until you cancel it. If you do not want to continue, cancel before :date in Subscription: nothing is charged.', ['renews' => $renews, 'date' => $date]) }}

<x-mail::button :url="$url">
{{ __('Open Subscription') }}
</x-mail::button>

{{ __('Kind regards,') }}<br>
{{ config('app.name') }}
</x-mail::message>
