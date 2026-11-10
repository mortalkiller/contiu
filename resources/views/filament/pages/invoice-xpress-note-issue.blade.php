@php
    use App\Models\InvoiceXpressNoteAttempt;
    use App\Support\Money;
@endphp

<x-filament-panels::page>
    <x-filament::section heading="Fatura original">
        @if (! $hasConnection)
            <p class="text-sm">Repõe a ligação original ao InvoiceXpress para consultar as tentativas.</p>
        @elseif ($itemIds === [])
            <p class="text-sm">Aprova os artigos e configura uma série na página de emissão de faturas.</p>
        @else
            <form wire:submit="loadOriginal" class="space-y-3">
                <label for="ix-note-original" class="text-sm font-medium">Fatura do InvoiceXpress</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ix-note-original" wire:model.live="originalId" required>
                        <option value="">Escolher fatura</option>
                        @foreach ($originals as $original)
                            <option value="{{ $original->id }}">{{ $original->number }} · {{ $original->party_name }} · {{ Money::format($original->total_amount) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                <x-filament::button type="submit" color="gray" wire:loading.attr="disabled">Consultar linhas da fatura</x-filament::button>
            </form>
        @endif
        @if ($hasConnection && ! $canWriteDocuments)
            <p class="mt-3 text-sm">A emissão aguarda a validação do catálogo do InvoiceXpress.</p>
        @endif
    </x-filament::section>

    @if ($originalData !== null)
        <x-filament::section heading="Dados da nota">
            <form wire:submit="preview" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="ix-note-kind" class="text-sm font-medium">Tipo de nota</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-kind" wire:model.live="kind">
                                <option value="credit_note">Nota de crédito</option>
                                <option value="debit_note">Nota de débito</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-line" class="text-sm font-medium">Linha original</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-line" wire:model.live="line" required>
                                <option value="">Escolher linha</option>
                                @foreach ($originalData['lines'] as $index => $originalLine)
                                    <option value="{{ $index + 1 }}">{{ $index + 1 }} · {{ $originalLine['name'] }} · {{ $originalLine['quantity'] }} un. · {{ Money::format($originalLine['total']) }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-item" class="text-sm font-medium">Identificador do artigo aprovado</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-note-item" wire:model.live="itemId" required>
                                <option value="">Escolher artigo</option>
                                @foreach ($itemIds as $id)
                                    <option value="{{ $id }}">#{{ $id }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                    <div class="space-y-1">
                        <label for="ix-note-quantity" class="text-sm font-medium">Quantidade a corrigir</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="ix-note-quantity" type="number" wire:model.live.change="quantity" min="1" max="1000" step="1" required />
                        </x-filament::input.wrapper>
                    </div>
                </div>
                <div class="space-y-1">
                    <label for="ix-note-reason" class="text-sm font-medium">Motivo da correção</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-note-reason" wire:model.live.change="reason" maxlength="255" required />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="submit" wire:loading.attr="disabled">Pré-visualizar nota</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    @foreach ($errors->all() as $error)
        <p class="text-sm text-danger-600" role="alert">{{ $error }}</p>
    @endforeach

    @if ($previewData !== null)
        <x-filament::section heading="Confirmar emissão">
            <form wire:submit="confirm" class="space-y-4">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt>Tipo e fatura original</dt><dd class="font-semibold">{{ $previewData['input']['kind'] === 'credit_note' ? 'Nota de crédito' : 'Nota de débito' }} · {{ $previewData['number'] }}</dd></div>
                    <div><dt>Cliente</dt><dd class="font-semibold">{{ $previewData['customer']['name'] }} · NIF {{ $previewData['customer']['nif'] }}</dd></div>
                    <div><dt>Linha e quantidade</dt><dd class="font-semibold">{{ $previewData['input']['line'] }} · {{ $previewData['line'] }} × {{ $previewData['input']['quantity'] }}</dd></div>
                    <div><dt>Série e data</dt><dd class="font-semibold">{{ $previewData['sequence'] }} · {{ $previewData['date'] }}</dd></div>
                    <div><dt>Base, IVA e total</dt><dd class="font-semibold">{{ Money::format($previewData['totals']['net']) }} + {{ Money::format($previewData['totals']['tax']) }} = {{ Money::format($previewData['totals']['total']) }}</dd></div>
                    <div><dt>Disponível na linha para este tipo de nota</dt><dd class="font-semibold">{{ $previewData['remaining']['quantity'] }} un. · {{ Money::format($previewData['remaining']['total']) }}</dd></div>
                    <div><dt>Motivo</dt><dd class="font-semibold">{{ $previewData['input']['reason'] }}</dd></div>
                </dl>
                <label for="ix-note-accepted" class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox id="ix-note-accepted" wire:model="accepted" />
                    Confirmei estes dados e autorizo a emissão da nota no InvoiceXpress.
                </label>
                <x-filament::button type="submit" :disabled="! $canWriteDocuments" wire:loading.attr="disabled">Confirmar e emitir nota</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section heading="Tentativas de emissão de notas">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($attempts as $attempt)
                <li wire:key="ix-note-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                    <div>
                        <span class="font-semibold">Tentativa #{{ $attempt->id }}</span>
                        <span> · Fatura {{ $attempt->snapshot['original']['number'] }}</span>
                        @if ($attempt->state === InvoiceXpressNoteAttempt::Finalized)
                            <p>{{ $attempt->document_reference }} · ATCUD {{ $attempt->atcud }}</p>
                        @elseif ($attempt->state === InvoiceXpressNoteAttempt::Blocked)
                            <p>Revisão manual necessária no InvoiceXpress.</p>
                        @else
                            <p>Por confirmar no InvoiceXpress.</p>
                        @endif
                    </div>
                    @if ($hasConnection && in_array($attempt->state, InvoiceXpressNoteAttempt::Recoverable, true))
                        <x-filament::button type="button" color="gray" wire:click="recover({{ $attempt->id }})" wire:loading.attr="disabled">
                            Verificar e retomar tentativa
                        </x-filament::button>
                    @endif
                </li>
            @empty
                <li class="text-sm">Sem tentativas de emissão de notas.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
