<x-mail::message>
# Pagamento de assinatura recebido

Falta emitir a fatura deste pagamento.

<x-mail::table>
| | |
|:--|:--|
| Conta | {{ $account->name }} |
| NIF | {{ $account->nif ?: '—' }} |
| Morada | {{ $address ?: '—' }} |
| Valor | {{ $amount }} |
| Data | {{ $paidOn }} |
| Período | {{ $period ?: '—' }} |
</x-mail::table>

<x-mail::button :url="$url">
Ver a conta e marcar como faturado
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
