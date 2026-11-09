<x-mail::message>
# Obrigações fiscais

Estas são as obrigações por cumprir em {{ $accountName }}.

<x-mail::table>
| Obrigação | Prazo | Valor |
|:----------|:------|------:|
@foreach ($items as $item)
| {{ $item['title'] }} | {{ $item['due_on'] ?? '—' }}{{ $item['overdue'] ? ' (em atraso)' : '' }} | {{ $item['amount'] ?? '—' }} |
@endforeach
</x-mail::table>

<x-mail::button :url="$url">
Abrir painel
</x-mail::button>

Com os melhores cumprimentos,<br>
{{ $accountName }}
</x-mail::message>
