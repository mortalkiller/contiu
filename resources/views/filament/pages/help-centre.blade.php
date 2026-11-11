@php
    $links = $this->links();
    $icons = collect($this->topics())->pluck('icon', 'id');
    $subscription = $this->subscription();
    $bank = $this->hasBusinessAccount();
    $flowTitle = $bank ? __('From project to bank statement') : __('From project to payment');
    $link = fn (string $key, string $label) => '<a href="'.e($links[$key]).'" class="font-medium text-primary-600 underline decoration-primary-600/30 underline-offset-2 hover:decoration-primary-600 dark:text-primary-400">'.e($label).'</a>';
@endphp

<x-filament-panels::page>
    <div class="fi-help">
        <nav aria-label="{{ __('Topics') }}" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->topics() as $topic)
                <a href="#{{ $topic['id'] }}" class="flex items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-600/40 dark:bg-gray-900 dark:ring-white/10">
                    <x-filament::icon :icon="$topic['icon']" class="mt-0.5 size-5 shrink-0 text-primary-600 dark:text-primary-400" />
                    <span>
                        <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $topic['title'] }}</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $topic['summary'] }}</span>
                    </span>
                </a>
            @endforeach
        </nav>

        <div class="fi-help-body mt-8 space-y-6 text-sm leading-6 text-gray-700 dark:text-gray-300">
            {{-- Do trabalho ao extrato (ao recebimento, sem conta da atividade) --}}
            <x-filament::section class="scroll-mt-24" id="fluxo" :icon="$icons['fluxo']" :heading="$flowTitle" :description="__('Example: an online shop for Gonçalo Teixeira, €2,000, paid in two halves.')">
                <ol class="list-decimal space-y-4 ps-5">
                    <li>
                        {!! __('<strong>Create the project.</strong> :projectLink (or "+ New" → New project): client → project (code, description, category, amount, VAT, quote date) → "50% / 50%" payment plan → summary.', ['projectLink' => $link('newProject', __('New project'))]) !!}
                        {!! __('The app creates two <em>receivables</em> of €1,000, shown under "Receivables" on the dashboard and in the month close for each due date.') !!}
                    </li>
                    <li>
                        @if ($bank)
                            {!! __('<strong>The client pays €400 into the business account.</strong> Issue a €400 invoice-receipt on Portal das Finanças. In the app, choose one of two options:') !!}
                            <ul class="mt-2 list-disc space-y-1 ps-5">
                                <li>{!! __('record it now: dashboard → "Receivables" → <strong>Receive</strong> on the first instalment → date, €400, method and invoice-receipt number. The instalment becomes "Partial" (€600 outstanding);') !!}</li>
                                <li>{!! __('or wait for the bank statement: the line "TRF GONCALO TEIXEIRA 400,00" comes with the suggestion <strong>Partial payment</strong> — accept it, then add the invoice-receipt number under Payments received.') !!}</li>
                            </ul>
                            {{ __('If you already recorded it, the statement shows "Already recorded". Accept the suggestion and the payment stays recorded once.') }}
                        @else
                            {!! __('<strong>The client pays €400.</strong> Issue a €400 invoice-receipt on Portal das Finanças and record it: dashboard → "Receivables" → <strong>Receive</strong> on the first instalment → date the client paid, €400, method and invoice-receipt number. The instalment becomes "Partial" (€600 outstanding).') !!}
                        @endif
                    </li>
                    @if ($bank)
                    <li>
                        {!! __('<strong>The client pays €600 by MB Way into your personal account.</strong> Issue the invoice-receipt and click <strong>Receive</strong>, using the date the client paid. That date counts for income. The first instalment is paid.') !!}
                    </li>
                    <li>
                        {!! __('<strong>You transfer €900 from your personal account to your business account.</strong> There is nothing to do in the app yet.') !!}
                    </li>
                    @else
                    <li>
                        {!! __('<strong>The client pays €600 by MB Way.</strong> Issue the invoice-receipt and click <strong>Receive</strong>, using the date the client paid. The first instalment is paid.') !!}
                    </li>
                    @endif
                    <li>
                        {!! __('<strong>The client pays the final €1,000.</strong> Issue the invoice-receipt. Then :record', ['record' => e($bank ? __('record it now or wait for the statement.') : __('record it as before.'))]) !!}
                    </li>
                    @if ($bank)
                    <li>
                        {!! __('<strong>End of month: import the bank statement.</strong> :bankLink → Upload statement → choose the bank’s Excel or CSV file.', ['bankLink' => $link('bank', __('Bank statement'))]) !!}
                        <div class="mt-3 overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                    <tr><th class="px-3 py-2 font-medium">{{ __('Bank statement line') }}</th><th class="px-3 py-2 font-medium">{{ __('Suggestion') }}</th><th class="px-3 py-2 font-medium">{{ __('What to do') }}</th></tr>
                                </thead>
                                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                                    <tr><td class="px-3 py-2">TRF DE ANA SILVA … · +900 €</td><td class="px-3 py-2">{{ __('Own transfer') }}</td><td class="px-3 py-2">{{ __('nothing') }}</td></tr>
                                    <tr><td class="px-3 py-2">TRF GONCALO TEIXEIRA · +1 000 €</td><td class="px-3 py-2">{{ __('Payment · second instalment (or "Already recorded")') }}</td><td class="px-3 py-2">{{ __('nothing') }}</td></tr>
                                    <tr><td class="px-3 py-2">STRIPE TECHNOLOGY EUROPE · +4,32 €</td><td class="px-3 py-2">{{ __('no suggestion') }}</td><td class="px-3 py-2">{!! __('<strong>Create income</strong>: €5.00 gross, €0.68 fee') !!}</td></tr>
                                    <tr><td class="px-3 py-2">COMPRA OPENAI · −83,74 €</td><td class="px-3 py-2">{{ __('Recurring expense · ChatGPT Pro') }}</td><td class="px-3 py-2">{{ __('nothing') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3">{!! __('Click <strong>Accept suggestions</strong> (select the lines and use the bulk action) and manually process the remaining lines.') !!}</p>
                    </li>
                    @endif
                    <li>
                        {!! __('<strong>Month close.</strong> On the :dashboardLink, the "Month close" button shows what you still need to receive or pay that month until "Month closed" appears.', ['dashboardLink' => $link('dashboard', __('dashboard'))]) !!}
                    </li>
                </ol>
            </x-filament::section>

            {{-- Recebimentos --}}
            <x-filament::section class="scroll-mt-24" id="recebimentos" :icon="$icons['recebimentos']" :heading="__('Payments received')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{!! __('Each time you receive a payment, issue an <strong>invoice-receipt</strong> on Portal das Finanças and record it under "Receive" (dashboard, project, retainer, client or :paymentsLink). The amount defaults to the outstanding balance.', ['paymentsLink' => $link('payments', __('Payments received'))]) !!}</li>
                    <li>{!! __('<strong>Partial:</strong> record only what you received. The receivable stays "Partial" until it is paid in full. Each part has its own invoice-receipt.') !!}</li>
                    <li>{!! __('<strong>Date:</strong> always use the date the client paid, even if they paid into your personal account.') !!}</li>
                    <li>{!! __('<strong>Retainers</strong> (:retainerLink) automatically create a receivable for each period.', ['retainerLink' => $link('newRetainer', __('New retainer'))]) !!}</li>
                    <li>{!! __('<strong>Tax-exempt:</strong> comes from the retainer or your settings. Tax-exempt payments are excluded from tax estimates.') !!}</li>
                </ul>
            </x-filament::section>

            {{-- Despesas --}}
            <x-filament::section class="scroll-mt-24" id="despesas" :icon="$icons['despesas']" :heading="__('Expenses')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{!! __(':expenseLink: supplier, category, amount, VAT, date and invoice number. Always ask for an invoice with your NIF — it counts towards the 15% expense requirement under the simplified regime.', ['expenseLink' => $link('newExpense', __('New expense'))]) !!}</li>
                    <li>{!! __('<strong>Direct expenses</strong> (for a project or retainer) count towards margins. <strong>Overheads</strong> are general costs.') !!}</li>
                    <li>{!! __(':recurringLink (e.g. ChatGPT, OVH) are created automatically every month:bankClause.', ['recurringLink' => $link('recurring', __('Recurring expenses')), 'bankClause' => $bank ? e(__(' and recognised on the statement')) : '']) !!}</li>
                    @if ($bank)
                        <li>{{ __('Paid from your personal account? Record it as usual with the payment date. There is nothing to do on the statement.') }}</li>
                    @endif
                </ul>
            </x-filament::section>

            {{-- Receitas avulsas --}}
            <x-filament::section class="scroll-mt-24" id="receitas-avulsas" :icon="$icons['receitas-avulsas']" :heading="__('One-off income entries')" :description="__('Donations, refunds or other income without a project or retainer.')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{!! __('"+ New" → <strong>One-off income</strong>:bankClause.', ['bankClause' => $bank ? __(', or <strong>Create income</strong> from a bank statement line') : '']) !!}</li>
                    <li>{!! __('Enter the <strong>gross amount</strong> (what the supporter paid and the amount on the invoice-receipt) and the <strong>fee</strong> the platform kept. Example: Buy Me a Coffee → €5.00 gross, €0.68 Stripe fee, €4.32 received at the bank.') !!}</li>
                    <li>{{ __('The app records €5.00 in income and a €0.68 expense ("Fees").:bankClause', ['bankClause' => $bank ? __(' Together they account for the €4.32 line on the statement. In "Create income", enter the gross amount. The fee is calculated from what reached the bank.') : '']) }}</li>
                    <li>{{ __('It counts on the dashboard, in margins ("One-off income entries") and for taxes like any other income.') }}</li>
                </ul>
            </x-filament::section>

            @if ($bank)
            {{-- Extrato --}}
            <x-filament::section class="scroll-mt-24" id="extrato" :icon="$icons['extrato']" :heading="__('Bank statement')" :description="__('Only the business account. The aim is to account for every line.')">
                <ol class="list-decimal space-y-2 ps-5">
                    <li>{!! __('Export the transactions from your bank’s website (Excel or CSV) and upload them in :bankLink. Duplicate lines are skipped.', ['bankLink' => $link('bank', __('Bank statement'))]) !!}</li>
                    <li>{!! __('Each line has a suggestion. Select the lines and use <strong>Accept suggestions</strong>. Only reliable suggestions are accepted.') !!}</li>
                    <li>{{ __('For the remaining lines:') }}
                        <ul class="mt-1 list-disc space-y-1 ps-5">
                            <li>{!! __('<strong>Link…</strong> — select one or more records (e.g. two invoices paid in one transfer, or a payment minus an expense). If the total does not match, the app alerts you. Usually the difference did not go through this account and you can leave it in the <strong>Business account</strong> card. Two cases need a new record, which you can create here:') !!}
                                <ul class="mt-1 list-disc space-y-1 ps-5">
                                    <li>{!! __('<strong>Bank fee</strong> — you received less than the invoice amount (e.g. €995 of €1,000) or paid more than the expense amount. Create an expense for the difference.') !!}</li>
                                    <li>{!! __('<strong>Other income</strong> — you received more than the invoice amount. Create a one-off income entry for the difference.') !!}</li>
                                </ul>
                            </li>
                            <li>{!! __('<strong>Create expense</strong> / <strong>Create income</strong> — when you have yet to record it.') !!}</li>
                            <li>{!! __('<strong>Own transfer</strong> (arrows) — money moving between your accounts, outside income and expenses.') !!}</li>
                            <li>{!! __('<strong>Ignore</strong> (eye) — anything else that is irrelevant.') !!}</li>
                        </ul>
                    </li>
                    <li>{!! __('Made a mistake? Under "Processed" or "Ignored", use <strong>Reprocess</strong>. Records created from the line are deleted, and any existing records are kept.') !!}</li>
                </ol>
                <p class="mt-3">{!! __('Transfers between your business and other accounts are recognised by the names on the bank statement (your name or the name you gave the other account). Set those names in :settingsLink → Bank → <strong>Your names on the statement</strong>.', ['settingsLink' => $link('settings', __('Financial settings'))]) !!}</p>
                <p class="mt-3">{!! __('<strong>Reconcile automatically</strong> (at the top of the statement) processes all lines with a reliable suggestion at once. You can handle the remaining lines yourself.') !!}</p>
                <p class="mt-3">{!! __('<strong>Business account</strong> — at the top of the statement, on the dashboard and in month close: the <em>bank balance</em> (from the latest statement), the <em>Contiu balance</em> (opening balance + payments received − expenses paid) and the difference:') !!}</p>
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    <li>{!! __('<strong>Still to transfer to the business account</strong> — some payments have yet to reach the bank. Transfer or deposit that amount.') !!}</li>
                    <li>{!! __('<strong>The business owes you</strong> — some paid expenses did not go through this account. You can transfer that amount to yourself.') !!}</li>
                    <li>{!! __('<strong>Balances match</strong> — the bank balance matches Contiu exactly.') !!}</li>
                </ul>
                <p class="mt-3">{!! __('In the <strong>Payments received</strong> and <strong>Expenses</strong> lists, the <strong>Bank</strong> column shows whether each entry is <strong>Reconciled</strong> or <strong>Unreconciled</strong> (with a filter of the same name). An entry is reconciled when it is linked to a statement line or covered by one of your own transfers, with the oldest entries covered first. Once the whole statement is processed, unreconciled items add up to "Still to transfer". Partially reconciled items show what is still outstanding.') !!}</p>
                <p class="mt-3">{!! __('To see <em>which</em> statement lines cover a payment or expense, choose <strong>View in bank statement</strong> from the row’s ⋯ menu or open the <strong>Bank</strong> section while editing it. This also appears on the payments and expenses tabs for each project or retainer and in the month close expenses. On a transfer, the eye icon 👁 <strong>View details</strong> shows which records the transfer covers.') !!}</p>
                <p class="mt-2">{{ __('The amount is final once the whole statement is processed. The card shows a warning while any lines are still "To process".') }}</p>
            </x-filament::section>

            @endif

            {{-- Fecho do mês --}}
            <x-filament::section class="scroll-mt-24" id="fecho-do-mes" :icon="$icons['fecho-do-mes']" :heading="__('Month close')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{!! __('Open :monthCloseLink (button on the dashboard). It shows the receivables and expenses still outstanding for the month.', ['monthCloseLink' => $link('monthClose', __('Month close'))]) !!}</li>
                    <li>{!! __('Record the payments you received, mark expenses as paid:bankClause. Once everything is done, <strong>"Month closed"</strong> appears.', ['bankClause' => $bank ? __(', add the invoice number and process that month’s statement lines') : __(' and add the invoice number')]) !!}</li>
                    @if ($bank)
                        <li>{!! __('The <strong>Business account</strong> card shows the balance at month-end. If it shows "Still to transfer", transfer the amount to the business account.') !!}</li>
                        <li>{{ __('Recommended order: import the statement → accept suggestions → close the month.') }}</li>
                    @endif
                </ul>
            </x-filament::section>

            @if ($bank)
            {{-- Conta pessoal --}}
            <x-filament::section class="scroll-mt-24" id="conta-pessoal" :icon="$icons['conta-pessoal']" :heading="__('Personal account')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{{ __('The client paid into your personal account: record the payment using the date they paid. It will be absent from the business statement.') }}</li>
                    <li>{{ __('You paid an expense from your personal account: record the expense as usual.') }}</li>
                    <li>{!! __('When you move money between your accounts, mark the line as <strong>Own transfer</strong> on the statement. You can leave it without linking specific payments or expenses, since it is your own money moving between accounts.') !!}</li>
                    <li>{!! __('The amount still to transfer or owed to you is always in the <strong>Business account</strong> card. Outstanding payments and expenses appear as <strong>Unreconciled</strong>.') !!}</li>
                    <li>{!! __('<strong>Example with an offset:</strong> the client paid €730 into your personal account, you paid for ChatGPT (€83.74) from that account and transferred just €646.26. Record both when they happen. On the statement, use <strong>Link…</strong> with the payment and expense (730 − 83.74 = 646.26, with no difference), or simply use <strong>Own transfer</strong>.') !!}</li>
                </ul>
            </x-filament::section>

            @endif

            {{-- Obrigações fiscais --}}
            <x-filament::section class="scroll-mt-24" id="obrigacoes-fiscais" :icon="$icons['obrigacoes-fiscais']" :heading="__('Tax obligations')" :description="__('The This Month card on the dashboard shows what you need to pay or file and when it is due.')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{!! __('<strong>What appears:</strong> Social Security due that month (the previous month’s contribution, payable between the 10th and 20th), the quarterly Social Security declaration (in January, April, July and October if you are required to contribute), VAT on services bought abroad (reverse charge), the recapitulative statement for services to EU businesses, and personal income tax (Form 3, from 1 April to 30 June). You only see what applies to your account.') !!}</li>
                    <li>{!! __('<strong>Mark as done:</strong> after paying or filing, click <strong>Mark as paid</strong> or <strong>Mark as filed</strong>. Anything you leave open stays on the card after its deadline as <strong>Overdue</strong>.') !!}</li>
                    <li>{!! __('<strong>Alerts:</strong> on the first of each month you receive an email with that month’s obligations. An alert appears under the bell three days before each deadline.') !!}</li>
                    <li>{!! __('<strong>Your answers:</strong> in :settingsLink, enter when you started trading (first start or restart), whether you received a salary or pension, your income tax coefficient, IRS Jovem, and your Social Security rate and exemption. If your start date is missing, the card asks you to complete your settings.', ['settingsLink' => $link('settings', __('Tax settings'))]) !!}</li>
                    <li>{!! __('<strong>Purchases from abroad:</strong> in :suppliersLink, enter where each company is based. Expenses from suppliers outside Portugal with no VAT on the invoice are subject to reverse charge, except purchases of goods (materials), which you flag on the expense.', ['suppliersLink' => $link('suppliers', __('Suppliers'))]) !!}</li>
                    <li>{!! __('<strong>Services to EU businesses:</strong> in :clientsLink, enter where the client is based and whether it is a business. Payments from businesses in another EU country go on the recapitulative statement.', ['clientsLink' => $link('clients', __('Clients'))]) !!}</li>
                </ul>
            </x-filament::section>

            {{-- Notas legais --}}
            <x-filament::section class="scroll-mt-24" id="notas-legais" :icon="$icons['notas-legais']" :heading="__('Legal notes')" :description="__('Summary for sole traders under the simplified regime — check with your accountant.')">
                <ul class="list-disc space-y-2 ps-5">
                    <li>{{ __('Under the simplified regime, you can use a personal account for business (Article 63-C of the LGT applies to those with organised accounting). Keeping accounts separate is good practice.') }}</li>
                    <li>{{ __('Income counts when the client pays, whichever account they use. Transfers between your own accounts fall outside income and expenses.') }}</li>
                    <li>{{ __('For taxes, the invoices and invoice-receipts you issue and the expenses with your NIF in e-fatura count towards the 15% rule in Article 31 of the CIRS.') }}</li>
                    <li>{{ __('Each payment, including partial payments, needs its own invoice-receipt.') }}</li>
                    <li>{{ __('Platform income: the gross amount counts as income, and the fee is an expense.') }}</li>
                    <li>{{ __('Still to confirm: who to issue the invoice-receipt to for donations from anonymous or foreign supporters, and whether these count as business income (and towards the exempt portion under IRS Jovem). If they do not count, mark those payments as tax-exempt to exclude them from estimates.') }}</li>
                </ul>
            </x-filament::section>

            {{-- A tua conta e a assinatura --}}
            <x-filament::section class="scroll-mt-24" id="conta" :icon="$icons['conta']" :heading="__('Your account and subscription')" :description="__('What is included in your trial, how much it costs and what happens if your subscription ends.')">
                <ul class="list-disc space-y-2 ps-5">
                    <li><strong>{{ trans_choice('{1} 1-day trial:|[0,*] :count-day trial:', $subscription['trialDays']) }}</strong> {{ trans_choice('{1} A new account can use everything for 1 day without a card. The banner shows how many days remain.|[0,*] A new account can use everything for :count days without a card. The banner shows how many days remain.', $subscription['trialDays']) }}</li>
                    <li>{!! __('<strong>Subscription:</strong> under Settings → <strong>Subscription</strong>, choose monthly (:monthly per month) or annual (:yearly per year) and pay by card through Stripe. If you subscribe during your trial, you keep your remaining days. The first charge is at the end of the trial.', ['monthly' => e($subscription['monthly']), 'yearly' => e($subscription['yearly'])]) !!}</li>
                    <li>{!! __('<strong>Read-only account:</strong> when the trial ends without a subscription, or the subscription is no longer paid, you can still view and export everything. Creating, editing and deleting records, importing statements and sending emails are disabled. Your data is kept so you can pick up where you left off after subscribing.') !!}</li>
                    <li>{!! __('<strong>Card, receipts and cancellation:</strong> on the Subscription page, the button opens the Stripe billing portal. If you cancel, your account works until the end of the period you paid for.') !!}</li>
                </ul>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
