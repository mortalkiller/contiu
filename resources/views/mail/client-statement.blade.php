@php use App\Support\Money; @endphp
<x-mail::message>
{!! nl2br(e($body)) !!}

<x-mail::table>
| | |
|:--|--:|
| {{ __('Charged') }} | {{ Money::format($charged, app()->getLocale()) }} |
| {{ __('Received') }} | {{ Money::format($received, app()->getLocale()) }} |
| **{{ __('Balance') }}** | **{{ Money::format($balance, app()->getLocale()) }}** |
@if ($overdue > 0)
| {{ __('Overdue') }} | {{ Money::format($overdue, app()->getLocale()) }} |
@endif
</x-mail::table>

{{ __('The full statement is attached as an Excel file.') }}

{{ __('Kind regards,') }}<br>
{{ $accountName }}
</x-mail::message>
