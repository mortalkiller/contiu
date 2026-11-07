# Extrato simples, receitas avulsas e centro de ajuda — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every bank line ends explained (linked to one or more records, "Transferência minha" or ignored) with suggestions doing most of the work, one-off income (donations) with platform commission, and an in-app help centre that walks Pedro through the whole workflow.

**Architecture:** Bank lines link to records through a `bank_transaction_links` table (replaces the single `matched_*` morph). `MatchBankTransactions` gains "partial receipt" and "own transfer" candidates; `ApplyBankTransaction` gains multi-link, own transfer, one-off income and undo. One-off income is a new receivable owner (`OneOffIncome`, beside Project and Retainer) so every existing total includes it. The help centre is a Filament page rendering a Blade guide.

**Tech Stack:** Laravel 13, Filament 5, Livewire, Pest 4, SQLite, Phosphor icons (`ToneGabes\Filament\Icons\Enums\Phosphor`).

**Spec:** `docs/superpowers/specs/2026-11-07-reconciliacao-design.md`

## Global Constraints

- UI copy in pt-PT; money via `App\Support\Money::format()`; dates d/m/Y; Phosphor icons only.
- TDD: each behaviour gets a failing Pest test first (`php artisan test --compact <file>`), then code.
- Run `vendor/bin/pint --dirty --format agent` and the full suite at the end of each task.
- No git commits (Pedro postponed git).
- Differences under 0,01 € count as zero.
- Ties never produce an automatic suggestion (existing `BankSuggestion::best()` rule).
- After UI tasks, look at the page in the browser and fix layout gaps before moving on.

## Review Focus

- Undo of a line that created a one-off income must remove the income, its receivable, payment and commission expense (no orphans in totals).
- A record can only ever be linked to one bank line (unique index), including through "Associar…" with several targets.
- Own-transfer suggestion must not fire for client names that merely share one word with Pedro's name (needs every ≥3-letter token of a user's name).
- Partial-receipt suggestion only when the line is smaller than what is open **and** the client name is in the description.
- One-off income shows in dashboard received/contracted, income-by-category, margins ("Receitas avulsas" line) and tax estimate, and is never listed as open/overdue.

---

### Task 1: Links table and statuses

**Files:**
- Create: `database/migrations/2026_11_07_140000_create_bank_transaction_links.php`, `app/Models/BankTransactionLink.php`
- Modify: `app/Enums/BankTransactionStatus.php`, `app/Models/BankTransaction.php`, `app/Models/Payment.php`, `app/Models/Expense.php`, `app/Actions/ApplyBankTransaction.php`, `app/Actions/MatchBankTransactions.php`, `app/Filament/Pages/BankStatementImport.php`
- Test: `tests/Feature/BankReconciliationTest.php`

**Interfaces:**
- Produces: table `bank_transaction_links` (`bank_transaction_id` FK cascade, `linkable_type`, `linkable_id`, `created_from_line` bool, `marked_paid` bool, timestamps, unique `[linkable_type, linkable_id]`). Migration copies every non-null `matched_*` into a link (`created_from_line` = status was `created`), maps status `created`→`matched`, then drops `matched_type`/`matched_id`.
- `BankTransactionStatus`: `Pending` "Por tratar", `Matched` "Registado", `OwnTransfer = 'own_transfer'` "Transferência minha" (color info), `Ignored` "Ignorado". `Created` removed.
- `BankTransaction::links(): HasMany<BankTransactionLink>`, `BankTransaction::records(): Collection<int, Payment|Expense>`, `BankTransaction::difference(): float` = `amount − (Σ payment.amount − Σ expense.total)` rounded to 2.
- `Payment::bankLink()` / `Expense::bankLink(): MorphOne<BankTransactionLink>` (replace `bankTransaction()`).
- `ApplyBankTransaction::linkRecords(BankTransaction $t, array $records): void` (replaces `toRegistered`): pending only; each record unlinked or `DomainException('Este registo já está ligado a outra linha do extrato.')`; an unpaid expense becomes paid on the line date with `marked_paid = true`; status Matched. All existing create/mark actions write links (`created_from_line` / `marked_paid`) instead of `matched_*`.

- [ ] Step 1: Rewrite the existing `toRegistered` tests to `linkRecords` and add `it('links one line to two payments and reports no difference')` (credit 900 = payments 600 + 300 → `difference()` 0.0, two links) and `it('reports the difference when the records do not add up')` (debit −151 linked to paid expense 151,98 → `difference()` 0.98).
- [ ] Step 2: Run `php artisan test --compact tests/Feature/BankReconciliationTest.php` — FAIL (undefined `linkRecords`/`difference`).
- [ ] Step 3: Migration, model, enum, relations and action changes above; update `MatchBankTransactions` to use `doesntHave('bankLink')`; page `describe()` lists `records()` labels and appends "diferença de X" when non-zero.
- [ ] Step 4: Run the bank tests and full suite — PASS.

