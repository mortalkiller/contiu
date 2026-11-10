@php
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Support\Money;
    use App\Support\Period;

    $balance = $review->confirmedBalance();
    $periodLabel = $months === 3
        ? $review->start->quarter . '.º trimestre de ' . $year
        : Period::MONTHS[$month - 1] . ' de ' . $year;
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary, 'notice' => $summaryNotice])

    <x-filament::section :heading="$periodLabel">
        <div class="grid gap-4 sm:grid-cols-3">
            <div class="space-y-1">
                <label for="vat-year" class="text-sm font-medium">Ano</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="vat-year" type="number" min="1900" max="9998" wire:model.live.change="year" />
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-1">
                <label for="vat-months" class="text-sm font-medium">Periodicidade</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="vat-months" wire:model.live="months">
                        <option value="1">Mensal</option>
                        <option value="3">Trimestral</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div class="space-y-1">
                <label for="vat-month" class="text-sm font-medium">{{ $months === 3 ? 'Trimestre' : 'Mês' }}</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="vat-month" wire:model.live="month">
                        @if ($months === 3)
                            @foreach ([1, 4, 7, 10] as $startMonth)
                                <option value="{{ $startMonth }}">{{ intdiv($startMonth - 1, 3) + 1 }}.º trimestre</option>
                            @endforeach
                        @else
                            @foreach (Period::MONTHS as $index => $label)
                                <option value="{{ $index + 1 }}">{{ $label }}</option>
                            @endforeach
                        @endif
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="IVA inscrito nos documentos">
        <dl class="grid gap-4 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-600 dark:text-gray-300">Documentos emitidos</dt>
                <dd class="text-lg font-semibold">{{ Money::format($review->issuedVat) }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-600 dark:text-gray-300">Documentos recebidos</dt>
                <dd class="text-lg font-semibold">{{ Money::format($review->receivedVat) }}</dd>
            </div>
        </dl>
        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
            @if ($review->documents->isEmpty())
                Sem documentos neste período.
            @else
                A soma usa o IVA inscrito em faturas e faturas-recibo em EUR. A exigibilidade, a dedução e as regularizações são conferidas no apuramento.
            @endif
        </p>
    </x-filament::section>

    @foreach (['supported' => 'Incluídos na soma documental', 'unresolved' => 'Por conferir', 'excluded' => 'Fora da soma documental'] as $status => $label)
        @php($rows = $review->documents->where('status', $status))
        @if ($rows->isNotEmpty())
            <x-filament::section :heading="$label">
                <ul class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($rows as ['document' => $document, 'reason' => $reason])
                        <li wire:key="vat-document-{{ $document->id }}" class="flex flex-wrap justify-between gap-x-4 gap-y-1 py-2 text-sm">
                            <div>
                                <a class="font-semibold underline" href="{{ FiscalDocumentResource::getUrl('index', ['tableSearch' => $document->number]) }}">{{ $document->number }}</a>
                                <span>{{ $document->type->getLabel() }} · {{ $document->direction->getLabel() }} · {{ $document->document_date->format('d/m/Y') }}</span>
                            </div>
                            <span>{{ $reason }} @if ($document->currency === 'EUR') · {{ Money::format($document->vat_amount) }} @endif</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endforeach

    <x-filament::section heading="Apuramento do período">
        <div class="space-y-4">
            @if ($balance === null)
                <x-filament::badge color="warning">{{ $review->confirmation === null ? 'Por confirmar' : 'Documentos alterados · requer nova confirmação' }}</x-filament::badge>
            @else
                <x-filament::badge color="success">Confirmado</x-filament::badge>
                <p class="text-lg font-semibold">{{ $balance < 0 ? 'Saldo a favor' : ($balance === 0 ? 'Sem valor a pagar' : 'A pagar') }} · {{ Money::format(abs($balance)) }}</p>
                <p class="text-sm">
                    {{ $review->confirmation->reference }}
                    @if ($review->dueOn()) · Data-limite indicada {{ $review->dueOn()->format('d/m/Y') }} @endif
                    @if ($review->isPaid()) · Pago em {{ $review->paidOn()->format('d/m/Y') }} @endif
                </p>
                @if (! $this::canConfirm())
                    <dl class="grid gap-4 text-sm sm:grid-cols-3">
                        <div><dt>IVA liquidado</dt><dd class="font-semibold">{{ Money::format($review->confirmation->liquidated) }}</dd></div>
                        <div><dt>IVA dedutível</dt><dd class="font-semibold">{{ Money::format($review->confirmation->deductible) }}</dd></div>
                        <div><dt>Ajustes líquidos</dt><dd class="font-semibold">{{ Money::format($review->confirmation->adjustments) }}</dd></div>
                    </dl>
                @endif
            @endif

            @if ($this::canConfirm())
                <form wire:submit="confirm" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        @foreach (['liquidated' => 'IVA liquidado', 'deductible' => 'IVA dedutível', 'adjustments' => 'Ajustes líquidos (+/−)'] as $field => $label)
                            <div class="space-y-1">
                                <label for="vat-{{ $field }}" class="text-sm font-medium">{{ $label }}</label>
                                <x-filament::input.wrapper suffix="€">
                                    <x-filament::input id="vat-{{ $field }}" type="text" inputmode="decimal" wire:model="{{ $field }}" required />
                                </x-filament::input.wrapper>
                                @error($field) <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Nos ajustes, usa um valor positivo se aumentar o montante a pagar e um valor negativo se o reduzir. Inclui o crédito anterior utilizado, quando aplicável.</p>
                    <div class="space-y-1">
                        <label for="vat-reference" class="text-sm font-medium">Referência do apuramento revisto</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="vat-reference" type="text" wire:model="reference" maxlength="500" required />
                        </x-filament::input.wrapper>
                        @error('reference') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        @foreach (['dueOn' => 'Data-limite confirmada', 'paidOn' => 'Data de pagamento'] as $field => $label)
                            <div class="space-y-1">
                                <label for="vat-{{ $field }}" class="text-sm font-medium">{{ $label }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="vat-{{ $field }}" type="date" wire:model="{{ $field }}" />
                                </x-filament::input.wrapper>
                                @error($field) <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-filament::button type="submit" wire:loading.attr="disabled">Confirmar apuramento</x-filament::button>
                        <x-filament::button type="button" color="gray" wire:click="reloadReview" wire:loading.attr="disabled">Atualizar dados</x-filament::button>
                    </div>
                </form>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
