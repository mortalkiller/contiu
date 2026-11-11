@php
    use App\Models\InvoiceXpressNoteAttempt;
    use App\Support\Money;
@endphp

<x-filament-panels::page>
    <x-filament::section :heading="__('Original invoice')">
        @if (! $hasConnection)
            <p class="text-sm">{{ __('Restore the original InvoiceXpress connection to look up the attempts.') }}</p>
        @elseif ($itemIds === [])
            <p class="text-sm">{{ __('Approve the items and configure a series on the invoice issuing page.') }}</p>
        @else
            <form wire:submit="loadOriginal" class="space-y-3">
                <label for="ix-note-original" class="text-sm font-medium">{{ __('InvoiceXpress invoice') }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ix-note-original" wire:model.live="originalId" required>
                        <option value="">{{ __('Select invoice') }}</option>
                        @foreach ($originals as $original)
                            <option value="{{ $original->id }}">{{ $original->number }} · {{ $original->party_name }} · {{ Money::format($original->total_amount, app()->getLocale()) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::button type="submit" color="gray" wire:loading.attr="disabled">{{ __('Look up invoice lines') }}</x-filament::button>
            </form>
        @endif
        @if ($hasConnection && ! $canWriteDocuments)
            <p class="mt-3 text-sm">{{ __('Issuing is pending validation of the InvoiceXpress catalogue.') }}</p>
        @endif
    </x-filament::section>

    @if ($originalData !== null)
        <x-filament::section :heading="__('Note details')">
            <form wire:submit="preview" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="ix-note-kind" class="text-sm font-medium">{{ __('Note type') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-kind" wire:model.live="kind">
                                <option value="credit_note">{{ __('Credit note') }}</option>
                                <option value="debit_note">{{ __('Debit note') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-line" class="text-sm font-medium">{{ __('Original line') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-line" wire:model.live="line" required>
                                <option value="">{{ __('Select line') }}</option>
                                @foreach ($originalData['lines'] as $index => $originalLine)
                                    <option value="{{ $index + 1 }}">{{ $index + 1 }} · {{ $originalLine['name'] }} · {{ __(':quantity pc.', ['quantity' => $originalLine['quantity']]) }} · {{ Money::format($originalLine['total'], app()->getLocale()) }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-item" class="text-sm font-medium">{{ __('Approved item identifier') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-item" wire:model.live="itemId" required>
                                <option value="">{{ __('Select item') }}</option>
                                @foreach ($itemIds as $id)
                                    <option value="{{ $id }}">#{{ $id }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-quantity" class="text-sm font-medium">{{ __('Quantity to correct') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="ix-note-quantity" type="number" wire:model.live.change="quantity" min="1" max="1000" step="1" required />
                        </x-filament::input.wrapper>
                    </div>
                </div>
                <div class="space-y-1">
                    <label for="ix-note-reason" class="text-sm font-medium">{{ __('Reason for correction') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-note-reason" wire:model.live.change="reason" maxlength="255" required />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Preview note') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    @foreach ($errors->all() as $error)
        <p class="text-sm text-danger-600" role="alert">{{ $error }}</p>
    @endforeach

    @if ($previewData !== null)
        <x-filament::section :heading="__('Confirm issuing')">
            <form wire:submit="confirm" class="space-y-4">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt>{{ __('Type and original invoice') }}</dt><dd class="font-semibold">{{ $previewData['input']['kind'] === 'credit_note' ? __('Credit note') : __('Debit note') }} · {{ $previewData['number'] }}</dd></div>
                    <div><dt>{{ __('Client') }}</dt><dd class="font-semibold">{{ $previewData['customer']['name'] }} · {{ __('Tax ID :number', ['number' => $previewData['customer']['nif']]) }}</dd></div>
                    <div><dt>{{ __('Line and quantity') }}</dt><dd class="font-semibold">{{ $previewData['input']['line'] }} · {{ $previewData['line'] }} × {{ $previewData['input']['quantity'] }}</dd></div>
                    <div><dt>{{ __('Series and date') }}</dt><dd class="font-semibold">{{ $previewData['sequence'] }} · {{ $previewData['date'] }}</dd></div>
                    <div><dt>{{ __('Net amount, VAT and total') }}</dt><dd class="font-semibold">{{ Money::format($previewData['totals']['net'], app()->getLocale()) }} + {{ Money::format($previewData['totals']['tax'], app()->getLocale()) }} = {{ Money::format($previewData['totals']['total'], app()->getLocale()) }}</dd></div>
                    <div><dt>{{ __('Available on this line for this note type') }}</dt><dd class="font-semibold">{{ __(':quantity pc.', ['quantity' => $previewData['remaining']['quantity']]) }} · {{ Money::format($previewData['remaining']['total'], app()->getLocale()) }}</dd></div>
                    <div><dt>{{ __('Reason') }}</dt><dd class="font-semibold">{{ $previewData['input']['reason'] }}</dd></div>
                </dl>
                <label for="ix-note-accepted" class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox id="ix-note-accepted" wire:model="accepted" />
                    {{ __('I have checked this information and authorise issuing the note in InvoiceXpress.') }}
                </label>
                <x-filament::button type="submit" :disabled="! $canWriteDocuments" wire:loading.attr="disabled">{{ __('Confirm and issue note') }}</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('Note issuing attempts')">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($attempts as $attempt)
                <li wire:key="ix-note-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                    <div>
                        <span class="font-semibold">{{ __('Attempt #:id', ['id' => $attempt->id]) }}</span>
                        <span> · {{ __('Invoice :number', ['number' => $attempt->snapshot['original']['number']]) }}</span>
                        @if ($attempt->state === InvoiceXpressNoteAttempt::Finalized)
                            <p>{{ $attempt->document_reference }} · ATCUD {{ $attempt->atcud }}</p>
                        @elseif ($attempt->state === InvoiceXpressNoteAttempt::Blocked)
                            <p>{{ __('Manual review required in InvoiceXpress.') }}</p>
                        @else
                            <p>{{ __('Pending confirmation in InvoiceXpress.') }}</p>
                        @endif
                    </div>
                    @if ($hasConnection && in_array($attempt->state, InvoiceXpressNoteAttempt::Recoverable, true))
                        <x-filament::button type="button" color="gray" wire:click="recover({{ $attempt->id }})" wire:loading.attr="disabled">
                            {{ __('Check and resume attempt') }}
                        </x-filament::button>
                    @endif
                </li>
            @empty
                <li class="text-sm">{{ __('No note issuing attempts.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
