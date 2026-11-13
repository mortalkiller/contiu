{{-- An invoice already dealt with: what became of it. --}}
@use('App\Enums\InvoiceImportStatus')
@use('App\Filament\Resources\Expenses\ExpenseResource')
@use('App\Filament\Resources\FiscalDocuments\FiscalDocumentResource')
@php $outcome = $import->outcome ?? []; @endphp

<div class="contiu-inv-top">
    <span class="contiu-inv-who">{{ ($import->status === InvoiceImportStatus::Sale ? ($draft['customer_name'] ?? null) : null) ?? $draft['supplier_name'] ?? $import->filename }}</span>
    @if (isset($draft['total'])) <span class="contiu-inv-amount">{{ $money($draft['total']) }}</span> @endif
    <span class="contiu-inv-meta">{{ collect([$draft['number'] ?? null, $day($draft['date'] ?? null)])->filter()->join(' · ') }}</span>
</div>

<p class="contiu-inv-out is-muted"><b>{{ match ($import->status) {
    InvoiceImportStatus::Confirmed => ($outcome['kind'] ?? '') === 'joined'
        ? __('It joined the expense ":description".', ['description' => $outcome['label'] ?? ''])
        : trans_choice('{1} It became a new expense.|[2,*] It became :count new expenses.', (int) ($outcome['expenses'] ?? 1)),
    InvoiceImportStatus::Sale => __('It was recorded as a sale.'),
    InvoiceImportStatus::Duplicate => __('This invoice had already been imported.'),
    default => __('It was left out: nothing was recorded.'),
} }}</b>
    @if ($import->status === InvoiceImportStatus::Duplicate) <small>{{ __('We did not change anything.') }}</small> @endif
</p>

<p class="contiu-inv-more">
    @if ($import->status === InvoiceImportStatus::Confirmed && $import->expense_id !== null)
        <x-filament::link :href="ExpenseResource::getUrl('view', ['record' => $import->expense_id])">{{ __('See the expense') }}</x-filament::link>
    @elseif ($import->status === InvoiceImportStatus::Sale && $import->fiscal_document_id !== null)
        <x-filament::link :href="FiscalDocumentResource::getUrl('view', ['record' => $import->fiscal_document_id])">{{ __('See the invoice') }}</x-filament::link>
    @endif
</p>
