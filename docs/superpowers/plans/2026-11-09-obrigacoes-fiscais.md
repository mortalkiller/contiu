# Regras fiscais configuráveis e obrigações do mês — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-account tax rules (SS minimum and exemptions, configurable IRS coefficient, early-years reduction, IRS Jovem, reverse-charge VAT on foreign services) and a monthly "Este mês" obligations card on the dashboard, with a day-1 email and a bell notice 3 days before each deadline.

**Architecture:** New typed settings in `FinanceSettings` feed the existing `TaxEstimator` (SS and IRS rules) and a new `ReverseChargeVat` (foreign purchases). A new `TaxObligations` service turns those numbers into dated obligations with stable keys; completions are stored per account. The dashboard widget, the scheduled command and the Estimativa/Previsão pages all read `TaxObligations`/`TaxEstimator`, never their own copies of the rules.

**Tech Stack:** Laravel 13, Filament 5 (tenancy), Livewire, Pest 4, SQLite (tests) / PostgreSQL (prod).

**Spec:** `docs/superpowers/specs/2026-11-09-obrigacoes-fiscais-design.md`

## Global Constraints

- UI copy pt-PT. Money in integer cents (`App\Support\Money`). Every estimate keeps the "confirma com o teu contabilista" warning.
- SS minimum contribution: **2000** cents/month (a contribution, not a base). SS rates: **0.214** or **0.252**. Relevant share default **0.70**.
- IRS coefficients: **0.75** (art. 151.º table) or **0.35**. Early-years reduction **50 %** (start year) and **25 %** (next year).
- IRS Jovem: year 1 → **100 %**, 2–4 → **75 %**, 5–7 → **50 %**, 8–10 → **25 %**; age ≤ **35** on 31/12; cap **55 × IAS**; IAS **2025 = 52250**, **2026 = 53713** cents (`config('contiu.ias')`).
- Reverse-charge VAT rate default **0.23**. VAT threshold statuses at **80 %**, **100 %**, **125 %**.
- Obligation keys exactly: `ss:YYYY-MM`, `ss-declaration:YYYY-Qn`, `vat-reverse:YYYY-MM`, `recapitulativa:YYYY-MM`, `irs-modelo3:YYYY`.
- The per-record "isento de impostos" (`payments.tax_exempt`, retainers, "Recebimentos isentos por defeito") stays exactly as today. No account-level exemption switch.
- Business models use `BelongsToAccount`; anything run outside a request uses `CurrentAccount::run()`. Migrations run on SQLite and PostgreSQL.
- TDD with Pest; `vendor/bin/pint --dirty` before each commit; `npm run build` after Blade views with new Tailwind classes. Run the whole suite (`php artisan test --compact --parallel`) at the end of every task.
- Commit on `main`, message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; never push; never touch `bin/release`.

## Review Focus

1. **Year boundary**: in January the card must show the SS of January (from Oct–Dec income), the Q4 SS declaration (due 31 Jan) and any December obligation still undone. → test in Task 5.
2. **SS obligation starting mid-year** (`ss_enrolled_from` = 2026-05-01): Jan–Apr "Isento (1.º enquadramento)", from May at least 20 € even with no income. → test in Task 2.
3. **Foreign-supplier expense that has VAT or is goods** must not be self-assessed; the VAT one shows a warning instead. → test in Task 4.
4. **IRS Jovem edge**: income above 55 × IAS is capped; turning 36 that year gives 0 %; a year missing from the IAS table uses the last one and says "provisório". → test in Task 3.
5. **Read-only account** shows the card but cannot mark obligations; the command skips it. → tests in Tasks 6 and 7.

---

### Task 1: Settings and schema

**Files:**
- Modify: `app/Support/FinanceSettings.php`, `config/contiu.php`, `app/Actions/CreateAccount.php` (`SETTINGS` list)
- Create: `app/Enums/Seat.php`
- Create: migration `2026_11_09_100000_add_tax_fields.php` (suppliers.`seat` string default `pt`; clients.`seat` string default `pt`, clients.`is_business` bool default false; expenses.`is_goods` bool default false; delete `settings` rows with key `irs_exempt` or `ss_first_year_exempt`)
- Modify: models `Supplier`, `Client`, `Expense` (casts: `seat` → `Seat`, booleans)
- Test: `tests/Feature/Analysis/FinanceSettingsTest.php`, `tests/Feature/Accounts/ExistingDataMigrationTest.php` (drop the old keys), `tests/Feature/Accounts/CreateAccountTest.php`, `tests/Feature/Accounts/RegistrationTest.php` (remove `irs_exempt`/`ss_first_year_exempt` from data)

