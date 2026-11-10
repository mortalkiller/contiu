@php
    use App\Filament\Pages\InvoiceXpressImport;
    use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
    use App\Models\InvoiceXpressIssueAttempt;
    use App\Support\InvoiceXpressIssue;
    use App\Support\InvoiceXpressIssueAdvance;
    use App\Support\Money;
    use ToneGabes\Filament\Icons\Enums\Phosphor;

    use function Filament\Support\generate_icon_html;
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3">
        @if ($hasConnection && $options !== null)
            <x-filament::button type="button" color="gray" wire:click="loadCatalog" wire:loading.attr="disabled">
                {{ $catalogChoices === null ? 'Consultar catálogo do InvoiceXpress' : 'Atualizar catálogo' }}
            </x-filament::button>
            @isset($catalogChoices['sequences'][$options['sequenceId']])
                <span class="text-sm">Série {{ $catalogChoices['sequences'][$options['sequenceId']] }}</span>
            @endisset
        @endif
        <x-filament::link :href="\App\Filament\Pages\InvoiceXpressSettingsPage::getUrl()">
            Configuração da emissão
        </x-filament::link>
    </div>
    @error('catalog') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @if (! $hasConnection)
        <p class="text-sm">Repõe a ligação original ao InvoiceXpress para retomar uma tentativa pendente.</p>
    @elseif (! $canWriteDocuments)
        <p class="text-sm">A emissão aguarda a validação de que a API preserva o catálogo do InvoiceXpress.</p>
    @endif

    @if ($options !== null)
    <section class="contiu-steps contiu-edge-to-edge" aria-label="Passos da emissão">
        <ol class="contiu-steps-list" style="--contiu-steps: {{ count($steps) }}">
            @foreach ($steps as $index => $step)
                <li>
                    <div class="contiu-step" data-done="{{ $step['done'] ? 'true' : 'false' }}">
                        <span class="contiu-step-title">{{ $index + 1 }}. {{ $step['label'] }}</span>
                        <span class="contiu-step-state">
                            {{ generate_icon_html($step['done'] ? Phosphor::CheckCircle : Phosphor::Clock) }}
                            {{ $step['state'] }}
                        </span>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    <x-filament::section heading="Dados do documento">
        <form wire:submit="preview" class="space-y-4">
            <div class="space-y-1">
                <label for="ix-document-type" class="text-sm font-medium">Tipo de documento</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ix-document-type" wire:model.live="documentType">
                        @foreach (InvoiceXpressIssue::DocumentTypes as $type => $label)
                            <option value="{{ $type }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('documentType') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                @error('payment') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="space-y-1">
                <label for="ix-exemption-reason" class="text-sm font-medium">Motivo de isenção de IVA</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="ix-exemption-reason" wire:model.live="exemptionReason">
                        <option value="">Sem isenção</option>
                        <option value="M10">Regime de isenção do artigo 53.º (M10)</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('exemptionReason') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="ix-receivable" class="text-sm font-medium">Valor a receber</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-receivable" wire:model.live="receivableId" required>
                            <option value="">Escolher valor a receber</option>
                            @foreach ($receivables as $receivable)
                                <option value="{{ $receivable->id }}">{{ $receivable->label }} · {{ $receivable->client?->name }} · {{ Money::format($receivable->amount) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('receivableId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('receivable') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-client" class="text-sm font-medium">Cliente existente no InvoiceXpress</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-client" wire:model.live="customerId" required>
                            <option value="">Escolher cliente</option>
                            @foreach ($catalogChoices['clients'] ?? [] as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('customerId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('customer') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-item" class="text-sm font-medium">Artigo aprovado</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="ix-item" wire:model.live="itemId" required>
                            <option value="">Escolher artigo</option>
                            @foreach (array_intersect_key($catalogChoices['items'] ?? [], array_flip($options['itemIds'])) as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                    @error('itemId') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('item') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    @error('tax') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="space-y-1">
                    <label for="ix-quantity" class="text-sm font-medium">Quantidade</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="ix-quantity" type="number" wire:model.live.change="quantity" min="1" max="1000" step="1" required />
                    </x-filament::input.wrapper>
                    @error('quantity') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            @foreach ($additionalLines as $index => $line)
                <fieldset wire:key="ix-line-{{ $index }}" class="grid gap-3 sm:grid-cols-3">
                    <legend class="text-sm font-medium">Linha {{ $loop->iteration + 1 }}</legend>
                    <div class="space-y-1">
                        <label for="ix-item-{{ $index }}" class="text-sm font-medium">Artigo aprovado</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="ix-item-{{ $index }}" wire:model.live="additionalLines.{{ $index }}.itemId" required>
                                <option value="">Escolher artigo</option>
                                @foreach (array_intersect_key($catalogChoices['items'] ?? [], array_flip($options['itemIds'])) as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @error("additionalLines.$index.itemId") <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="space-y-1">
                        <label for="ix-quantity-{{ $index }}" class="text-sm font-medium">Quantidade</label>
                        <x-filament::input.wrapper>
                            <x-filament::input id="ix-quantity-{{ $index }}" type="number" wire:model.live.change="additionalLines.{{ $index }}.quantity" min="1" max="1000" step="1" required />
                        </x-filament::input.wrapper>
                        @error("additionalLines.$index.quantity") <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="self-end">
                        <x-filament::button type="button" color="gray" wire:click="removeLine({{ (int) $index }})" wire:loading.attr="disabled">Remover linha</x-filament::button>
                    </div>
                </fieldset>
            @endforeach
            @error('additionalLines') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
            <div class="flex flex-wrap gap-2">
                <x-filament::button type="button" color="gray" :disabled="$catalogChoices === null || count($additionalLines) >= \App\Support\InvoiceXpressIssue::MaxLines - 1" wire:click="addLine" wire:loading.attr="disabled">Adicionar linha</x-filament::button>
                <x-filament::button type="submit" :disabled="$catalogChoices === null" wire:loading.attr="disabled">Pré-visualizar {{ mb_strtolower(InvoiceXpressIssue::DocumentTypes[$documentType] ?? 'documento') }}</x-filament::button>
            </div>
        </form>
    </x-filament::section>
    @endif

    @error('provider') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('confirmation') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    @error('sequence') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

    @error('advanceSettlement') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror

    @if (isset($previewData['advanceSettlement']))
        @php($settlement = $previewData['advanceSettlement'])
        <x-filament::section heading="Pré-visualizar acerto de adiantamento">
            <div class="space-y-4 text-sm">
                <p>{{ $previewData['customer']['name'] }} · NIF {{ $previewData['customer']['nif'] }}</p>
                <dl class="space-y-3">
                    <div>
                        <dt class="font-semibold">Valor integral do serviço</dt>
                        <dd>{{ Money::format($previewData['totals']['net']) }} + {{ Money::format($previewData['totals']['tax']) }} IVA = {{ Money::format($previewData['totals']['total']) }}</dd>
                        <ul>
                            @foreach ($previewData['lines'] as $line)
                                <li>{{ $line['item']['name'] }} · {{ $line['quantity'] }} × {{ Money::format($line['item']['unitCents']) }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        <dt class="font-semibold">Abatimento do adiantamento</dt>
                        <dd>Fatura original {{ $settlement['reference'] }} de {{ $settlement['date'] }}</dd>
                        <dd>−{{ Money::format($settlement['deduction']['net']) }} de base e −{{ Money::format($settlement['deduction']['tax']) }} de IVA · −{{ Money::format($settlement['deduction']['total']) }} no total</dd>
                    </div>
                    <div>
                        <dt class="font-semibold">Total da fatura final</dt>
                        <dd>{{ Money::format($settlement['balance']['net']) }} + {{ Money::format($settlement['balance']['tax']) }} IVA = {{ Money::format($settlement['balance']['total']) }}</dd>
                    </div>
                </dl>
                <p role="status">{{ InvoiceXpressIssueAdvance::ProviderBlock }}</p>
                <p>Depois de emitir por um meio confirmado com o fornecedor, verifica o abatimento e a referência na fatura final. Importa ou regista a fatura, associa-a à mesma cobrança e usa «Ligar adiantamento».</p>
                <div class="flex flex-wrap gap-3">
                    <x-filament::link :href="InvoiceXpressImport::getUrl()">Importar fatura final</x-filament::link>
                    <x-filament::link :href="FiscalDocumentResource::getUrl()">Documentos fiscais</x-filament::link>
                </div>
            </div>
        </x-filament::section>
    @elseif ($previewData !== null)
        @include('filament.pages.partials.tax-summary', ['stats' => [], 'notice' => [
            'title' => 'Depois de emitida, uma fatura só se corrige com nota de crédito.',
            'body' => 'Confirma o cliente, as linhas e os totais antes de emitires.',
            'tone' => 'warning',
        ]])

        <x-filament::section heading="Confirmar emissão">
            <form wire:submit="confirm" class="space-y-4">
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt>Cliente no InvoiceXpress</dt>
                        <dd class="font-semibold">{{ $previewData['customer']['name'] }} · NIF {{ $previewData['customer']['nif'] }}</dd>
                    </div>
                    <div>
                        <dt>Emissão e vencimento</dt>
                        <dd class="font-semibold">{{ $previewData['invoice']['date'] }} · {{ $previewData['invoice']['due_date'] }}</dd>
                    </div>
                    <div>
                        <dt>Tipo de documento</dt>
                        <dd class="font-semibold">{{ InvoiceXpressIssue::DocumentTypes[$previewData['documentType'] ?? 'invoice'] }}</dd>
                    </div>
                    @isset($previewData['payment'])
                        <div>
                            <dt>Recebimento registado</dt>
                            <dd class="font-semibold">#{{ $previewData['payment']['id'] }} · {{ $previewData['invoice']['date'] }} · {{ Money::format($previewData['payment']['amount']) }}</dd>
                        </div>
                    @endisset
                    <div>
                        <dt>Série e referência interna</dt>
                        <dd class="font-semibold">{{ $previewData['sequence']['name'] }} · {{ $previewData['invoice']['reference'] }}</dd>
                    </div>
                    <div>
                        <dt>Base e IVA</dt>
                        <dd class="font-semibold">{{ Money::format($previewData['totals']['net']) }} + {{ Money::format($previewData['totals']['tax']) }} · {{ $previewData['tax']['name'] }} ({{ intdiv($previewData['tax']['basisPoints'], 100) }}%)</dd>
                        @isset($previewData['invoice']['tax_exemption_reason'])
                            <dd>Regime de isenção do artigo 53.º ({{ $previewData['invoice']['tax_exemption_reason'] }})</dd>
                        @endisset
                    </div>
                    <div>
                        <dt>Total do documento</dt>
                        <dd class="font-semibold">{{ Money::format($previewData['totals']['total']) }}</dd>
                    </div>
                </dl>
                <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                    @foreach ($previewData['lines'] ?? [['item' => $previewData['item'], 'quantity' => $previewData['invoice']['items'][0]['quantity'], 'totals' => $previewData['totals']]] as $line)
                        <li class="py-2">
                            <span class="font-semibold">{{ $line['item']['name'] }}</span> · {{ $line['quantity'] }} × {{ Money::format($line['item']['unitCents']) }} · {{ Money::format($line['totals']['net']) }} + {{ Money::format($line['totals']['tax']) }} IVA = {{ Money::format($line['totals']['total']) }}
                            @if ($line['item']['description'] !== '') <p>{{ $line['item']['description'] }}</p> @endif
                        </li>
                    @endforeach
                </ul>
                <label for="ix-accept" class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox id="ix-accept" wire:model="accepted" />
                    Confirmei estes dados e autorizo a emissão deste documento no InvoiceXpress.
                </label>
                @error('accepted') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                <x-filament::button type="submit" :disabled="! $canWriteDocuments" wire:loading.attr="disabled">Confirmar e emitir</x-filament::button>
            </form>
        </x-filament::section>
    @endif

    <x-filament::section heading="Tentativas de emissão">
        <ul class="divide-y divide-gray-200 dark:divide-white/10">
            @forelse ($attempts as $attempt)
                <li wire:key="ix-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                    <div>
                        <span class="font-semibold">Tentativa #{{ $attempt->id }}</span>
                        <span> · {{ $attempt->created_at->format('d/m/Y H:i') }}</span>
                        <span> · {{ InvoiceXpressIssue::DocumentTypes[$attempt->snapshot['documentType'] ?? 'invoice'] }} · Valor a receber #{{ $attempt->snapshot['receivableId'] }}</span>
                        @if ($attempt->state === InvoiceXpressIssueAttempt::Finalized)
                            <p>{{ $attempt->document_reference }} · ID {{ $attempt->provider_invoice_id }} · ATCUD {{ $attempt->atcud }}</p>
                        @elseif ($attempt->state === InvoiceXpressIssueAttempt::Blocked)
                            <p>Revisão manual necessária no InvoiceXpress.</p>
                        @else
                            <p>Por confirmar no InvoiceXpress.</p>
                        @endif
                    </div>
                    @if (in_array($attempt->state, InvoiceXpressIssueAttempt::Recoverable, true))
                        <x-filament::button type="button" color="gray" wire:click="recover({{ $attempt->id }})" wire:loading.attr="disabled">
                            Verificar e retomar tentativa
                        </x-filament::button>
                    @endif
                </li>
            @empty
                <li class="text-sm text-gray-600 dark:text-gray-300">Sem tentativas de emissão.</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
