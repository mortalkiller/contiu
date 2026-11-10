@php
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Support\Money;
    use App\Support\WithholdingReview;
@endphp

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary])

    <x-filament::section heading="Retenção de IRS documentada">
        <div class="space-y-4">
            @if ($allocation)
                <dl class="grid gap-3 text-sm sm:grid-cols-3">
                    <div><dt>Cobrança</dt><dd class="font-semibold">{{ $allocation->receivable->label }}</dd></div>
                    <div><dt>Caixa afeto</dt><dd class="font-semibold">{{ Money::format($allocation->amount) }}</dd></div>
                    <div><dt>Data do recebimento</dt><dd class="font-semibold">{{ $allocation->payment->paid_on->format('d/m/Y') }}</dd></div>
                </dl>
            @else
                <div class="max-w-40 space-y-1">
                    <label for="withholding-year" class="text-sm font-medium">Ano do recebimento</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="withholding-year" type="number" min="1900" max="9998" wire:model.live.change="year" />
                    </x-filament::input.wrapper>
                </div>
            @endif

            <dl>
                <dt class="text-sm text-gray-600 dark:text-gray-300">Total retido com fonte conferida</dt>
                <dd class="text-lg font-semibold">{{ $review->retainedTotal === null ? 'Sem valores confirmados' : Money::format($review->retainedTotal) }}</dd>
            </dl>
        </div>
    </x-filament::section>

    @if ($this::canRecord())
        <x-filament::section :heading="$allocation ? 'Conciliar retenção com um recibo emitido' : 'Registar a partir de um documento'">
            @if ($sources->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $allocation
                        ? 'Sem recibos elegíveis para esta afetação. Confere se a fatura está ligada à cobrança e se o recibo está registado com o total antes da retenção.'
                        : 'Sem recibos ou faturas-recibo emitidos em EUR e ligados a recebimentos deste ano.' }}
                </p>
            @else
                <form wire:submit="record" class="space-y-4">
                    @if ($allocation)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            Usa o valor de IRS indicado no recibo. O bruto menos a retenção tem de coincidir com o caixa afeto.
                        </p>
                    @endif
                    <div class="space-y-1">
                        <label for="withholding-document" class="text-sm font-medium">Recibo ou fatura-recibo</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="withholding-document" wire:model.live="documentId" required>
                                <option value="">Escolher documento</option>
                                @foreach ($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->number }} · {{ $source->document_date->format('d/m/Y') }}{{ $source->party_name ? ' · '.$source->party_name : '' }} · {{ Money::format($source->total_amount) }} antes de retenção</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error('documentId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    @if ($selected)
                        @php
                            $payment = $allocation?->payment ?? $selected->links->payment;
                            $bankCredit = WithholdingReview::bankCredit($payment);
                        @endphp
                        <dl class="grid gap-3 text-sm sm:grid-cols-3">
                            <div><dt>Total antes da retenção</dt><dd class="font-semibold">{{ Money::format($selected->total_amount) }}</dd></div>
                            <div><dt>{{ $allocation ? 'Caixa afeto' : 'Recebimento registado' }}</dt><dd class="font-semibold">{{ Money::format($allocation?->amount ?? $payment->amount) }}</dd></div>
                            <div><dt>Crédito bancário do recebimento</dt><dd class="font-semibold">{{ $bankCredit === null ? 'Sem correspondência individual' : Money::format($bankCredit) }}</dd></div>
                        </dl>
                    @endif
                    <div class="space-y-1">
                        <label for="withholding-retained" class="text-sm font-medium">IRS retido indicado no documento</label>
                        <x-filament::input.wrapper suffix="€">
                            <x-filament::input id="withholding-retained" type="text" inputmode="decimal" wire:model="retained" required />
                        </x-filament::input.wrapper>
                        @error('retained') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    @if ($allocation)
                        <label class="flex items-center gap-2 text-sm">
                            <x-filament::input.checkbox wire:model="accepted" />
                            Confirmei que o recibo e a retenção correspondem a esta afetação.
                        </label>
                        @error('accepted') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @endif
                    <x-filament::button type="submit" wire:loading.attr="disabled">
                        {{ $allocation ? 'Confirmar ligação e retenção' : 'Registar retenção' }}
                    </x-filament::button>
                </form>
            @endif
        </x-filament::section>
    @endif

    <x-filament::section heading="Retenções registadas">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($review->rows as $row)
                @php($record = $row['record'])
                <li wire:key="withholding-record-{{ $record->id }}" class="space-y-2 py-3 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <a class="font-semibold underline" href="{{ FiscalDocumentResource::getUrl('index', ['tableSearch' => $record->document->number]) }}">{{ $record->document->number }}</a>
                        <span>{{ $record->paid_on->format('d/m/Y') }}</span>
                        @if (! $row['current'])
                            <x-filament::badge color="warning">Fonte alterada · por conferir</x-filament::badge>
                        @elseif ($record->payment_allocation_id === null)
                            <x-filament::badge color="gray">Registo fiscal · sem afetação confirmada</x-filament::badge>
                        @elseif (! $record->settles_receivable)
                            <x-filament::badge color="gray">Cobrança já pelo líquido</x-filament::badge>
                        @endif
                    </div>
                    <dl class="grid gap-3 sm:grid-cols-4">
                        <div><dt>Total antes da retenção</dt><dd class="font-semibold">{{ Money::format($record->gross_amount) }}</dd></div>
                        <div><dt>IRS retido registado</dt><dd class="font-semibold">{{ Money::format($record->retained_amount) }}</dd></div>
                        <div><dt>{{ $record->payment_allocation_id ? 'Caixa afeto' : 'Recebimento registado' }}</dt><dd class="font-semibold">{{ Money::format($record->payment_allocation_id ? $row['cashAmount'] : $row['paymentAmount']) }}</dd></div>
                        <div><dt>Crédito bancário do recebimento</dt><dd class="font-semibold">{{ $row['bankCredit'] === null ? 'Sem correspondência individual' : Money::format($row['bankCredit']) }}</dd></div>
                    </dl>
                </li>
            @empty
                <li class="text-sm text-gray-600 dark:text-gray-300">Sem retenções registadas para este ano.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
