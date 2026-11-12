@php use App\Support\Money; @endphp
<x-mail::message>
@if (filled($body))
{!! nl2br(e($body)) !!}
@else
{{ __('Hello,') }}

{{ __('Please find attached :type :number.', ['type' => mb_strtolower($typeLabel), 'number' => $number]) }}
@endif

<x-mail::table>
| | |
|:--|--:|
| {{ $typeLabel }} | **{{ $number }}** |
| {{ __('Date') }} | {{ $issuedOn }} |
@if ($dueOn !== null)
| {{ $dueLabel }} | {{ $dueOn }} |
@endif
| {{ __('Total') }} | {{ Money::format($total, app()->getLocale()) }} |
@if ($payable !== $total)
| **{{ __('To pay') }}** | **{{ Money::format($payable, app()->getLocale()) }}** |
@endif
</x-mail::table>

{{ __('The document is attached as a PDF.') }}

{{ __('Kind regards,') }}<br>
{{ $accountName }}
</x-mail::message>
