<x-filament-panels::page>
    @unless ($canWrite)
        <x-filament::section>
            A emissão de recibos está desativada para esta conta InvoiceXpress.
        </x-filament::section>
    @endunless

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Para um recibo já emitido com retenção na fonte, usa Conciliar retenção na afetação correspondente.
    </p>

    @error('receipt')
        <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
    @enderror

    @if ($previewData !== null)
        <x-filament::section>
            <x-slot name="heading">Confirmar emissão do recibo</x-slot>

            <form wire:submit="confirm" class="space-y-4">
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Fatura original</dt>
                        <dd>{{ $previewData['invoice'] }} · {{ $previewData['charge'] }}</dd>
                        <dd class="text-sm text-gray-500 dark:text-gray-400">Série de recibos {{ $previewData['series'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Recebimento</dt>
                        <dd>#{{ $previewData['paymentId'] }} · Transferência</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Data do recebimento</dt>
                        <dd>{{ $previewData['paidOn'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm text-gray-500 dark:text-gray-400">Montante a emitir</dt>
                        <dd class="font-semibold">{{ \App\Support\Money::format($previewData['amount']) }}</dd>
                    </div>
                </dl>

                <label class="flex items-center gap-2 text-sm">
                    <x-filament::input.checkbox wire:model="accepted" />
                    Confirmo a emissão deste recibo para a afetação indicada.
                </label>
                @error('accepted')
                    <p role="alert" class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror

                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Emitir recibo
                </x-filament::button>
            </form>
        </x-filament::section>
    @endif

    {{ $this->table }}

    @if ($attempts->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">Histórico de emissão</x-slot>

            <ul class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($attempts as $attempt)
                    <li wire:key="receipt-attempt-{{ $attempt->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div>
                            <p class="text-sm font-medium">
                                #{{ $attempt->id }} · Fatura {{ $attempt->intent['local']['invoiceNumber'] }}
                                · {{ \App\Support\Money::format($attempt->intent['local']['amount']) }}
                            </p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                @if ($attempt->provider_receipt_id !== null)
                                    Recibo {{ $attempt->receipt_number }} · ATCUD {{ $attempt->receipt_atcud }}
                                    @if ($attempt->fiscal_document_id === null)
                                        · Registo local removido
                                    @endif
                                @else
                                    Por verificar · {{ $attempt->note }}
                                @endif
                            </p>
                        </div>

                        @if ($attempt->provider_receipt_id === null)
                            <x-filament::button color="gray" size="sm" wire:click="recover({{ $attempt->id }})" wire:loading.attr="disabled">
                                Verificar no InvoiceXpress
                            </x-filament::button>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $attempts->links() }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
