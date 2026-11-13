@use('App\Modules\InvoiceReader\InvoiceData')

<div class="min-w-0">
    <h4 class="mb-2 text-sm font-semibold">{{ InvoiceData::fieldLabel($list) }}</h4>

    @if ($rows !== [])
        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-left text-sm">
                <thead class="border-y border-slate-300 bg-slate-100 text-xs text-slate-700">
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col" @class([
                                'px-2 py-2 font-semibold',
                                'min-w-40' => in_array($column, ['description', 'exemption_reason'], true),
                                'min-w-20 text-right' => in_array($column, InvoiceData::decimalFields(), true),
                            ])>{{ InvoiceData::fieldLabel($column) }}</th>
                        @endforeach
                        <th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach ($rows as $key => $row)
                        <tr wire:key="invoice-review-{{ $list }}-{{ $key }}">
                            @foreach ($columns as $column)
                                <td class="px-1 py-2 align-top">
                                    @include('invoice-reader::partials.invoice-benchmark-paper-field', [
                                        'field' => $list.'.'.$key.'.'.$column,
                                        'label' => InvoiceData::fieldLabel($column),
                                        'decimal' => in_array($column, InvoiceData::decimalFields(), true),
                                        'class' => in_array($column, InvoiceData::decimalFields(), true) ? 'text-right tabular-nums' : '',
                                        'options' => $list === 'vat' && $column === 'category'
                                            ? [
                                                'standard' => InvoiceData::fieldLabel('standard'),
                                                'reduced' => InvoiceData::fieldLabel('reduced'),
                                                'intermediate' => InvoiceData::fieldLabel('intermediate'),
                                                'exempt' => InvoiceData::fieldLabel('exempt'),
                                            ]
                                            : null,
                                        'currentValue' => $row[$column] ?? null,
                                    ])
                                </td>
                            @endforeach
                            <td class="px-1 py-2 align-top">
                                <div class="flex items-center gap-0.5">
                                    @unless ($loop->first)
                                        <button type="button" wire:click='moveCorrectionRow(@js($list), @js((string) $key), -1)' title="{{ __('Move row up') }}" aria-label="{{ __('Move row up') }}" class="rounded-sm px-2 text-slate-600 hover:bg-slate-100">↑</button>
                                    @endunless
                                    @unless ($loop->last)
                                        <button type="button" wire:click='moveCorrectionRow(@js($list), @js((string) $key), 1)' title="{{ __('Move row down') }}" aria-label="{{ __('Move row down') }}" class="rounded-sm px-2 text-slate-600 hover:bg-slate-100">↓</button>
                                    @endunless
                                    <button type="button" wire:click='removeCorrectionRow(@js($list), @js((string) $key))' title="{{ __('Remove row') }}" aria-label="{{ __('Remove row') }}" class="rounded-sm px-2 text-slate-600 hover:bg-slate-100">×</button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <p class="border-y border-slate-200 py-3 text-sm text-slate-600">{{ $emptyMessage }}</p>
    @endif

    <button type="button" wire:click='addCorrectionRow(@js($list))' wire:loading.attr="disabled" class="mt-2 rounded-sm px-1 py-1 text-sm font-medium text-primary-700 hover:bg-slate-100">
        + {{ $addLabel }}
    </button>
</div>
