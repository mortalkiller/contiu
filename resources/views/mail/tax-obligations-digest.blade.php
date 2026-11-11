<x-mail::message>
# {{ __('Tax obligations') }}

{{ __('These are the outstanding obligations for :account.', ['account' => $accountName]) }}

<x-mail::table>
| {{ __('Obligation') }} | {{ __('Deadline') }} | {{ __('Value') }} |
|:----------|:------|------:|
@foreach ($items as $item)
| {{ $item['title'] }} | {{ $item['due_on'] ?? '—' }}{{ $item['overdue'] ? ' '.__('(overdue)') : '' }} | {{ $item['amount'] ?? '—' }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
{{ __('Open dashboard') }}
</x-mail::button>

{{ __('Kind regards,') }}<br>
{{ $accountName }}
</x-mail::message>
