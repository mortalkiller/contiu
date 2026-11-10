@use(App\Filament\Pages\SocialSecurity)
@use(App\Support\CurrentAccount)
@use(App\Support\Money)

<x-filament-panels::page>
    @include('filament.pages.partials.tax-summary', ['stats' => $summary])

    <form method="GET" action="{{ SocialSecurity::getUrl() }}" class="flex flex-wrap items-end gap-3">
        <div class="space-y-1.5">
            <label for="ss-quarter" class="block text-sm font-medium">Trimestre encerrado</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="ss-quarter" name="quarter">
                    @foreach ($quarters as $option)
                        <option value="{{ $option }}" @selected($option === $this->quarter)>{{ SocialSecurity::quarterLabel($option) }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <x-filament::button type="submit" color="gray">Ver trimestre</x-filament::button>
    </form>

    <x-filament::section heading="Recebimentos registados">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Valores registados no Contiu pela data de pagamento, incluindo os marcados como isentos. Usa-os como referência ao conferir os rendimentos sem IVA na Segurança Social Direta.
        </p>

        <dl class="my-4 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm">Total registado</dt>
                <dd class="font-semibold tabular-nums">{{ Money::format($receipts->sum('amount')) }}</dd>
            </div>
            <div>
                <dt class="text-sm">Recebimentos usados na estimativa</dt>
                <dd class="font-semibold tabular-nums">{{ Money::format($estimateIncome) }}</dd>
            </div>
        </dl>

        @if ($receipts->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">Sem recebimentos registados neste trimestre.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-start">
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th scope="col" class="py-2 text-start font-medium">Data</th>
                            <th scope="col" class="px-3 py-2 text-start font-medium">Recebimento</th>
                            <th scope="col" class="py-2 text-end font-medium">Valor</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($receipts as $receipt)
                            <tr>
                                <td class="py-2 whitespace-nowrap">{{ $receipt->paid_on->format('d/m/Y') }}</td>
                                <td class="px-3 py-2">
                                    {{ $receipt->receivableLabels() ?: 'Recebimento #'.$receipt->id }}
                                    @if ($receipt->tax_exempt)
                                        <span class="text-xs text-gray-600 dark:text-gray-400"> · Marcado como isento no Contiu</span>
                                    @endif
                                </td>
                                <td class="py-2 text-end whitespace-nowrap tabular-nums">{{ Money::format($receipt->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Rendimentos declarados">
        <x-slot name="afterHeader">
            @if (! CurrentAccount::readOnly())
                {{ $this->recordDeclarationAction }}
            @endif
        </x-slot>

        @php
            $declaration = $declarations->first();
        @endphp
        @if ($declaration)
            <dl class="grid gap-3 sm:grid-cols-4">
                @foreach ($incomeMonths as $month)
                    <div>
                        <dt class="text-sm">{{ SocialSecurity::monthLabel($month) }}</dt>
                        <dd class="font-semibold tabular-nums">{{ Money::format($declaration->amounts[$month->format('Y-m')]) }}</dd>
                    </div>
                @endforeach
                <div>
                    <dt class="text-sm">Total declarado</dt>
                    <dd class="font-semibold tabular-nums">{{ Money::format(array_sum($declaration->amounts)) }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">Registo local de {{ $declaration->recorded_at->format('d/m/Y H:i') }}</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">Ainda não há valores declarados registados para este trimestre.</p>
        @endif

        @if ($declarations->count() > 1)
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer font-medium">Histórico da declaração</summary>
                <ul class="mt-2 space-y-2">
                    @foreach ($declarations->skip(1) as $revision)
                        <li>
                            {{ $revision->recorded_at->format('d/m/Y H:i') }}
                            @foreach ($incomeMonths as $month)
                                · {{ SocialSecurity::monthLabel($month) }} {{ Money::format($revision->amounts[$month->format('Y-m')]) }}
                            @endforeach
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </x-filament::section>

    <x-filament::section heading="Contribuições mensais">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Confirma o valor exato indicado em <strong>Valores a pagar</strong> na Segurança Social Direta. O pagamento é tratado em separado.
        </p>
        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
            A estimativa usa as definições fiscais atuais.
            @if ($incompleteSettings)
                Completa-as para melhorar a estimativa.
            @endif
        </p>

        <div class="mt-4 grid gap-3 lg:grid-cols-3">
            @foreach ($contributionMonths as $month)
                @php
                    $key = $month->format('Y-m');
                    $revisions = $contributionsByMonth->get($key, collect());
                    $confirmed = $revisions->first();
                @endphp

                <div class="space-y-3 rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div>
                        <h3 class="text-sm font-semibold">{{ SocialSecurity::monthLabel($month) }}</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Pagamento em {{ SocialSecurity::monthLabel($month->addMonth()) }}</p>
                    </div>
                    <dl class="space-y-1 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt>Estimativa</dt>
                            <dd class="tabular-nums">{{ Money::format($estimatedContributions[$key]) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt>Valor confirmado</dt>
                            <dd class="font-semibold tabular-nums">{{ $confirmed ? Money::format($confirmed->amount) : 'Por confirmar' }}</dd>
                        </div>
                    </dl>
                    @if (! CurrentAccount::readOnly())
                        {{ ($this->recordContributionAction)(['month' => $key]) }}
                    @endif
                    @if ($confirmed)
                        <p class="text-xs text-gray-600 dark:text-gray-400">Confirmado em {{ $confirmed->confirmed_at->format('d/m/Y H:i') }}</p>
                    @endif
                    @if ($revisions->count() > 1)
                        <details class="text-sm">
                            <summary class="cursor-pointer font-medium">Histórico da contribuição</summary>
                            <ul class="mt-2 space-y-1">
                                @foreach ($revisions->skip(1) as $revision)
                                    <li>{{ $revision->confirmed_at->format('d/m/Y H:i') }} · {{ Money::format($revision->amount) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