### Task 2: Own transfer, undo and partial-receipt suggestion

**Files:**
- Modify: `app/Actions/MatchBankTransactions.php`, `app/Actions/ApplyBankTransaction.php`, `app/Support/BankCandidate.php`
- Test: `tests/Feature/BankReconciliationTest.php`

**Interfaces:**
- `BankCandidate` types add `'partial'` (model Receivable) and `'own_transfer'` (model `null`; `$model` becomes nullable). Labels: `'Recebimento parcial · {label} ({client}) — falta {outstanding}'`, `'Transferência minha'`.
- Own transfer candidate: description contains every token (≥3 letters, ascii-lowercased) of any `User` name, nameScore = token count; or contains `ordenado`, nameScore 1. Added for credits and debits alongside other candidates.
- Partial: open receivable with `outstanding > amount + 0.009` and `nameScore > 0`.
- `ApplyBankTransaction::markOwnTransfer(BankTransaction $t): void`; `accept()` handles `partial` (→ `toReceivable`) and `own_transfer`.
- `ApplyBankTransaction::undo(BankTransaction $t): void`: for each link — `created_from_line` → delete the record (a payment whose receivable belongs to a `OneOffIncome` deletes the whole income, Task 3); `marked_paid` → expense back to unpaid (`is_paid` false, `paid_on` null); delete links; status Pending. Works for Matched, OwnTransfer and Ignored.

- [ ] Step 1: Tests: `it('suggests own transfer when the description has the user full name')` (user "Pedro Monteiro", line "TRF DE PEDRO FILIPE PEREIRA MONTEIRO" → best type `own_transfer`); `it('does not suggest own transfer for a different person with one shared name')` ("TRF DE PEDRO SILVA" → no own_transfer); `it('suggests a partial receipt when the client name matches and the line is smaller')` (open 1000, line 400 "TRF GONCALO TEIXEIRA" → best `partial`); `it('does not suggest a partial receipt without the client name')`; `it('undo deletes a payment created from the line and keeps one that already existed')`; `it('undo puts an expense marked paid by the line back to unpaid')`; `it('undo returns an own transfer to pending')`.
- [ ] Step 2: Run — FAIL.
- [ ] Step 3: Implement as above.
- [ ] Step 4: Run bank tests + full suite — PASS.

### Task 3: One-off income (receita avulsa)

**Files:**
- Create: `database/migrations/2026_11_07_140100_create_one_off_incomes.php`, `app/Models/OneOffIncome.php`, `database/factories/OneOffIncomeFactory.php`, `app/Actions/RecordOneOffIncome.php`, `app/Filament/Actions/OneOffIncomeAction.php`
- Modify: `app/Support/PeriodSummary.php` (contracted query), `app/Models/Payment.php` (tax-exempt default), `app/Models/Receivable.php` (`open` stays correct; `billableUrl()` helper), `app/Filament/Widgets/UpcomingReceivablesTable.php`, `app/Filament/MonthClose/Widgets/MonthReceivablesTable.php` (use `billableUrl()`), `app/Livewire/QuickCreateMenu.php`, `app/Filament/Resources/Payments/PaymentResource.php` (header action), `app/Actions/ApplyBankTransaction.php`
- Test: `tests/Feature/OneOffIncomeTest.php`

**Interfaces:**
- Table `one_off_incomes`: `code` (unique, `RA-YYYYMMDD-n`), `client_id` nullable, `category_id` nullable, `business_line` default `'Receitas avulsas'`, `title`, `received_on` date, `net_amount` decimal, `vat_rate` decimal default 0, `commission_expense_id` nullable FK→expenses nullOnDelete, `notes`, timestamps. Model uses `HasReceivables`; its `deleting` deletes its payments, receivables and commission expense (overrides the "has payments" guard).
- `RecordOneOffIncome::handle(array $data): OneOffIncome` — keys `received_on`, `title`, `category_id`, `gross_amount`, `commission` (nullable), `commission_supplier_id` (required when commission > 0), `invoice_number`, `tax_exempt`, `method` (default Transfer), `client_id` (nullable). Creates income, one receivable (amount gross, due_on received_on), one payment (paid), and when commission > 0 a paid overhead expense: supplier, category "Comissões" (expense type, `firstOrCreate`), description `'Comissão · '.title`, net = commission, VAT 0.
- `Receivable::billableUrl(): ?string` → Project/Retainer view URL, null otherwise.
- `ApplyBankTransaction::createOneOffIncome(BankTransaction $t, array $data): OneOffIncome` — credit only; links payment and commission expense with `created_from_line`.
- `OneOffIncomeAction::make()` — Filament action "Receita avulsa" (icon `Phosphor::HandCoins`) with the form fields above; gross defaults to a passed amount.

