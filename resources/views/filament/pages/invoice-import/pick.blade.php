{{-- "A que despesa pertence?": the expenses the invoice may belong to. --}}
@php
    $pickedExpense = is_numeric($picked) ? $choices->firstWhere('id', (int) $picked) : null;
    $total = (int) ($draft['total'] ?? 0);
@endphp

<div class="contiu-inv-top">
    <span class="contiu-inv-who">{{ __('Which expense does it belong to?') }}</span>
    <span class="contiu-inv-meta">{{ collect([$draft['number'] ?? null, $draft['supplier_name'] ?? null, $money($total)])->filter()->join(' · ') }}</span>
</div>

<div class="contiu-inv-pick">
    <label for="inv-search" class="sr-only">{{ __('Search for an expense') }}</label>
    <x-filament::input.wrapper>
        <x-filament::input id="inv-search" type="search" wire:model.live.debounce.400ms="expenseSearch" placeholder="{{ __('Search for an expense') }}" />
    </x-filament::input.wrapper>

    <div class="contiu-inv-options" role="radiogroup" aria-label="{{ __('Expenses') }}">
        @forelse ($choices as $expense)
            @php $gap = abs($expense->total - $total); @endphp
            <label class="contiu-inv-option" wire:key="pick-{{ $expense->id }}">
                <input type="radio" name="picked" value="{{ $expense->id }}" wire:model.live="picked">
                <span><b>{{ $expense->description }}</b>
                    <small>{{ collect([
                        $expense->supplier?->name,
                        $shortDay($expense->spent_on),
                        $expense->proof_path !== null ? __('already has a supporting document') : __('no supporting document'),
                        $gap > 0 ? __('differs by :amount', ['amount' => $money($gap)]) : null,
                    ])->filter()->join(' · ') }}</small></span>
                <i>{{ $money($expense->total) }}</i>
            </label>
        @empty
            <p class="contiu-inv-note">{{ __('No expense without an invoice looks like this one.') }}</p>
        @endforelse
        <label class="contiu-inv-option">
            <input type="radio" name="picked" value="new" wire:model.live="picked">
            <span><b>{{ __('None of these') }}</b> <small>{{ __('Creates a new expense with this invoice') }}</small></span>
        </label>
    </div>
    @error('picked') <p class="contiu-inv-error" role="alert">{{ $message }}</p> @enderror

    @if ($pickedExpense !== null)
        @php $canAdjust = $pickedExpense->bankLink === null && count($draft['rates'] ?? []) === 1 && $pickedExpense->total !== $total; @endphp
        <p class="contiu-inv-out">{{ __('The invoice joins ":description".', ['description' => $pickedExpense->description]) }}
            <small>{{ $canAdjust
                ? __('The expense goes from :from to :to and the invoice is kept as its supporting document.', ['from' => $money($pickedExpense->total), 'to' => $money($total)])
                : __('The invoice is kept as its supporting document.') }}</small></p>
    @endif
</div>

<div class="contiu-inv-act">
    <x-filament::button size="lg" wire:click="joinExpense(true)" wire:loading.attr="disabled">{{ $picked === 'new' ? __('Create a new expense') : __('Join this expense') }}</x-filament::button>
    <div class="contiu-inv-act-row">
        <x-filament::link tag="button" color="gray" wire:click="back">{{ __('Back') }}</x-filament::link>
        @if ($pickedExpense !== null && ($canAdjust ?? false))
            <x-filament::link tag="button" color="gray" wire:click="joinExpense(false)">{{ __('Keep the :amount', ['amount' => $money($pickedExpense->total)]) }}</x-filament::link>
        @endif
    </div>
</div>
