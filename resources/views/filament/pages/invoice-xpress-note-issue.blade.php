@php
    use App\Filament\Pages\FiscalDocumentDraftsPage;
    use App\Filament\Pages\IntegrationsPage;
    use App\Models\InvoiceXpressNoteAttempt;
    use App\Support\Money;
@endphp

<x-filament-panels::page>
    @if ($savedDraft !== null)
        <div class="flex flex-wrap items-center gap-3" role="status">
            <span>{{ __('Draft #:id', ['id' => $savedDraft->id]) }} · {{ FiscalDocumentDraftsPage::statusLabel($savedDraft) }}</span>
            <x-filament::link :href="FiscalDocumentDraftsPage::getUrl()">{{ __('Drafts') }}</x-filament::link>
        </div>
    @endif

    @if ($readOnlyDraft)
        <x-filament::section :heading="__('Draft details')">
            <dl class="space-y-3 text-sm">
                @if ($savedDraft->remote_id !== null)
                    <div><dt>{{ __('InvoiceXpress draft ID') }}</dt><dd class="font-semibold">#{{ $savedDraft->remote_id }}</dd></div>
                @endif
                @php($total = $savedDraft->preview['totals']['total'] ?? null)
                @if (is_int($total))
                    <div><dt>{{ __('Document total') }}</dt><dd class="font-semibold">{{ Money::format($total, app()->getLocale()) }}</dd></div>
                @endif
            </dl>
        </x-filament::section>
    @else
    <x-filament::section :heading="__('Original invoice or invoice receipt')">
        @if (! $hasConnection)
            <p class="text-sm">{{ __('Restore the original InvoiceXpress connection to look up the attempts.') }}</p>
        @elseif ($itemIds === [])
            <p class="text-sm">{{ __('Configure the approved items and series in Integrations.') }}</p>
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
        @if (! $hasConnection || $itemIds === [])
            <x-filament::link :href="IntegrationsPage::getUrl()">{{ __('Integrations') }}</x-filament::link>
        @endif
        @if ($draftOnly)
            <p class="mt-3 text-sm">{{ __('INVOICEXPRESSDEMO series · Drafts only') }}</p>
        @endif
    </x-filament::section>

    @if ($originalData !== null)
        <x-filament::section :heading="__('Note details')">
            <form wire:submit="preview" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="ix-note-kind" class="text-sm font-medium">{{ __('Note type') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-kind" wire:model="kind">
                                <option value="credit_note">{{ __('Credit note') }}</option>
                                @if ($originalData['allowsDebit'] ?? true)
                                    <option value="debit_note">{{ __('Debit note') }}</option>
                                @endif
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-line" class="text-sm font-medium">{{ __('Original line') }}</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-line" wire:model="line" required>
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
                            <x-filament::input.select id="ix-note-item" wire:model="itemId" required>
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
                            <x-filament::input id="ix-note-quantity" type="number" wire:model="quantity" min="1" max="1000" step="1" required />
                        </x-filament::input.wrapper>
                    </div>
                </div>
                <div class="space-y-1">
                    <label for="ix-note-reason" class="text-sm font-medium">{{ __('Reason for correction') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-note-reason" wire:model="reason" maxlength="255" required />
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
    <div
        wire:key="ix-note-review-{{ md5(serialize($reviewedInputs)) }}"
        x-data="{
            reviewedInputs: @js($reviewedInputs),
            get reviewIsCurrent() {
                return Object.entries(this.reviewedInputs).every(([field, value]) => this.$wire[field] === value)
            },
        }"
        x-show="reviewIsCurrent"
        x-bind:inert="!reviewIsCurrent"
    >
        <x-filament::section :heading="__('Review draft')">
            <form wire:submit="saveDraft" class="space-y-4">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt>{{ __('Type and original invoice') }}</dt><dd class="font-semibold">{{ $previewData['input']['kind'] === 'credit_note' ? __('Credit note') : __('Debit note') }} · {{ $previewData['number'] }}</dd></div>
                    <div><dt>{{ __('Client') }}</dt><dd class="font-semibold">{{ $previewData['customer']['name'] }} · {{ __('Tax ID :number', ['number' => $previewData['customer']['nif']]) }}</dd></div>
                    <div><dt>{{ __('Line and quantity') }}</dt><dd class="font-semibold">{{ $previewData['input']['line'] }} · {{ $previewData['line'] }} × {{ $previewData['input']['quantity'] }}</dd></div>
                    <div><dt>{{ __('Series and date') }}</dt><dd class="font-semibold">{{ $previewData['sequence'] }} · {{ $previewData['date'] }}</dd></div>
                    <div><dt>{{ __('Net amount, VAT and total') }}</dt><dd class="font-semibold">{{ Money::format($previewData['totals']['net'], app()->getLocale()) }} + {{ Money::format($previewData['totals']['tax'], app()->getLocale()) }} = {{ Money::format($previewData['totals']['total'], app()->getLocale()) }}</dd></div>
                    @if ($previewData['exemptionReason'] ?? null)
                        <div><dt>{{ __('Reason for VAT exemption') }}</dt><dd class="font-semibold">{{ $previewData['exemptionReason'] }}</dd></div>
                    @endif
                    @if ($previewData['withholding'] ?? null)
                        <div><dt>{{ __('Amount withheld') }}</dt><dd class="font-semibold">{{ Money::format($previewData['withholding']['retained'], app()->getLocale()) }}</dd></div>
                        <div><dt>{{ __('Total after withholding') }}</dt><dd class="font-semibold">{{ Money::format($previewData['withholding']['cash'], app()->getLocale()) }}</dd></div>
                    @endif
                    <div><dt>{{ __('Available on this line for this note type') }}</dt><dd class="font-semibold">{{ __(':quantity pc.', ['quantity' => $previewData['remaining']['quantity']]) }} · {{ Money::format($previewData['remaining']['total'], app()->getLocale()) }}</dd></div>
                    <div><dt>{{ __('Reason') }}</dt><dd class="font-semibold">{{ $previewData['input']['reason'] }}</dd></div>
                </dl>
                <x-filament::button type="submit" wire:loading.attr="disabled">{{ __('Save draft') }}</x-filament::button>
            </form>
        </x-filament::section>
    </div>
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
                </li>
            @empty
                <li class="text-sm">{{ __('No note issuing attempts.') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
    @endif
</x-filament-panels::page>
