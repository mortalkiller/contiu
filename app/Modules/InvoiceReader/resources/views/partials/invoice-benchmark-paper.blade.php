@use('App\Modules\InvoiceReader\InvoiceData')

<article aria-label="{{ __('Invoice review sheet') }}" class="space-y-7 rounded-sm border border-slate-200 bg-white p-4 text-slate-950 shadow-md @lg:p-6 @3xl:p-8">
    <header class="grid gap-6 border-b border-slate-300 pb-6 @xl:grid-cols-2">
        <div class="min-w-0">
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-slate-600">{{ __('Supplier') }}</p>
            @include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => 'supplier_name', 'label' => InvoiceData::fieldLabel('supplier_name'), 'class' => 'text-xl font-semibold'])
            @include('invoice-reader::partials.invoice-benchmark-paper-address', ['party' => 'supplier'])
        </div>

        <div class="min-w-0 @xl:justify-self-end @xl:text-right">
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-slate-600">{{ __('Invoice review sheet') }}</p>
            @include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => 'invoice_number', 'label' => InvoiceData::fieldLabel('invoice_number'), 'class' => 'text-2xl font-semibold @xl:text-right'])
            <dl class="mt-2 grid grid-cols-[auto_1fr] items-baseline gap-x-3 text-left text-sm">
                @foreach (['document_type', 'issue_date', 'due_date', 'atcud', 'currency'] as $field)
                    <dt class="text-slate-600">{{ InvoiceData::fieldLabel($field) }}</dt>
                    <dd class="min-w-0">@include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => $field, 'label' => InvoiceData::fieldLabel($field), 'class' => 'md:text-right'])</dd>
                @endforeach
            </dl>
        </div>
    </header>

    <div class="min-w-0 md:max-w-lg">
        <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-slate-600">{{ __('Client') }}</p>
        @include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => 'customer_name', 'label' => InvoiceData::fieldLabel('customer_name'), 'class' => 'text-base font-semibold'])
        @include('invoice-reader::partials.invoice-benchmark-paper-address', ['party' => 'customer'])
    </div>

    @include('invoice-reader::partials.invoice-benchmark-paper-rows', [
        'list' => 'lines',
        'rows' => $this->correction['lines'] ?? [],
        'columns' => InvoiceData::LINE_FIELDS,
        'addLabel' => __('Add line item'),
        'emptyMessage' => __('No invoice lines were extracted.'),
    ])

    <div class="grid gap-6 border-t border-slate-300 pt-5 @4xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="min-w-0">
            @include('invoice-reader::partials.invoice-benchmark-paper-rows', [
                'list' => 'vat',
                'rows' => $this->correction['vat'] ?? [],
                'columns' => InvoiceData::VAT_FIELDS,
                'addLabel' => __('Add VAT rate'),
                'emptyMessage' => __('No VAT breakdown was extracted.'),
            ])
            <p class="mt-4 text-xs text-slate-600">{{ InvoiceData::fieldLabel('exemption_reason') }}</p>
            @include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => 'exemption_reason', 'label' => InvoiceData::fieldLabel('exemption_reason'), 'class' => 'text-sm'])
        </div>

        <dl class="min-w-0 text-sm">
            @foreach (['net_amount', 'discount', 'taxable_amount', 'vat_amount', 'total_tax', 'paid_amount', 'gross_total'] as $field)
                <div @class([
                    'grid grid-cols-2 items-baseline gap-2 px-2 py-1',
                    'border-b border-slate-200' => $field !== 'gross_total',
                    'mt-2 border-y border-slate-400 bg-slate-100 py-2 text-lg font-semibold' => $field === 'gross_total',
                ])>
                    <dt>{{ InvoiceData::fieldLabel($field) }}</dt>
                    <dd class="min-w-0">
                        @include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => $field, 'label' => InvoiceData::fieldLabel($field), 'decimal' => true, 'class' => 'text-right tabular-nums'])
                    </dd>
                </div>
            @endforeach
        </dl>
    </div>
</article>
