{{-- What the invoice will become, in one sentence, and "that's right". --}}
@use('App\Enums\FiscalDocumentType')
@use('App\Support\Money')
@php
    $type = FiscalDocumentType::tryFrom((string) ($draft['type'] ?? ''));
    $meta = collect([trim(($type?->getLabel() ?? __('Invoice')).' '.($draft['number'] ?? '')), $day($draft['date'] ?? null)])->filter()->join(' · ');
    $isSale = $decision['kind'] === 'sale';
    $who = $isSale ? ($decision['client']?->name ?? $draft['customer_name'] ?? __('Client to identify')) : ($decision['supplier']?->name ?? $draft['supplier_name'] ?? __('Supplier to identify'));
    $joined = $decision['expense'];
    $blocked = $decision['missing'] !== [] || $decision['questions'] !== [];
    $missingLabels = ['supplier' => __('the supplier'), 'date' => __('the date'), 'amounts' => __('the amounts')];
@endphp

<div class="contiu-inv-top">
    <span class="contiu-inv-who">{{ $who }}
        @if (! $isSale && $decision['kind'] === 'expense' && $decision['supplier'] === null && filled($draft['supplier_name'] ?? null))
            <span class="contiu-inv-new">{{ __('new supplier') }}</span>
        @endif
    </span>
    <span class="contiu-inv-amount">{{ $money($draft['total'] ?? null) }}</span>
    <span class="contiu-inv-meta">{{ $meta }}</span>
</div>

@if ($decision['kind'] === 'duplicate')
    <p class="contiu-inv-out is-muted"><b>{{ __('This invoice had already been imported.') }}</b>
        <small>{{ __('We do not change anything: you can remove it from the list.') }}</small></p>
    @if ($canConfirm)
        <div class="contiu-inv-act">
            <x-filament::button size="lg" x-ref="primary" wire:click="dismiss">{{ __('Remove from the list') }}</x-filament::button>
        </div>
    @endif
@elseif ($isSale)
    <p class="contiu-inv-out is-warn"><b>{{ __('This invoice was issued by you.') }}</b>
        <small>{{ __('Whoever issued it has your tax ID, so it is not an expense: it is a sale to :client.', ['client' => $who]) }}</small></p>

    <dl class="contiu-inv-facts">
        <dt>{{ __('Client') }}</dt>
        <dd>{{ $who }} <small>{{ $decision['client'] !== null ? __('Already your client') : __('New client') }}</small></dd>
        <dt>{{ __('Amount excluding VAT') }}</dt><dd>{{ $money($draft['net'] ?? null) }}</dd>
        <dt>{{ __('VAT') }}</dt><dd>{{ $money($draft['vat'] ?? null) }}</dd>
        <dt>{{ __('Receipt of the amount') }}</dt>
        <dd>
            @if ($type?->settlesOnIssue())
                {{ __('Amount received') }}
            @else
                {{ __('Amount to receive') }}
                @if (filled($draft['due_date'] ?? null)) <small>{{ __('Due on :date', ['date' => $shortDay($draft['due_date'])]) }}</small> @endif
            @endif
        </dd>
    </dl>

    @if ($canConfirm)
        <div class="contiu-inv-act">
            <x-filament::button size="lg" x-ref="primary" wire:click="registerSale" wire:loading.attr="disabled">{{ __('Record as a sale') }}</x-filament::button>
            <div class="contiu-inv-act-row">
                <x-filament::link tag="button" color="gray" x-ref="later" wire:click="later">{{ __('Leave for later') }}</x-filament::link>
                <x-filament::link tag="button" color="gray" wire:click="dismiss">{{ __('I already have it recorded') }}</x-filament::link>
            </div>
        </div>
    @endif
