<x-mail::message>
# {{ __('Subscription payment received') }}

{{ __('An invoice still needs to be issued for this payment.') }}

<x-mail::table>
| | |
|:--|:--|
| {{ __('Account') }} | {{ $account->name }} |
| {{ __('Tax ID') }} | {{ $account->nif ?: '—' }} |
| {{ __('Address') }} | {{ $address ?: '—' }} |
| {{ __('Value') }} | {{ $amount }} |
| {{ __('Date') }} | {{ $paidOn }} |
| {{ __('Period') }} | {{ $period ?: '—' }} |
</x-mail::table>

<x-mail::button :url="$url">
{{ __('View account and mark as invoiced') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
