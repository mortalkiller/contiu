{{-- "Linhas da fatura": what was bought, line by line. The lines describe; for the taxes, the totals of the invoice count. --}}
@php $summary = $this->linesSummary(); @endphp

<div class="contiu-inv-top">
    <span class="contiu-inv-who">{{ __('Lines of the invoice') }}</span>
    <span class="contiu-inv-meta">{{ collect([$draft['supplier_name'] ?? null, $draft['number'] ?? null])->filter()->join(' · ') }}</span>
    <span class="contiu-inv-meta">{{ __('The lines help to describe the expense; for the taxes, the totals count.') }}</span>
</div>

<form wire:submit="saveLines" class="contiu-inv-form">
    <div class="is-wide contiu-inv-line-list">
        @forelse ($lines as $index => $line)
            <div class="contiu-inv-line" wire:key="line-{{ $index }}">
                <div class="contiu-inv-line-head">
                    <x-filament::input.wrapper :valid="! $errors->has('lines.'.$index.'.description')">
                        <x-filament::input type="text" wire:model="lines.{{ $index }}.description" maxlength="255" aria-label="{{ __('Description of line :number', ['number' => $index + 1]) }}" placeholder="{{ __('What was bought') }}" required />
                    </x-filament::input.wrapper>
                    <button type="button" class="contiu-inv-remove" wire:click="removeLine({{ $index }})" aria-label="{{ __('Remove line :number', ['number' => $index + 1]) }}">×</button>
                </div>
                <div class="contiu-inv-line-row">
                    <label>{{ __('Qty.') }}
                        <x-filament::input.wrapper :valid="! $errors->has('lines.'.$index.'.quantity')">
                            <x-filament::input type="text" inputmode="decimal" wire:model.live.blur="lines.{{ $index }}.quantity" required />
                        </x-filament::input.wrapper>
                    </label>
                    <label>{{ __('Price excl. VAT') }}
                        <x-filament::input.wrapper suffix="€" :valid="! $errors->has('lines.'.$index.'.unit_price')">
                            <x-filament::input type="text" inputmode="decimal" wire:model.live.blur="lines.{{ $index }}.unit_price" required />
                        </x-filament::input.wrapper>
                    </label>
                    <label>{{ __('VAT') }}
                        <x-filament::input.wrapper>
                            <x-filament::input.select wire:model.live="lines.{{ $index }}.rate">
                                @foreach ($rates as $value => $label)
                                    <option value="{{ $value }}">{{ $value === '0.0000' ? __('Exempt') : $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </label>
                </div>
                <div class="contiu-inv-line-total">{{ __('Excl. VAT') }} <b>{{ $summary['totals'][$index] !== null ? $money($summary['totals'][$index]) : '—' }}</b></div>
                @foreach (['description', 'quantity', 'unit_price', 'rate'] as $key)
                    @error('lines.'.$index.'.'.$key) <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
                @endforeach
            </div>
        @empty
            <p class="contiu-inv-note">{{ __('This invoice has no lines yet.') }}</p>
        @endforelse

        @error('lines') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
        @if (count($lines) < \App\Support\InvoiceImportDraft::MAX_LINES)
            <x-filament::link tag="button" type="button" wire:click="addLine">{{ __('Add a line') }}</x-filament::link>
        @endif
    </div>

    @if ($lines !== [])
        <dl class="is-wide contiu-inv-facts">
            <dt>{{ __('Sum of the lines, excluding VAT') }}</dt>
            <dd>{{ $money($summary['sum']) }}
                @if ($summary['difference'] === 0)
                    <small class="is-ok">{{ __('Matches the invoice') }}</small>
                @elseif ($summary['difference'] !== null)
                    <small class="is-off">{{ __('The invoice says :amount: the lines are kept as a note only.', ['amount' => $money($draft['net'] ?? null)]) }}</small>
                @endif
            </dd>
        </dl>
    @endif

    <div class="is-wide contiu-inv-act">
        <x-filament::button size="lg" type="submit" wire:loading.attr="disabled">{{ __('Save lines') }}</x-filament::button>
        <div class="contiu-inv-act-row">
            <x-filament::link tag="button" type="button" color="gray" wire:click="closeLines">{{ __('Back without saving') }}</x-filament::link>
        </div>
    </div>
</form>
