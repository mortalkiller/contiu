<x-mail::message>
# {{ __('Certified accountant requested') }}

{{ __('Introduce the partner’s certified accountant to this account and give them accountant access.') }}

<x-mail::table>
| | |
|:--|:--|
| {{ __('Account') }} | {{ $accountName }} |
| {{ __('Tax ID') }} | {{ $nif ?: '—' }} |
| {{ __('Owner') }} | {{ $owner ?: '—' }} |
| {{ __('Service') }} | {{ $service }} |
| {{ __('Date') }} | {{ $requestedOn }} |
</x-mail::table>

<x-mail::button :url="$url">
{{ __('View account') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