**Interfaces:**
- Produces `enum Seat: string { Portugal = 'pt'; EuropeanUnion = 'eu'; OutsideEu = 'non_eu' }` with `isForeign(): bool` and pt-PT labels ("Portugal", "Outro país da UE", "Fora da UE").
- Produces on `FinanceSettings` (DEFAULTS in brackets): `activityStartKind(): ?string` [`null`; values `first`|`restart`], `previousActivityEndedOn(): ?CarbonImmutable` [null], `ssEnrolledFrom(): ?CarbonImmutable` [null], `dependentIncomeYears(): list<int>` [`[]`, stored JSON], `irsCoefficient(): float` [0.75], `irsJovem(): bool` [false], `birthDate(): ?CarbonImmutable` [null], `irsJovemYearNumber(int $year): ?int` [stored `{"year":2026,"number":2}` → `number + (year − refYear)`; null when unset], `ssContributionExempt(): bool` [false], `reverseChargeVatRate(): float` [0.23], `isIncomplete(): bool` (true when `activity_started_on` or `activity_start_kind` is null).
- Keeps `irsExempt()` and `ssFirstYearExempt()` for now (Task 2 removes the SS one with its toggles, Task 3 the IRS one). The migration already deletes their stored rows, so both read their default (false) until then.
- `config('contiu.ias')` = `[2025 => 52250, 2026 => 53713]`.

- [ ] **Step 1: Failing tests** — `it('reads the new tax settings with their defaults')` asserting every default above; `it('counts the IRS Jovem year from the stored reference year')` with `{"year":2026,"number":2}` → `irsJovemYearNumber(2027) === 3`, `(2026) === 2`; `it('is incomplete until the activity start and kind are set')`; `it('drops the old exemption settings')` (migration test: rows `irs_exempt`, `ss_first_year_exempt` gone, other settings kept).
- [ ] **Step 2: Run them, expect failures** — `php artisan test --compact --filter="tax settings|IRS Jovem year|incomplete|old exemption"`.
- [ ] **Step 3: Implement** the enum, settings, config, migration and casts.
- [ ] **Step 4: Run the whole suite.** Green.
- [ ] **Step 5: Commit** "Impostos: definições novas por conta".

### Task 2: Segurança Social rules

**Files:**
- Modify: `app/Support/TaxEstimator.php`, `app/Filament/Analysis/Widgets/TaxQuartersTable.php`, `app/Support/CashForecast.php` (no rule copies; keep calling `ssContributionForMonth`)
- Modify: `app/Support/FinanceSettings.php` (remove `ssFirstYearExempt()` and its default), `app/Filament/Pages/FinanceSettingsPage.php` and `app/Filament/Pages/Auth/RegisterAccount.php` (remove the "Isento nos primeiros 12 meses" toggle and its default logic), `app/Actions/CreateAccount.php` (drop the key)
- Test: `tests/Feature/Analysis/TaxEstimatorTest.php`, `tests/Feature/Analysis/CashForecastTest.php` (replace `ss_first_year_exempt` usages)

**Interfaces:**
- Consumes Task 1 settings.
- Produces on `TaxEstimator`: `const SS_MINIMUM_CONTRIBUTION = 2000`; `ssObligationStartsOn(): ?CarbonImmutable` (first day of a month: `ssEnrolledFrom` if set; else kind `first` → 1st day of the 12th month after the start month (start 2025-08 → 2026-08), kind `restart` → the start month; null without a start date = obliged every month); `ssObligationIsEstimated(): bool` (true when computed, not from `ss_enrolled_from`); `ssMonthStatus(CarbonImmutable $month): string` returning `charged` | `first_enrolment` | `exempt`; `ssContributionForMonth(CarbonImmutable $month): int` (0 unless `charged`; else `max(base × rate, 2000)`); `quarters()` rows keep their keys, `monthly` already includes the minimum, plus `'status'` of the quarter's first payment month.
- The first-enrolment rule for incomplete settings (no kind) uses `first` (spec decision 3).

