@use('App\Modules\InvoiceReader\InvoiceData')

@php($suggestions = $this->postalSuggestions[$party] ?? [])

@include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => $party.'_address', 'label' => InvoiceData::fieldLabel($party.'_address'), 'class' => 'text-sm'])
@if (isset($run->review['vies_'.$party][$party.'_address']))
    <p class="mt-2 text-xs text-slate-600">{{ __('Check any missing address details against the full address.') }}</p>
@endif
<dl class="mt-2 grid grid-cols-[auto_1fr] items-baseline gap-x-3 text-sm">
    @foreach (InvoiceData::ADDRESS_FIELDS[$party] as $field)
        <dt class="text-slate-600">{{ InvoiceData::fieldLabel($field) }}</dt>
        <dd class="min-w-0">@include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => $field, 'label' => InvoiceData::fieldLabel($field)])</dd>
    @endforeach
    @foreach ([$party.'_nif' => __('Tax ID'), $party.'_country' => __('Country')] as $field => $label)
        <dt class="text-slate-600">{{ $label }}</dt>
        <dd class="min-w-0">@include('invoice-reader::partials.invoice-benchmark-paper-field', ['field' => $field, 'label' => InvoiceData::fieldLabel($field)])</dd>
    @endforeach
</dl>
@if (filled($suggestions['locality'] ?? null) || ! empty($suggestions['streets']))
    <div class="mt-3 space-y-2 border-t border-slate-200 pt-2 text-sm" wire:key="invoice-postal-suggestions-{{ $party }}-{{ $run->id }}">
        @if (filled($suggestions['locality']))
            <p>
                {{ __('GEO API PT locality for :postalCode · :locality', ['postalCode' => $suggestions['postal_code'], 'locality' => $suggestions['locality']]) }}
                @if ($this->correction[$party.'_locality'] !== $suggestions['locality'])
                    <button type="button" wire:click="usePostalLocality('{{ $party }}')" class="font-medium text-primary-700 underline">{{ __('Use this locality') }}</button>
                @endif
            </p>
        @endif
        @if ($suggestions['streets'] !== [])
            <label class="block">
                <span class="text-slate-600">{{ __('Streets suggested by GEO API PT') }}</span>
                <select wire:change="choosePostalStreet($event.target.value, '{{ $party }}')" class="block w-full rounded-sm border border-slate-300 bg-white px-2 py-1 text-sm text-slate-950">
                    <option value="">{{ __('Choose a street') }}</option>
                    @foreach ($suggestions['streets'] as $streetIndex => $street)
                        <option value="{{ $streetIndex }}" @selected($this->correction[$party.'_street'] === $street)>{{ $street }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>
@endif
