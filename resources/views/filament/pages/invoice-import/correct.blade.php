{{-- "Corrigir": the same fields, to edit. One row per VAT rate on the invoice. A sale has its client where an expense has its supplier. --}}
@php
    $isSale = $decision['kind'] === 'sale';
    $lineCount = count($draft['lines'] ?? []);
@endphp

<div class="contiu-inv-top">
    <span class="contiu-inv-who">{{ __('Correct the invoice') }}</span>
    <span class="contiu-inv-meta">{{ ($draft['exact'] ?? []) !== [] ? __('Change only what is wrong. What came from the QR code is already right.') : __('Change only what is wrong.') }}</span>
</div>

<form wire:submit="saveCorrection" class="contiu-inv-form">
    <div class="is-wide">
        <label for="inv-supplier">{{ $isSale ? __('Client') : __('Supplier') }}</label>
        <x-filament::input.wrapper :valid="! $errors->has('form.supplier_name')">
            <x-filament::input id="inv-supplier" type="text" wire:model="form.supplier_name" maxlength="255" required />
        </x-filament::input.wrapper>
        @error('form.supplier_name') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="inv-nif">{{ $isSale ? __('Client\'s tax ID') : __('Supplier\'s tax ID') }}</label>
        <x-filament::input.wrapper :valid="! $errors->has('form.supplier_nif')">
            <x-filament::input id="inv-nif" type="text" inputmode="numeric" wire:model="form.supplier_nif" maxlength="11" />
        </x-filament::input.wrapper>
        @error('form.supplier_nif') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="inv-number">{{ __('Invoice no.') }}</label>
        <x-filament::input.wrapper :valid="! $errors->has('form.number')">
            <x-filament::input id="inv-number" type="text" wire:model="form.number" maxlength="100" />
        </x-filament::input.wrapper>
        @error('form.number') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="inv-date">{{ __('Date') }}</label>
        <x-filament::input.wrapper :valid="! $errors->has('form.date')">
            <x-filament::input id="inv-date" type="date" wire:model="form.date" max="{{ today()->toDateString() }}" required />
        </x-filament::input.wrapper>
        @error('form.date') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
    </div>
    @if ($isSale)
        <div>
            <label for="inv-due">{{ __('Due date') }} <small>{{ __('optional') }}</small></label>
            <x-filament::input.wrapper :valid="! $errors->has('form.due_date')">
                <x-filament::input id="inv-due" type="date" wire:model="form.due_date" />
            </x-filament::input.wrapper>
            @error('form.due_date') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
        </div>
    @else
        <div>
            <label for="inv-form-category">{{ __('Category') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="inv-form-category" wire:model="form.category_id">
                    <option value="">{{ __('No category') }}</option>
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div class="is-wide">
            <label for="inv-description">{{ __('Description of the expense') }}</label>
            <x-filament::input.wrapper :valid="! $errors->has('form.description')">
                <x-filament::input id="inv-description" type="text" wire:model="form.description" maxlength="250" placeholder="{{ $form['supplier_name'] ?? '' }}" />
            </x-filament::input.wrapper>
        </div>
    @endif

    <fieldset class="is-wide contiu-inv-rates">
        <legend>{{ count($form['rates'] ?? []) > 1 ? __('VAT by rate') : __('Invoice amounts') }}</legend>
        @if (count($form['rates'] ?? []) > 1)
            <p class="contiu-inv-note">{{ __('This invoice has more than one VAT rate. Check each one against the table at the bottom of the invoice.') }}</p>
        @endif
        <div class="contiu-inv-rate is-head" aria-hidden="true">
            <span>{{ __('Rate') }}</span><span>{{ __('Excl. VAT') }}</span><span>{{ __('VAT') }}</span><span></span>
        </div>
        @foreach ($form['rates'] ?? [] as $index => $rate)
            <div class="contiu-inv-rate" wire:key="rate-{{ $index }}">
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="form.rates.{{ $index }}.rate" aria-label="{{ __('Rate') }}">
                        @foreach ($rates as $value => $label)
                            <option value="{{ $value }}">{{ $value === '0.0000' ? __('Exempt') : $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::input.wrapper suffix="€" :valid="! $errors->has('form.rates.'.$index.'.net')">
                    <x-filament::input type="text" inputmode="decimal" wire:model="form.rates.{{ $index }}.net" aria-label="{{ __('Amount excluding VAT') }}" required />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper suffix="€" :valid="! $errors->has('form.rates.'.$index.'.vat')">
                    <x-filament::input type="text" inputmode="decimal" wire:model="form.rates.{{ $index }}.vat" aria-label="{{ __('VAT') }}" required />
                </x-filament::input.wrapper>
                @if (count($form['rates']) > 1)
                    <button type="button" class="contiu-inv-remove" wire:click="removeRate({{ $index }})" aria-label="{{ __('Remove this rate') }}">×</button>
                @else
                    <span></span>
                @endif
            </div>
            @foreach (['rate', 'net', 'vat'] as $key)
                @error('form.rates.'.$index.'.'.$key) <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
            @endforeach
        @endforeach
        @error('form.rates') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
        @if (count($form['rates'] ?? []) < 4)
            <x-filament::link tag="button" type="button" wire:click="addRate">{{ __('Add a rate') }}</x-filament::link>
        @endif
    </fieldset>

    @unless ($isSale)
        <details class="is-wide contiu-inv-details" @if ($errors->has('form.cost_centre') || ($form['kind'] ?? '') === 'direct') open @endif>
            <summary>{{ __('More details') }}</summary>
            <div class="contiu-inv-form" x-data="{ kind: $wire.entangle('form.kind') }">
                <div class="is-wide">
                    <span class="contiu-inv-label">{{ __('Expense') }}</span>
                    @if ($decision['expense'] !== null)
                        <p class="contiu-inv-note">{{ __('Joins ":description".', ['description' => $decision['expense']->description]) }}</p>
                    @else
                        <p class="contiu-inv-note">{{ __('Creates a new expense.') }}</p>
                    @endif
                    <x-filament::link tag="button" type="button" wire:click="pickExpense">{{ __('Join an expense that already exists…') }}</x-filament::link>
                </div>
                @if ($decision['expense'] === null)
                    <div>
                        <label for="inv-kind">{{ __('Cost type') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="inv-kind" x-model="kind">
                                <option value="overhead">{{ __('Overhead') }}</option>
                                <option value="direct">{{ __('Direct · of a sale or a retainer') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div x-show="kind === 'direct'" x-cloak>
                        <label for="inv-cost-centre">{{ __('Sale or retainer') }}</label>
                        <x-filament::input.wrapper :valid="! $errors->has('form.cost_centre')">
                            <x-filament::input.select id="inv-cost-centre" wire:model="form.cost_centre">
                                <option value="">{{ __('Choose…') }}</option>
                                @foreach ($costCentres as $group => $options)
                                    <optgroup label="{{ $group }}">
                                        @foreach ($options as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @if ($costCentres === [])
                            <p class="contiu-inv-note">{{ __('You have no sales or retainers yet.') }}</p>
                        @endif
                        @error('form.cost_centre') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="inv-attribution">{{ __('Use in the activity') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="inv-attribution" wire:model="form.attribution">
                            @foreach (\App\Enums\ExpenseAttribution::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->getLabel() }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label for="inv-paid">{{ __('Payment') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="inv-paid" wire:model.boolean="form.is_paid">
                            <option value="1">{{ __('Expense paid') }}</option>
                            <option value="0">{{ __('To pay') }}</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                @if ($decision['bank'] !== null || ($import->choices['bank'] ?? null) === 'none')
                    <div class="is-wide">
                        <label for="inv-bank">{{ __('Bank transaction') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="inv-bank" wire:model.boolean="form.bank">
                                <option value="1">{{ __('Link the transaction found') }}</option>
                                <option value="0">{{ __('Do not link') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif
            </div>
        </details>
    @endunless

    <p class="is-wide contiu-inv-more">
        <x-filament::link tag="button" type="button" wire:click="editLines">{{ $lineCount > 0
            ? trans_choice('{1} 1 line read · see or edit|[2,*] :count lines read · see or edit', $lineCount)
            : __('Add the lines of the invoice') }}</x-filament::link>
    </p>

    <div class="is-wide contiu-inv-act">
        <x-filament::button size="lg" type="submit" wire:loading.attr="disabled">{{ __('Save and confirm') }}</x-filament::button>
        <div class="contiu-inv-act-row">
            <x-filament::link tag="button" type="button" color="gray" wire:click="back">{{ __('Cancel') }}</x-filament::link>
            <x-filament::link tag="button" type="button" color="gray" wire:click="saveCorrection(false)">{{ __('Save without confirming') }}</x-filament::link>
        </div>
    </div>
</form>
