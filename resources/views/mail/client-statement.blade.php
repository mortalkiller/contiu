@php use App\Support\Money; @endphp
<x-mail::message>
{!! nl2br(e($body)) !!}

<x-mail::table>
| | |
|:--|--:|
| Cobrado | {{ Money::format($charged) }} |
| Recebido | {{ Money::format($received) }} |
| **Saldo** | **{{ Money::format($balance) }}** |
@if ($overdue > 0)
| Em atraso | {{ Money::format($overdue) }} |
@endif
</x-mail::table>

O extrato completo segue em anexo (Excel).

Com os melhores cumprimentos,<br>
{{ config('app.name') }}
</x-mail::message>
