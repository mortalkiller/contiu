{{-- "Preencher à mão": the essentials of an invoice that could not be read. --}}
@php $amounts = $this->byHandAmounts(); @endphp

<div class="contiu-inv-top">
    <span class="contiu-inv-who">{{ __('Fill in by hand') }}</span>
    <span class="contiu-inv-meta">{{ __('Only the essentials: the file is kept as the supporting document.') }}</span>
</div>

<form wire:submit="saveByHand" class="contiu-inv-form">
    <div class="is-wide">
        <label for="hand-supplier">{{ __('Supplier') }}</label>
        <x-filament::input.wrapper :valid="! $errors->has('form.supplier_name')">
            <x-filament::input id="hand-supplier" type="text" wire:model="form.supplier_name" maxlength="255" required />
        </x-filament::input.wrapper>
        @error('form.supplier_name') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="hand-nif">{{ __('Supplier\'s tax ID') }} <small>{{ __('optional') }}</small></label>
        <x-filament::input.wrapper :valid="! $errors->has('form.supplier_nif')">
            <x-filament::input id="hand-nif" type="text" inputmode="numeric" wire:model="form.supplier_nif" maxlength="11" />
        </x-filament::input.wrapper>
        @error('form.supplier_nif') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="hand-date">{{ __('Date') }}</label>
        <x-filament::input.wrapper :valid="! $errors->has('form.date')">
            <x-filament::input id="hand-date" type="date" wire:model="form.date" max="{{ today()->toDateString() }}" required />
        </x-filament::input.wrapper>
        @error('form.date') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="hand-number">{{ __('Invoice no.') }} <small>{{ __('optional') }}</small></label>
        <x-filament::input.wrapper>
            <x-filament::input id="hand-number" type="text" wire:model="form.number" maxlength="100" />
        </x-filament::input.wrapper>
    </div>
    <div>
        <label for="hand-total">{{ __('Total with VAT') }}</label>
        <x-filament::input.wrapper suffix="€" :valid="! $errors->has('form.total')">
            <x-filament::input id="hand-total" type="text" inputmode="decimal" wire:model.live.blur="form.total" required />
        </x-filament::input.wrapper>
        @error('form.total') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="hand-rate">{{ __('VAT rate') }}</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="hand-rate" wire:model.live="form.rate">
                @foreach ($rates as $value => $label)
                    <option value="{{ $value }}">{{ $value === '0.0000' ? __('Exempt') : $label }}</option>
                @endforeach
                <option value="several">{{ __('Several rates…') }}</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>
    <div>
        <label for="hand-category">{{ __('Category') }}</label>
        <x-filament::input.wrapper>
            <x-filament::input.select id="hand-category" wire:model="form.category_id">
                <option value="">{{ __('No category') }}</option>
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    @if ($amounts !== null)
        <dl class="is-wide contiu-inv-facts">
            <dt>{{ __('Amount excluding VAT') }}</dt><dd>{{ $money($amounts['net']) }}</dd>
            <dt>{{ __('VAT') }}</dt>
            <dd>{{ $money($amounts['vat']) }}
                @if ($amounts['total'] !== $amounts['typed'])
                    <small>{{ __('At this rate the total comes to :total.', ['total' => $money($amounts['total'])]) }}</small>
                @endif
            </dd>
        </dl>
    @endif

    <div class="is-wide contiu-inv-act">
        <x-filament::button size="lg" type="submit" wire:loading.attr="disabled">{{ ($form['rate'] ?? '') === 'several' ? __('Go on to the amounts of each rate') : __('Save expense') }}</x-filament::button>
        <div class="contiu-inv-act-row">
            <x-filament::link tag="button" type="button" color="gray" wire:click="back">{{ __('Back') }}</x-filament::link>
            <x-filament::link tag="button" type="button" color="gray" wire:click="dismiss">{{ __('Remove from the list') }}</x-filament::link>
        </div>
    </div>
</form>