- [ ] Step 1: Tests: `it('records a donation with the platform commission')` (gross 5, commission 0.68, Stripe → payment 5.00, expense total 0.68 category "Comissões"); `it('counts in received, contracted, income by category, margins and tax estimate')` (PeriodSummary received/contracted include 5; `receivedByCategory()` has "Donativos"; `MarginReport::byBusinessLine()` has "Receitas avulsas"; TaxEstimator income includes it when not exempt); `it('is never open or overdue')`; `it('creates the income from a bank line and links payment and commission')` (line 4.32 → difference 0.0); `it('deleting or undoing removes income, receivable, payment and commission')`; `it('opens from the quick create menu')`.
- [ ] Step 2: Run — FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run new tests + full suite — PASS.

### Task 4: Bank page — Associar…, own transfer, create income, undo

**Files:**
- Modify: `app/Filament/Pages/BankStatementImport.php`, `app/Actions/ApplyBankTransaction.php`
- Test: `tests/Feature/BankStatementImportPageTest.php`

**Interfaces:**
- `ApplyBankTransaction::planTargets(BankTransaction $t, array $targets): array<int, array{target: string, amount: float}>` and `applyTargets(BankTransaction $t, array $targets): void`. Target keys: `payment:ID`, `paid:ID`, `expense:ID` (existing, amount = record's), `receivable:ID` (creates payment of `min(outstanding, remaining)`), `template:ID` (creates expense for `remaining`), processed existing-first; `remaining` = |line| − signed sum so far. Difference = line − signed sum of planned amounts.
- Table action `link` ("Associar…", replaces `choose`): `Select::multiple()->searchable()->live()` with grouped options (Sugeridos, Em aberto, Despesas por pagar, Recorrentes, Recebimentos já registados, Despesas já registadas), callout key `amountDifference` visible when the planned difference ≠ 0.
- Actions on pending rows: `accept`, `link`, `createExpense` (debits), `createIncome` (credits; `OneOffIncomeAction` form, gross default = line amount), `ownTransfer` (icon `Phosphor::ArrowsLeftRight`), `ignore`. On treated rows: `undo` ("Voltar a tratar", confirmation lists what will be deleted).
- Tabs: Por tratar · Tratados (Matched + OwnTransfer) · Ignorados.

- [ ] Step 1: Tests: `it('links a line to two receivables of a client who paid both at once')`; `it('warns about the difference before linking')` (`assertSchemaComponentExists('amountDifference', checkComponentUsing: ...)`); `it('marks a line as own transfer')`; `it('creates a one-off income from a credit line')`; `it('undoes a treated line')`; update `choose` tests to `link`.
- [ ] Step 2: Run — FAIL.
- [ ] Step 3: Implement.
- [ ] Step 4: Run page tests + full suite — PASS. Browser: open the page, open "Associar…" and "Receita avulsa" modals, fix layout.

### Task 5: Help centre

**Files:**
- Create: `app/Filament/Pages/HelpCentre.php`, `resources/views/filament/pages/help-centre.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (user menu item "Centro de ajuda", icon `Phosphor::Question`)
- Test: `tests/Feature/HelpCentreTest.php`

**Interfaces:**
- Page slug `ajuda`, title "Centro de ajuda", in navigation group "Definições" and in the user menu. Content: table of contents + sections with ids: `fluxo` (worked example: trabalho → parcial → total → extrato → fecho), `recebimentos`, `despesas`, `receitas-avulsas`, `extrato`, `fecho-do-mes`, `conta-pessoal`, `notas-legais`. Copy follows the spec and the example agreed with Pedro (Gonçalo Teixeira, 2 000 €, 50/50, 400 € parcial, 600 € MB Way na conta pessoal, transferência de 900 €, 1 000 € final, donativo 5,00/0,68/4,32, OpenAI 83,74 €). Links to the real pages (`ProjectResource::getUrl('create')`, `BankStatementImport::getUrl()`, …).

- [ ] Step 1: Tests: `it('shows the workflow guide')` (GET page OK, sees "Centro de ajuda", "Aceitar sugestões", "Transferência minha", "Receita avulsa"); `it('is in the user menu')`.
- [ ] Step 2: Run — FAIL.
- [ ] Step 3: Implement; `npm run build`.
- [ ] Step 4: Tests + full suite PASS; browser screenshot at desktop and mobile width.

### Task 6: Real data and final check

- [ ] Step 1: Run migrations on the local DB (existing links are migrated, Pedro's manual 655 € payment kept).
- [ ] Step 2: Browser: extrato page shows suggestions for Pedro's lines (own transfers for "TRF DE PEDRO…"); nothing accepted on his behalf.
- [ ] Step 3: Full suite, Pint, `storage/logs/laravel.log` free of new errors.