- [ ] **Step 1: Failing tests** — rewrite the SS tests in `TaxEstimatorTest`:
  - `it('charges the 20 € minimum in a quarter without income')`: no payments, `activity_started_on` 2024-01-01, kind `first` → `ssContributionForMonth(2026-05-01) === 2000`.
  - `it('charges the 20 € minimum when the computed contribution is lower')`: 25550 cents paid in Jul–Sep 2026 → `ssContributionForMonth(2026-11-01) === 2000` (computed 1276).
  - `it('keeps the computed contribution above the minimum')`: 100000 in Q1 → May = 4993.
  - `it('starts the obligation on the SS enrolment date')`: `ss_enrolled_from` 2026-05-01 → status of 2026-04 `first_enrolment`, 2026-05 `charged`, and May contribution 2000 with no income (Review Focus 2).
  - `it('computes the first-enrolment start without an enrolment date')`: start 2025-08-07 kind `first` → 2026-07 `first_enrolment`, 2026-08 `charged`, `ssObligationIsEstimated()` true; kind `restart` → 2025-08 `charged`.
  - `it('charges nothing with a recognised contribution exemption')`: `ss_contribution_exempt` true → every month `exempt`, 0, `ssYearTotal() === 0`.
  - `it('counts only payments not marked tax exempt, still with the minimum')`: one exempt payment of 300000 in Q2 → Jul contribution 2000.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement** in `TaxEstimator`; `TaxQuartersTable` note column: `charged` months as today, `first_enrolment` → "Isento (1.º enquadramento)", `exempt` → "Isento (art. 157.º)", and "mínimo" when the minimum applied.
- [ ] **Step 4: Run the whole suite** (fix `CashForecastTest` expectations that relied on 0 € months: they now get 2000 where obliged). Green.
- [ ] **Step 5: Commit** "Segurança Social: mínimo de 20 €, enquadramento e isenção do art. 157.º".

### Task 3: IRS rules and the Estimativa page

**Files:**
- Modify: `app/Support/TaxEstimator.php`, `app/Filament/Pages/TaxEstimate.php`, `app/Filament/Pages/CashForecast.php` (same incomplete-settings callout)
- Modify: `app/Support/FinanceSettings.php` (remove `irsExempt()`), `app/Filament/Pages/FinanceSettingsPage.php` and `app/Filament/Pages/Auth/RegisterAccount.php` (remove the "Isento de IRS" toggle), `app/Actions/CreateAccount.php` (drop the key)
- Test: `tests/Feature/Analysis/TaxEstimatorTest.php`, `tests/Feature/Analysis/AnalysisPagesTest.php`

**Interfaces:**
- Produces on `TaxEstimator`: `ias(): int` (cents for `$year`, last known year when missing) and `iasIsProvisional(): bool`; `irsJovemPercent(): float` (0.0–1.0; 0 when off, age > 35 on 31/12, or year number outside 1–10); `irsJovemExempt(): int` (`min(income × percent, 55 × ias)`); `irsCoefficientReduction(): float` (0.5 | 0.25 | 0.0); `effectiveIrsCoefficient(): float` (`settings->irsCoefficient() × (1 − reduction)`); `irsTaxableIncome(): int` (`(income − irsJovemExempt) × effectiveIrsCoefficient`, `Money::share`). `irsExempt()` is removed.
- Reduction rules: year == start year → 0.5, start year + 1 → 0.25, else 0; 0 when `$year` ∈ `dependentIncomeYears`; for `restart`, 0 unless `previous_activity_ended_on` is set and ≥ 5 years before `activity_started_on` (missing date → no reduction, conservative).

