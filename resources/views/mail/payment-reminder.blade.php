@php use App\Support\Money; @endphp
<x-mail::message>
{!! nl2br(e($body)) !!}

<x-mail::table>
| Referência | Vencimento | Em aberto |
|:-----------|:-----------|----------:|
@foreach ($items as $item)
| {{ $item['highlighted'] ? '**' : '' }}{{ $item['code'] }} · {{ $item['description'] }}{{ $item['highlighted'] ? '**' : '' }} | {{ $item['due_on'] }}{{ $item['overdue'] ? ' (em atraso)' : '' }} | {{ Money::format($item['outstanding']) }} |
@endforeach
| **Total em aberto** | | **{{ Money::format($totalOpen) }}** |
@if ($totalOverdue > 0)
| Dos quais em atraso | | {{ Money::format($totalOverdue) }} |
@endif
</x-mail::table>

Com os melhores cumprimentos,<br>
{{ config('app.name') }}
</x-mail::message>
