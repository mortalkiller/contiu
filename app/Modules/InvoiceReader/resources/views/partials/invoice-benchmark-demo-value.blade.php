@if (is_array($value))
    @forelse ($value as $entry)
        <dl @class(['space-y-1', 'mt-3 border-t border-gray-200 pt-3 dark:border-gray-700' => ! $loop->first])>
            @foreach ($entry as $key => $detail)
                @continue(! filled($detail))
                <div>
                    @if ($key === 'description' || ($field === 'vat' && $key === 'rate'))
                        <dt class="sr-only">{{ \App\Modules\InvoiceReader\InvoiceData::fieldLabel($key) }}</dt>
                        <dd class="font-medium">{{ $detail }}{{ $key === 'rate' ? '%' : '' }}</dd>
                    @else
                        <dt class="inline text-gray-500">{{ \App\Modules\InvoiceReader\InvoiceData::fieldLabel($key) }}</dt>
                        <dd class="inline">{{ $field === 'vat' && $key === 'category' ? \App\Modules\InvoiceReader\InvoiceData::fieldLabel($detail) : $detail }}{{ $key === 'vat_rate' ? '%' : '' }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    @empty
        <span class="text-gray-500">{{ __('None') }}</span>
    @endforelse
@elseif (filled($value))
    {{ $value }}
@else
    <span class="text-gray-500">—</span>
@endif