- [ ] **Step 1: Failing tests:**
  - `it('uses the configured IRS coefficient')`: 400000 in 2026, coefficient 0.35, start 2020 → taxable 140000.
  - `it('reduces the coefficient in the first two years')`: start 2025-08-07 kind `first` → `effectiveIrsCoefficient()` 2025 = 0.375, 2026 = 0.5625, 2027 = 0.75.
  - `it('drops the reduction in a year with salary or pension')`: `dependent_income_years` [2026] → 2026 `effectiveIrsCoefficient()` = 0.75.
  - `it('drops the reduction after a recent cessation')`: kind `restart`, previous ended 2022-01-01, start 2025-08-07 → 0; previous ended 2019-01-01 → 0.5 in 2025.
  - `it('exempts part of the gross income with IRS Jovem before the coefficient')`: 2000000 in 2026, IRS Jovem number 2 (75 %), start 2025 → exempt 1500000, taxable `500000 × 0.5625 = 281250` (the spec's worked example).
  - `it('caps IRS Jovem at 55 times the IAS')`: 4000000 in 2026 at 100 % → exempt 2954215.
  - `it('gives no IRS Jovem after turning 35')`: birth 1990-06-01 → 2026 percent 0.0 (age 36).
  - `it('marks the IAS provisional for a year not in the table')`: 2027 → `ias() === 53713`, provisional true.
  - Page test: Estimativa shows "Rendimento tributável", the coefficient used ("0,5625") and "IRS Jovem" lines; no "Isento (definição)".
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement**; the IRS section on `TaxEstimate` lists bruto, isento pelo IRS Jovem, coeficiente (and why: "redução de 25 % — 2.º ano"), tributável, and the note that IRS Jovem + redução is an interpretation without a binding AT ruling. The page callout no longer says "coeficiente de 75%". With `isIncomplete()`, a warning callout "Definições fiscais incompletas: valores aproximados" with a link to Definições fiscais, on Estimativa and on Previsão de tesouraria (test both).
- [ ] **Step 4: Run the whole suite.** Green.
- [ ] **Step 5: Commit** "IRS: coeficiente, redução dos primeiros anos e IRS Jovem".

### Task 4: VAT — reverse charge and threshold levels

**Files:**
- Create: `app/Support/ReverseChargeVat.php`
- Modify: `app/Support/TaxEstimator.php` (threshold status), `app/Filament/Pages/TaxEstimate.php` (125 % callout)
- Test: `tests/Feature/Analysis/ReverseChargeVatTest.php`, `tests/Feature/Analysis/TaxEstimatorTest.php`

**Interfaces:**
- Produces `ReverseChargeVat::__construct(FinanceSettings $settings)`; `forMonth(CarbonImmutable $month): array{base: int, vat: int, expenses: Collection<Expense>}` — paid or unpaid expenses with `spent_on` in the month, supplier `seat` foreign, `is_goods` false, `vat_rate` 0; `vat = Money::share(base, reverseChargeVatRate)`; `base` = sum of `net_amount`. `foreignWithVat(CarbonImmutable $month): Collection<Expense>` — same filter but `vat_rate` > 0.
- `TaxEstimator::vatThresholdStatus()` returns `ok` | `warning` (≥ 80) | `danger` (≥ 100) | `critical` (≥ 125).

- [ ] **Step 1: Failing tests** — `it('self-assesses VAT on services bought from foreign suppliers')` (EU supplier 8374 + non-EU 1000 in Oct 2026 → base 9374, vat 2156); `it('leaves out goods, Portuguese suppliers and other months')`; `it('lists foreign expenses that carry VAT instead of self-assessing them')` (Review Focus 3); `it('uses the configured reverse-charge rate')` (0.22); threshold dataset adds `[1875000, 'critical', 125.0]`.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.** Estimativa: `critical` callout "Passaste 125 % do limite: tens 15 dias úteis para entregar a declaração de alterações"; `danger` callout text becomes "Sais da isenção no próximo ano: declaração de alterações em janeiro".
- [ ] **Step 4: Run the whole suite.** Green.
- [ ] **Step 5: Commit** "IVA: autoliquidação das compras ao estrangeiro e patamar dos 125 %".

### Task 5: Obligations service and completions

**Files:**
- Create: `app/Support/TaxObligation.php` (readonly value object), `app/Support/TaxObligations.php`, `app/Models/TaxObligationCompletion.php` (`BelongsToAccount`), factory, migration `2026_11_09_100100_create_tax_obligation_completions_table.php` (`account_id`, `key`, `completed_at`, `user_id` nullable; unique `account_id`+`key`)
- Test: `tests/Feature/TaxObligationsTest.php`; add the table to the arch allowlist check in `AccountIsolationTest` only if that test requires it (it is account-scoped, so it should pass as is)

**Interfaces:**
- `TaxObligation` props: `string $key`, `string $kind` (`ss` | `ss-declaration` | `vat-reverse` | `recapitulativa` | `irs-modelo3` | `vat-threshold` | `foreign-vat-warning`), `string $title`, `?int $amount`, `?CarbonImmutable $dueOn`, `?CarbonImmutable $opensOn` (SS: day 10), `string $detail`, `?string $url`, `string $status` (`due` | `overdue` | `done` | `exempt` | `info`). `daysLeft(CarbonImmutable $today): ?int`.
- `TaxObligations::__construct(FinanceSettings $settings, CarbonImmutable $today)`; `thisMonth(): list<TaxObligation>` (obligations due in `$today`'s month + undone overdue ones from earlier months, ordered by `dueOn`; SS of the month even when `exempt`; VAT threshold row as `info` only when `vat_exempt`; foreign-VAT warnings as `info`; incomplete settings → no SS/IRS rows and one `info` row "Completa as definições fiscais"); `upcoming(): list<TaxObligation>` (next `ss-declaration` and `irs-modelo3`, not yet due this month); `pending(): list<TaxObligation>` (`thisMonth()` filtered to `due`/`overdue`); `complete(string $key, ?User $user): void`; `reopen(string $key): void`.
- Deadlines: `ss:Y-M` due 20/M (opens 10/M); `ss-declaration:Y-Qn` due last day of Apr/Jul/Oct/Jan(Y+1); `vat-reverse:Y-M` (purchases of month M) due last day of M+1, only when `ReverseChargeVat::forMonth(M).vat > 0`; `recapitulativa:Y-M` due 20/(M+1) when month M has payments from clients `seat` = `eu` and `is_business`; `irs-modelo3:Y` (income year Y) due 30/06/(Y+1), shown in `thisMonth()` only in June and in `upcoming()` from April.
- Overdue = `dueOn < today` and not completed; overdue items are listed for at most the previous 12 months.

- [ ] **Step 1: Failing tests:**
  - `it('lists what is due this month with amount and deadline')` — today 2026-11-09, SS charged 2000, foreign expense in Oct → `ss:2026-11` (2000, due 2026-11-20) and `vat-reverse:2026-10` (due 2026-11-30).
  - `it('shows the January set across the year boundary')` — today 2027-01-05: `ss:2027-01`, `ss-declaration:2026-Q4` (due 2027-01-31), undone `vat-reverse:2026-11` (due 2026-12-31) as `overdue` (Review Focus 1).
  - `it('drops completed obligations and reopens them')`.
  - `it('shows SS as exempt without a deadline')` with `ss_contribution_exempt`.
  - `it('asks to complete the settings when they are incomplete')`.
  - `it('lists the recapitulativa only for EU business clients')`.
  - `it('keeps completions per account')` — completing in account A does not complete in account B.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the whole suite.** Green.
- [ ] **Step 5: Commit** "Obrigações fiscais do mês: serviço e registo do que está feito".

### Task 6: "Este mês" card on the dashboard

**Files:**
- Create: `app/Filament/Widgets/TaxObligationsCard.php`, `resources/views/filament/widgets/tax-obligations-card.blade.php`
- Modify: `app/Filament/Pages/Dashboard.php` (render right after `FinanceStatsOverview`/`CompanyAccountStats`, full width)
- Test: `tests/Feature/TaxObligationsCardTest.php`

**Interfaces:**
- Consumes `TaxObligations` (Task 5). Livewire methods on the widget: `complete(string $key): void`, `reopen(string $key): void` — both refuse (no-op + danger notification) when `CurrentAccount::readOnly()`.
- Layout follows the approved mockup (option A): header "Este mês · <mês ano>" with "N por fazer · <total>"; one bordered row per obligation (title, amount, detail, days-left badge — amber ≤ 7 days, red when overdue —, "Marcar como pago/entregue", link when `url`); VAT threshold progress bar; "A seguir" line; "Ver feitas" toggle listing done items with "Desfazer"; empty state "Nada a pagar este mês". Use theme tokens, works in light and dark.

- [ ] **Step 1: Failing tests** — `it('shows this month obligations on the dashboard')` (sees "Este mês", "Segurança Social", "20,00 €", "Faltam 11 dias"); `it('marks an obligation as done and hides it')`; `it('does not mark in a read-only account')` (Review Focus 5); `it('shows the empty state')`.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement**; `npm run build`.
- [ ] **Step 4: Run the whole suite**; open the dashboard in the browser (light and dark) and compare with the mockup. Green.
- [ ] **Step 5: Commit** "Painel: cartão Este mês com as obrigações fiscais".

### Task 7: Alerts — day-1 email and 3-day bell

**Files:**
- Create: `app/Console/Commands/SendTaxObligations.php` (`finance:send-tax-obligations`), `app/Mail/TaxObligationsDigest.php` (uses `SendsAsAccount`), `resources/views/mail/tax-obligations-digest.blade.php`
- Modify: `routes/console.php` (`dailyAt('08:00')`)
- Test: `tests/Feature/TaxObligationsAlertsTest.php`

**Interfaces:**
- Loops accounts like `SendReminders` (skip `! $account->hasAccess()`), inside `CurrentAccount::run()`. Day 1: if `pending()` is not empty, queue `TaxObligationsDigest` to each account user (list with amount, deadline, overdue mark, dashboard link). Every day: for each `pending()` obligation with `dueOn` == today + 3 days, one Filament database notification ("<título>: faltam 3 dias", warning, action "Abrir painel").

- [ ] **Step 1: Failing tests** — `it('emails the month obligations on the 1st')`; `it('sends no email without obligations')`; `it('notifies 3 days before a deadline, once')` (run on 2026-11-17 for `ss:2026-11` → 1 notification; 2026-11-18 → none); `it('skips read-only accounts')`; `it('includes overdue obligations in the email')`.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the whole suite.** Green.
- [ ] **Step 5: Commit** "Alertas das obrigações fiscais: email no dia 1 e aviso 3 dias antes".

### Task 8: Forms — settings, registration, suppliers, clients, expenses

**Files:**
- Modify: `app/Filament/Pages/FinanceSettingsPage.php`, `app/Filament/Pages/Auth/RegisterAccount.php`, `app/Actions/CreateAccount.php`, `app/Filament/Resources/Suppliers/Schemas/SupplierForm.php` (+ table column), `app/Filament/Resources/Clients/Schemas/ClientForm.php`, `app/Filament/Resources/Expenses/Schemas/ExpenseForm.php`, `app/Filament/Pages/HelpCentre.php` view (one section "Obrigações fiscais")
- Test: `tests/Feature/Analysis/FinanceSettingsTest.php`, `tests/Feature/Accounts/RegistrationTest.php`, `tests/Feature/Analysis/AnalysisPagesTest.php`, `tests/Feature/TaxFormsTest.php`

**Interfaces:**
- Consumes Task 1 settings and `Seat`.
- Definições fiscais sections: **Atividade** (início, `activity_start_kind` radio "Primeiro início"/"Reinício", `previous_activity_ended_on` visible on reinício, `ss_enrolled_from` with help "Segurança Social Direta → Trabalho → Remunerações e contribuições → Trabalhadores independentes → Consultar e alterar os dados de atividade", `dependent_income_years` as two toggles: current and previous year "Tive salário ou pensão em <ano>"); **IRS** (coefficient radio with the art. 151.º explanation and "programadores, designers, consultores" example; IRS Jovem toggle + `birth_date` + "Em <ano>, és no ano n.º" 1–10); **Segurança Social** (rate select 21,4 % / 25,2 %, relevant share, `ss_contribution_exempt` with the art. 157.º help); **IVA** (as today + reverse-charge rate). "Isento de IRS" and "Isento nos primeiros 12 meses" are gone.
- Registration: the Impostos/SS steps ask the same fields (Atividade, IRS, IVA, Segurança Social); the Confirmar summary lists them; `CreateAccount::SETTINGS` gains the new keys.
- Supplier form: `seat` select "Onde fica a sede da empresa?". Client form: `seat` + `is_business` "É uma empresa?". Expense form: `is_goods` toggle "É uma compra de bens (material)?", visible only when the chosen supplier's seat is foreign.

- [ ] **Step 1: Failing tests** — settings page saves and reloads every new field; registration stores them (update `registrationData()`); `it('shows the goods question only for foreign suppliers')`; Pedro's example from the spec as a settings test: restart 2025-08-07, enrolled 2025-08-01, 21,4 %, exempt, salary 2026, coefficient 0.75 → Estimativa shows SS "Isento (art. 157.º)" and IRS coefficient 0,75.
- [ ] **Step 2: Run, expect failures.**
- [ ] **Step 3: Implement**; `npm run build` if new classes.
- [ ] **Step 4: Run the whole suite**; check the settings page and the wizard in the browser. Green.
- [ ] **Step 5: Commit** "Definições e registo: atividade, IRS, Segurança Social e sede de fornecedores e clientes".