@else
    @if ($decision['missing'] !== [])
        <p class="contiu-inv-out is-warn"><b>{{ __('We could not read :what.', ['what' => collect($decision['missing'])->map(fn ($key) => $missingLabels[$key])->join(', ', ' '.__('and').' ')]) }}</b>
            <small>{{ __('Fill it in to save this invoice.') }}</small></p>
    @elseif ($decision['questions'] !== [])
        <p class="contiu-inv-out is-warn"><b>{{ trans_choice('{1} Before saving, just one question.|[2,*] Before saving, just :count questions.', count($decision['questions'])) }}</b></p>

        <div class="contiu-inv-asks">
            @if (in_array('total', $decision['questions'], true))
                <div class="contiu-inv-ask">
                    <b>{{ __('Which total is right?') }}</b>
                    <p>{{ __('The invoice says :total, but :net + :vat of VAT is :sum.', ['total' => $money($draft['total']), 'net' => $money($draft['net']), 'vat' => $money($draft['vat']), 'sum' => $money($draft['net'] + $draft['vat'])]) }}</p>
                    <div class="contiu-inv-answers">
                        <button type="button" wire:click="answerTotal('invoice')">{{ $money($draft['total']) }} <small>{{ __('what the invoice says') }}</small></button>
                        <button type="button" wire:click="answerTotal('sum')">{{ $money($draft['net'] + $draft['vat']) }} <small>{{ __('base + VAT') }}</small></button>
                    </div>
                </div>
            @endif
            @if (in_array('expense', $decision['questions'], true))
                @php $similar = $decision['similar']; @endphp
                <div class="contiu-inv-ask">
                    <b>{{ __('Is it the expense you already recorded?') }}</b>
                    <p>{{ __('On :date you recorded ":description" from :supplier with :total.', ['date' => $shortDay($similar->spent_on), 'description' => $similar->description, 'supplier' => $who, 'total' => $money($similar->total)]) }}</p>
                    <div class="contiu-inv-answers">
                        <button type="button" wire:click="answerExpense('yes')">{{ __('Yes, that one') }}
                            <small>{{ $similar->total === ($draft['total'] ?? null) ? __('the invoice joins it') : __('sets its amount to :total', ['total' => $money($draft['total'])]) }}</small></button>
                        <button type="button" wire:click="answerExpense('no')">{{ __('No, another') }} <small>{{ __('creates a new expense') }}</small></button>
                    </div>
                </div>
            @endif
        </div>
    @elseif ($joined !== null)
        <p class="contiu-inv-out"><b>{{ __('It joins the expense ":description" you had already recorded.', ['description' => $joined->description]) }}</b>
            <small>
                @if ($decision['adjust'] && $joined->total !== $draft['total'])
                    {{ __('The expense goes from :from to :to and the invoice is kept as its supporting document.', ['from' => $money($joined->total), 'to' => $money($draft['total'])]) }}
                @elseif ($joined->total !== $draft['total'])
                    {{ __('The expense keeps its :amount and the invoice is kept as its supporting document.', ['amount' => $money($joined->total)]) }}
                @else
                    {{ __('The invoice is kept as its supporting document.') }}
                @endif
            </small></p>
    @elseif ($decision['split'])
        <p class="contiu-inv-out"><b>{{ __('It will be :count new expenses, one for each VAT rate.', ['count' => count($draft['rates'])]) }}</b>
            <small>{{ __('An expense in Contiu has a single VAT rate. The invoice is kept as the supporting document of each one.') }}</small></p>
    @else
        <p class="contiu-inv-out"><b>{{ __('It will be a new expense.') }}</b>
            <small>{{ $decision['supplier'] === null
                ? __('The invoice is kept as its supporting document and :supplier joins your suppliers.', ['supplier' => $who])
                : __('The invoice is kept as its supporting document.') }}</small></p>
    @endif

    @if ($joined === null && $decision['missing'] === [])
        <div class="contiu-inv-category">
            <label for="inv-category">{{ __('Category') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="inv-category" wire:change="setCategory($event.target.value)" :disabled="! $canConfirm">
                    <option value="">{{ __('No category') }}</option>
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}" @selected($decision['category_id'] === $id)>{{ $name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif

    @if ($decision['missing'] === [])
        <dl class="contiu-inv-facts">
            @foreach ($draft['rates'] as $rate)
                @php $computed = Money::vat($rate['net'], $rate['rate']); @endphp
                <dt>{{ $decision['split'] ? __('Excluding VAT, at :rate', ['rate' => $percent($rate['rate'])]) : __('Amount excluding VAT') }}</dt>
                <dd>{{ $money($rate['net']) }}</dd>
                <dt>{{ __('VAT at :rate', ['rate' => $percent($rate['rate'])]) }}</dt>
                <dd>{{ $money($rate['vat']) }}
                    @if ($computed !== $rate['vat'] && $joined === null)
                        <small>{{ __('The expense gets :amount, the VAT at this rate.', ['amount' => $money($computed)]) }}</small>
                    @endif
                </dd>
            @endforeach
            <dt>{{ __('Payment') }}</dt>
            <dd>{{ $decision['is_paid'] || $joined?->is_paid ? __('Expense paid') : __('To pay') }}
                @if ($decision['bank'] !== null)
                    <small>{{ __('Bank transaction found: :date', ['date' => $shortDay($decision['bank']->booked_on)]) }}</small>
                @endif
            </dd>
        </dl>
    @endif

    @if ($canConfirm)
        <p class="contiu-inv-more">
            <x-filament::link tag="button" wire:click="correct">{{ $decision['missing'] !== [] ? __('Fill in what is missing') : __('Correct or see more details') }}</x-filament::link>
        </p>

        <div class="contiu-inv-act">
            <x-filament::button size="lg" x-ref="primary" wire:click="confirm" wire:loading.attr="disabled" :disabled="$blocked">{{ __('That\'s right') }}</x-filament::button>
            <div class="contiu-inv-act-row">
                <x-filament::link tag="button" color="gray" x-ref="later" wire:click="later">{{ __('Leave for later') }}</x-filament::link>
                <x-filament::link tag="button" color="gray" wire:click="dismiss">{{ __('It is not my expense') }}</x-filament::link>
            </div>
            <span class="contiu-inv-keys">{{ __('Enter confirms · → goes to the next one') }}</span>
        </div>
    @endif
@endif
