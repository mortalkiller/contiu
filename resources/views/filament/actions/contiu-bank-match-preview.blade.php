<div
    class="grid gap-4"
    x-load
    x-load-src="{{ asset('js/contiu-bank-match-preview.js') }}"
    x-data="contiuBankMatchPreview(@js([
        'amount' => $amount,
        'targets' => $targets,
        'locale' => $locale,
        'message' => __('The statement shows :amount. The selected records differ by :difference. Confirm that this is the same transaction.', [
            'amount' => $statementAmount,
            'difference' => ':difference',
        ]),
        'explanations' => array_map(fn ($option) => $option?->value, $explanations),
    ]))"
    x-effect="clearUnavailableExplanation()"
>
    <x-filament::callout
        :heading="__('The amount differs')"
        :icon="\ToneGabes\Filament\Icons\Enums\Phosphor::Warning"
        color="warning"
        x-show="warning"
        x-cloak
    >
        <x-slot name="description">
            <span x-text="warning" aria-live="polite"></span>
        </x-slot>
    </x-filament::callout>

    @if ($options = array_filter(array_unique($explanations, SORT_REGULAR)))
        <div x-show="explanation" x-cloak>
            <x-dynamic-component :component="$getFieldWrapperView()" :field="$field" label-tag="div">
                <div class="fi-fo-radio" role="radiogroup" aria-labelledby="{{ $getId() }}-label">
                    @foreach ($options as $option)
                        <label class="fi-fo-radio-label" x-show="explanation === @js($option->value)">
                            <input
                                class="fi-radio-input fi-valid"
                                type="radio"
                                name="{{ $getId() }}"
                                value="{{ $option->value }}"
                                wire:model="{{ $getStatePath() }}"
                            />
                            <span class="fi-fo-radio-label-text">{{ $option->getLabel() }}</span>
                        </label>
                    @endforeach
                </div>
            </x-dynamic-component>
        </div>
    @endif
</div>
