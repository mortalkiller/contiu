@php use App\Support\Money; @endphp
<x-mail::message>
{!! nl2br(e($body)) !!}

<x-mail::table>
| {{ __('Reference') }} | {{ __('Due date') }} | {{ __('Outstanding') }} |
|:-----------|:-----------|----------:|
@foreach ($items as $item)
| {{ $item['highlighted'] ? '**' : '' }}{{ $item['code'] }} · {{ $item['description'] }}{{ $item['highlighted'] ? '**' : '' }} | {{ $item['due_on'] }}{{ $item['overdue'] ? ' '.__('(overdue)') : '' }} | {{ Money::format($item['outstanding'], app()->getLocale()) }} |
@endforeach
| **{{ __('Total outstanding') }}** | | **{{ Money::format($totalOpen, app()->getLocale()) }}** |
@if ($totalOverdue > 0)
| {{ __('Of which overdue') }} | | {{ Money::format($totalOverdue, app()->getLocale()) }} |
@endif
</x-mail::table>

{{ __('Kind regards,') }}<br>
{{ $accountName }}
</x-mail::message>
