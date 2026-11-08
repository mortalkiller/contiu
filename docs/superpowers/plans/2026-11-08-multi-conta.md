# Contiu multi-conta Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn Contiu into a SaaS where each freelancer has an isolated account, signs up through a step-by-step wizard, gets 14 days of trial and then pays 5 €/month or 54 €/year through Stripe; only super admins see every account.

**Architecture:** An `Account` model is the Filament tenant. Every business model uses a `BelongsToAccount` trait whose global scope reads one `CurrentAccount` service (explicit account, else `Filament::getTenant()`) and **fails closed** (throws) when there is none, so code outside Filament resources (support classes, commands, widgets) cannot leak. Billing is Laravel Cashier on `Account`; an account without access becomes read-only through one choke point in the trait (model writes) plus `Gate::before` (hides write buttons).

**Tech Stack:** Laravel 13, Filament 5 (tenancy), Pest 4, Spatie Permission + Filament Shield (no teams), Laravel Cashier (Stripe), PostgreSQL (prod) / SQLite (tests).

**Spec:** `docs/superpowers/specs/2026-11-08-multi-conta-design.md`

## Global Constraints

- UI copy in pt-PT. Money in integer cents (`App\Support\Money`).
- Prices: monthly **500** cents, yearly **5400** cents. Trial **14** days from sign-up. No VAT, no Stripe Tax.
- Only super admins see all accounts; `super_admin` is never assignable from the Users screen.
- Migrations must run on PostgreSQL and SQLite (`tests/Feature/PostgresCompatibilityTest.php` patterns).
- TDD with Pest; `vendor/bin/pint --dirty` before each commit; `npm run build` after adding Blade views with new Tailwind classes.
- Never edit or commit `bin/release`. Commit on `main`, message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; don't push.
- Run the whole suite (`php artisan test --parallel` or `vendor/bin/pest`) at the end of every task, not only the new tests.

## Review Focus

1. **Livewire round-trips keep the tenant**: a select search, a table filter or a modal action on a resource page is a Livewire update request; it must resolve the same account, not throw `NoCurrentAccount` and not see other accounts. → test in Task 3.
2. **A super admin inside someone else's account sees only that account**, including `FinanceSettings`, dashboard widgets and the bank page. → test in Task 3.
3. **The same bank statement imported into two accounts** creates lines in both (hash unique per account), and same project/retainer codes can exist in two accounts. → test in Task 2.
4. **Scheduled commands and their notifications**: each account's reminders go only to that account's users, and the "Ver despesa" link opens the right account's URL. → test in Task 4.
5. **Registration edge inputs**: invalid NIF, NIF already used, account names that collide with reserved URL segments (`login`, `register`, `termos`…) or with each other. → tests in Tasks 6 and 7.

---

### Task 1: Account foundation (accounts, CurrentAccount, fail-closed scope)

**Files:**
- Create: `app/Models/Account.php`, `database/factories/AccountFactory.php`
- Create: `database/migrations/2026_11_08_100000_create_accounts_table.php` (`accounts`, `account_user`)
- Create: `app/Support/CurrentAccount.php`, `app/Exceptions/NoCurrentAccount.php`
- Create: `app/Models/Concerns/BelongsToAccount.php`, `app/Models/Scopes/AccountScope.php`
- Modify: `app/Providers/AppServiceProvider.php` (register `CurrentAccount` as `scoped`)
- Test: `tests/Feature/Accounts/CurrentAccountTest.php`

**Interfaces:**
- Produces:
  - `accounts`: `id`, `name`, `slug` (unique), `nif` (string 9, nullable, unique), `trial_ends_at` (nullable timestamp), `billing_exempt` (bool, default false), timestamps. `account_user`: `account_id`, `user_id`, `role` (string, default `owner`), unique (`account_id`,`user_id`).
  - `Account` with `users(): BelongsToMany` (pivot `role`), `owner(): ?User` (first user with role owner).
  - `CurrentAccount` (container `scoped`), static facade-style methods: `set(?Account $account): void`, `get(): ?Account` (explicit, else `Filament::getTenant()`), `id(): int` (throws `NoCurrentAccount`), `run(Account $account, callable $callback): mixed` (sets, runs, restores the previous value even on exception).
  - Trait `BelongsToAccount`: adds `AccountScope` (`where(qualified account_id, CurrentAccount::id())`), fills `account_id` on `creating` when empty, `account(): BelongsTo`.

- [ ] **Step 1: Write failing tests** in `CurrentAccountTest.php` using a throwaway test model on a temp table (or wait to Task 2 and use `Client`; here use an anonymous model class with table `scope_probes` created in the test):
  - `it('scopes queries to the current account')` — two accounts, one probe each; inside `CurrentAccount::run($a, …)` query returns only `$a`'s probe.
  - `it('fills account_id when creating')`.
  - `it('throws when there is no current account')` — `expect(fn () => Probe::query()->get())->toThrow(NoCurrentAccount::class)`.
  - `it('restores the previous account after run, even on exception')`.
  - `it('falls back to the Filament tenant')` — `Filament::setTenant($b)`; `CurrentAccount::get()->is($b)`.
- [ ] **Step 2:** `vendor/bin/pest tests/Feature/Accounts/CurrentAccountTest.php` → FAIL (classes missing).
- [ ] **Step 3:** Implement the files above. `Account` is **not** scoped.
- [ ] **Step 4:** Same command → PASS; full suite still green (nothing uses the trait yet).
- [ ] **Step 5:** Commit `Contas: modelo Account e isolamento por conta atual`.

### Task 2: Every business table belongs to an account

**Files:**
- Create: `database/migrations/2026_11_08_100100_add_account_id_to_business_tables.php`
- Create: `app/Models/Audit.php` (extends `OwenIt\Auditing\Models\Audit`, sets `account_id` from `CurrentAccount::get()` on creating) + `config/audit.php` `implementation`
- Modify: models `Client, Supplier, Category, Project, Retainer, Receivable, Payment, Expense, RecurringExpense, OneOffIncome, BankTransaction, BankTransactionLink, ClientMessage, Setting` → `use BelongsToAccount`
- Modify: `app/Models/Account.php` — `hasMany` per model, named as Filament expects: `clients, suppliers, categories, projects, retainers, receivables, payments, expenses, recurringExpenses, oneOffIncomes, bankTransactions, clientMessages, settings`
- Modify: `tests/Pest.php` — Feature `beforeEach`: create `$this->account = Account::factory()->create()` and `CurrentAccount::set($this->account)`; `actingAsAdmin()` attaches the user as owner and calls `Filament::setTenant(test()->account)`
- Modify: `app/Actions/RecordOneOffIncome.php::nextCode`, `app/Actions/ImportBankStatement.php:33`, `app/Filament/Resources/Projects/Schemas/ProjectForm.php:64`, `app/Filament/Resources/Retainers/Schemas/RetainerForm.php:32` (unique rule `->where('account_id', CurrentAccount::id())`)
- Test: `tests/Feature/Accounts/AccountIsolationTest.php`, `tests/Feature/Accounts/ExistingDataMigrationTest.php`

**Interfaces:**
- Consumes: Task 1 (`BelongsToAccount`, `CurrentAccount`, `Account`).
- Produces: `account_id` (FK, cascade delete, indexed) on: `clients, suppliers, categories, projects, retainers, receivables, payments, expenses, recurring_expenses, one_off_incomes, bank_transactions, bank_transaction_links, client_messages, settings, audits` (nullable on `audits` only). Per-account uniques: `categories(account_id,type,name)`, `projects(account_id,code)`, `retainers(account_id,code)`, `one_off_incomes(account_id,code)`, `settings(account_id,key)`, `bank_transactions(account_id,hash)`.

Backfill (inside the same migration, only if `users` or any business table has rows): create one account `name = env('CONTIU_FIRST_ACCOUNT_NAME', 'Conta principal')`, slug from it, `billing_exempt = true`; set its id on every existing row; attach every existing user as `owner`; set `email_verified_at = now()` where null (Task 7 turns on verification and must not lock Pedro out); **write the current effective value of every `FinanceSettings::DEFAULTS` key that has no row** (Task 5 changes the defaults and Pedro's values must not move). Then make `account_id` NOT NULL.

- [ ] **Step 1: Write failing tests**
  - `AccountIsolationTest`: `it('keeps every business model inside its account')` — dataset of the 14 model classes; create one record per account via factories under `CurrentAccount::run`; each account counts 1.
  - `it('lets two accounts import the same bank statement')` — `ImportBankStatement` the same rows under account A then B → both have the lines. *(Review Focus 3)*
  - `it('allows the same project and retainer code in two accounts')`, `it('numbers one-off incomes per account')`.
  - Architecture: `arch()->expect('App\Models')->...` or a plain test: every model whose table has `account_id` uses `BelongsToAccount` (iterate `app/Models/*.php`, check `Schema::hasColumn`).
  - `ExistingDataMigrationTest`: roll back to before the migration, insert a user + client + setting row without account, migrate, assert one `billing_exempt` account owns them, the user is owner and verified, and missing settings keys were written with the old defaults.
- [ ] **Step 2:** Run the two files → FAIL.
- [ ] **Step 3:** Implement migration, traits on models, Audit model, rule/code changes, Pest helper changes.
- [ ] **Step 4:** Run the two files → PASS. Run the **full suite**: fix fallout (tests creating records before `beforeEach` sets the account, migration tests in `MoneyInCentsMigrationTest`). Expected: all green.
- [ ] **Step 5:** Commit `Contas: todos os dados pertencem a uma conta`.

### Task 3: Filament tenancy, super admins and system pages

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` — `->tenant(Account::class, slugAttribute: 'slug', ownershipRelationship: 'account')`, `->tenantProfile(EditAccountProfile::class)`
- Modify: `app/Models/User.php` — implement `HasTenants` (`getTenants(Panel): Collection` = all accounts for super admin, else `$this->accounts`; `canAccessTenant(Model): bool`), `accounts(): BelongsToMany`, `isSuperAdmin(): bool`
- Create: `app/Filament/Pages/Tenancy/EditAccountProfile.php` (fields: name, NIF with `PortugueseNif` from Task 6 — add the rule in Task 6 and leave the NIF field plain `digits:9` here)
- Modify: `UserResource`, Shield `RoleResource`, backup page, `ImportWorkbook` → `$isScopedToTenant = false` where applicable and `canAccess()` = `auth()->user()->isSuperAdmin()`; `UserResource` role select excludes `super_admin`
- Test: `tests/Feature/Accounts/TenancyAccessTest.php`; update existing page tests whose URLs change (`PanelAtRootTest`, `NavigationTest`, `UserManagementTest`)

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces: tenant URLs `/{account:slug}/…`; `User::isSuperAdmin(): bool`, `User::accounts()`.

- [ ] **Step 1: Write failing tests** in `TenancyAccessTest.php`:
  - `it('shows a user only their own accounts')` and `it('returns 404 for a record of another account')` — `get(PaymentResource::getUrl('edit', ['record' => $other], tenant: $mine))` → 404; and `get('/'.$other->slug)` → 403/404 for a normal user.
  - `it('lets a super admin open any account and see only that account')` — super admin on account B's dashboard / `FinanceSettings` / `BankStatementImport` sees B's values, not A's. *(Review Focus 2)*
  - `it('keeps the tenant on Livewire updates')` — `Livewire::test(CreatePayment::class)` with tenant set, call the receivable select's search (`getSearchResults` / `fillForm` + `assertFormFieldExists` with options) → only own records, no exception. *(Review Focus 1)*
  - `it('hides system pages from normal users')` — Users, Roles, Backups, Importar Excel → 403 for owner, 200 for super admin.
  - `it('never offers super_admin in the Users screen')`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. Keep `->path('')`.
- [ ] **Step 4:** Run → PASS; full suite green (update URL-dependent tests).
- [ ] **Step 5:** Commit `Contas: tenancy do Filament e páginas só para super admins`.

### Task 4: Close the leaks outside resources

**Files:**
- Modify: `app/Actions/SendFinanceReminders.php:26`, `app/Actions/GenerateRecurringExpenses.php:93` — recipients = `CurrentAccount::get()->users`; URLs built with `tenant: CurrentAccount::get()`
- Modify: `app/Actions/MatchBankTransactions.php:107` — names from the current account's users only
- Modify: `app/Console/Commands/GenerateRecurring.php`, `app/Console/Commands/SendReminders.php` — loop `Account::query()->each(fn ($account) => CurrentAccount::run($account, …))`
- Modify: `app/Livewire/QuickCreateMenu.php` (URLs with the current tenant) if it builds URLs without it
- Test: `tests/Feature/Accounts/AccountJobsTest.php`

**Interfaces:**
- Consumes: Tasks 1–3. Produces: commands run per account (Task 8 adds the read-only skip).

- [ ] **Step 1: Write failing tests**:
  - `it('sends each account its own reminders')` — overdue receivable in A and B, users in each; run `finance:send-reminders`; A's user gets only A's notification. *(Review Focus 4)*
  - `it('links recurring-expense notifications to the right account')` — notification action URL starts with `/{A->slug}/`.
  - `it('recognises own transfers only by the current account users names')`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS; full suite green.
- [ ] **Step 5:** Commit `Contas: lembretes, recorrentes e reconhecimento de transferências por conta`.

### Task 5: Remove what was tailored to Pedro

**Files:**
- Create: `app/Actions/SeedAccountDefaults.php` — `handle(Account $account): void` creates the base categories (copy from spec): Receita — Serviços, Avenças, Outras receitas; Despesa — Software e subscrições, Equipamento, Comunicações e internet, Deslocações, Formação, Contabilidade, `RecordOneOffIncome::COMMISSION_CATEGORY`, Outras despesas. Idempotent (`firstOrCreate`).
- Modify: `app/Support/FinanceSettings.php::DEFAULTS` — `irs_exempt=false`, `vat_exempt=false`, `ss_first_year_exempt=false`, `payments_exempt_by_default=false` (others unchanged)
- Modify: `ProjectForm.php:85`, `RetainerForm.php:46` — datalist = distinct `business_line` of the account's projects and retainers
- Modify: `app/Mail/PaymentReminder.php`, `app/Mail/ClientStatementEmail.php` + views `resources/views/mail/*.blade.php` — signature `{{ $accountName }}` instead of `config('app.name')`; envelope `replyTo` = account owner email
- Modify: `FinanceSettingsPage.php:120-121` placeholder → `"ANA MARIA SILVA\nConta pessoal"`, helper without "Ordenado"; `help-centre.blade.php:120` → generic example
- Test: `tests/Feature/Accounts/AccountDefaultsTest.php`; adjust `PaymentReminderTest`, `ClientStatementTest`, `HelpCentreTest`

- [ ] **Step 1: Write failing tests**: `it('creates the base categories once')`; `it('suggests the business lines already used in the account')`; `it('signs client emails with the account name and replies to the owner')` (`Mail::fake`, `assertSent` with `hasReplyTo` and rendered body containing account name); `it('does not show Pedro data in settings or help')` (`assertDontSee('PEDRO')`, `assertDontSee('Ordenado')`).
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS + full suite.
- [ ] **Step 5:** Commit `Contas: categorias base e textos sem dados do Pedro`.

### Task 6: NIF rule and CreateAccount action

**Files:**
- Create: `app/Rules/PortugueseNif.php`, `app/Actions/CreateAccount.php`, `config/contiu.php`
- Modify: `EditAccountProfile` (use `PortugueseNif`, unique on `accounts.nif`)
- Test: `tests/Unit/PortugueseNifTest.php`, `tests/Feature/Accounts/CreateAccountTest.php`

**Interfaces:**
- Produces:
  - `config('contiu.trial_days') = 14`, `config('contiu.terms_version') = '2026-11-08'`, `config('contiu.prices') = ['monthly' => env('STRIPE_PRICE_MONTHLY'), 'yearly' => env('STRIPE_PRICE_YEARLY')]`, `config('contiu.reserved_slugs')` = `login, logout, register, password-reset, email-verification, profile, termos, privacidade, stripe, livewire, up, storage, build, brand, admin, api`.
  - `PortugueseNif` (ValidationRule): 9 digits; check digit = `11 - (Σ d[i]·(9-i), i=0..7) mod 11`, `≥10 → 0`.
  - `CreateAccount::handle(User $owner, array $data): Account` — `$data` keys: `name, nif, activity_started_on, irs_exempt, vat_exempt, vat_exemption_threshold (cents), ss_rate, ss_relevant_income_share, ss_first_year_exempt, account_names`. In a transaction: account (unique slug from name, never in `reserved_slugs`, suffix `-2`, `-3`…), `trial_ends_at = now()->addDays(14)`, owner pivot, `FinanceSettings::set` (+ `payments_exempt_by_default = vat_exempt`) inside `CurrentAccount::run`, `SeedAccountDefaults`.

- [ ] **Step 1: Write failing tests**: NIF dataset valid `123456789`, `501964843`; invalid `123456780`, `12345678`, `abcdefghi`. `CreateAccount`: creates account, trial ends in 14 days, owner attached, settings stored for that account only, categories seeded; `it('never uses a reserved slug')` (name "Login" → slug `login-2`); `it('suffixes repeated names')`. *(Review Focus 5)*
- [ ] **Step 2:** FAIL. **Step 3:** Implement. **Step 4:** PASS + full suite.
- [ ] **Step 5:** Commit `Contas: validação do NIF e criação de conta`.

### Task 7: Registration wizard, email verification, terms

**Files:**
- Create: `app/Filament/Pages/Auth/RegisterAccount.php` (extends `Filament\Auth\Pages\Register`; form = `Wizard`; `handleRegistration(array $data): Model` calls `CreateAccount`)
- Create: migration `2026_11_08_100200_add_terms_acceptance_to_users.php` (`terms_accepted_at`, `terms_version`)
- Create: routes `/termos`, `/privacidade` in `routes/web.php` + views `resources/views/legal/{terms,privacy}.blade.php` (structure + "Texto a fornecer pelo Pedro" placeholders, brand styling like `errors/*`)
- Modify: `AdminPanelProvider` — `->registration(RegisterAccount::class)`, `->emailVerification()`; `User implements MustVerifyEmail`
- Test: `tests/Feature/Accounts/RegistrationTest.php`

Wizard steps and fields (copy in pt-PT, one short helper line each):
1. **Acesso** — `name`, `email` (unique users), `password` + confirmation.
2. **Atividade** — `account_name`, `nif` (`PortugueseNif`, unique `accounts.nif`), `activity_started_on`.
3. **Impostos** — `irs_exempt`, `vat_exempt` (art. 53.º), `vat_exemption_threshold` (`MoneyInput`, default 1 500 000 cents, visible when `vat_exempt`).
4. **Segurança Social** — `ss_rate` (default 21,4 %), `ss_relevant_income_share` (70 %), `ss_first_year_exempt` (default true when `activity_started_on` is within the last 12 months).
5. **Banco** (skippable) — `account_names` textarea, generic placeholder.
6. **Confirmar** — summary + `terms` checkbox (required, links to `/termos` and `/privacidade`).

- [ ] **Step 1: Write failing tests**: `it('creates user and account from the wizard')` (Livewire `RegisterAccount`, fill all steps, `call('register')` → user, account owner, `terms_version` stored, redirected to verification prompt); `it('rejects an invalid or repeated NIF')`; `it('requires accepting the terms')`; `it('blocks the panel until the email is verified')`; `it('serves the terms and privacy pages')`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement; `npm run build`. **Step 4:** PASS + full suite.
- [ ] **Step 5:** Commit `Contas: registo em passos com verificação de email e termos`.

### Task 8: Subscription access and read-only accounts

**Files:**
- `composer require laravel/cashier` (version supporting Laravel 13); publish Cashier migrations and change the customer columns and `subscriptions` foreign key to `accounts` / `account_id`; `Cashier::useCustomerModel(Account::class)`, `Cashier::keepPastDueSubscriptionsActive()` in `AppServiceProvider`
- Modify: `Account` — `use Billable`; `hasAccess(): bool` = `billing_exempt || onGenericTrial() || subscribed('default')`
- Modify: `CurrentAccount` — `readOnly(): bool` = account set, `! hasAccess()`, and auth user is not super admin
- Modify: `BelongsToAccount` — on `saving`/`deleting` throw `App\Exceptions\AccountIsReadOnly` when `CurrentAccount::readOnly()`; render it in Filament as a danger notification "A conta está só de leitura. Subscreve para continuar a registar."
- Modify: `AppServiceProvider` — `Gate::before`: for non-super-admins, deny `create, update, delete, deleteAny, restore, forceDelete, replicate, reorder` when `CurrentAccount::readOnly()`
- Modify: email actions `SendPaymentReminderAction`, `SendStatementEmailAction`, bank import/auto-reconcile actions → `->disabled(fn () => CurrentAccount::readOnly())`
- Create: `resources/views/filament/partials/account-status-banner.blade.php` via `PanelsRenderHook::CONTENT_START`: trial → "Experiência: faltam N dias · Subscrever"; read-only → fixed notice + link to the `AccountSubscription` page (Task 9; link target added there)
- Modify: commands from Task 4 skip accounts where `! hasAccess()`
- Test: `tests/Feature/Accounts/AccountAccessTest.php`

- [ ] **Step 1: Write failing tests** (subscriptions inserted directly into `subscriptions` with `stripe_status`): dataset → access: trial in future ✓; trial over, none ✗; `active` ✓; `past_due` ✓; `canceled` with `ends_at` future ✓; `canceled` ended ✗; `incomplete_expired` ✗; `unpaid` ✗; `billing_exempt` ✓. `it('refuses writes in a read-only account')` (create Client → `AccountIsReadOnly`), `it('still lets a super admin write')`, `it('hides create buttons when read-only')`, `it('skips read-only accounts in scheduled commands')`, `it('shows the trial banner with days left')`.
- [ ] **Step 2:** FAIL. **Step 3:** Implement; `npm run build`. **Step 4:** PASS + full suite.
- [ ] **Step 5:** Commit `Contas: assinatura dá acesso; sem ela a conta fica só de leitura`.

### Task 9: Subscription page, Stripe payments and the accounts list

**Files:**
- Create: `app/Filament/Pages/AccountSubscription.php` (slug `assinatura`, group Definições, hidden for `billing_exempt`) + view
- Create: migration `2026_11_08_100300_create_subscription_payments_table.php` (`account_id`, `stripe_invoice_id` unique, `amount` cents, `paid_at`, `period_start`, `period_end`, `billing_address` json, `invoiced_at` nullable) + `app/Models/SubscriptionPayment.php` (**not** account-scoped; read only by super admins)
- Create: `app/Listeners/RecordSubscriptionPayment.php` on `Laravel\Cashier\Events\WebhookReceived`, type `invoice.paid`, `amount_paid > 0`
- Create: `app/Mail/SubscriptionPaid.php` + view — to every super admin: conta, NIF, morada, valor, data, período
- Create: `app/Filament/Resources/Accounts/AccountResource.php` (`$isScopedToTenant = false`, super admins only): name, NIF, owner email, estado (Experiência até d/m/Y · Mensal · Anual · Só leitura · Isenta), registo; view page with payments and action "Marcar como faturado" (sets `invoiced_at`); row action "Entrar na conta" → tenant dashboard URL
- Test: `tests/Feature/Accounts/SubscriptionTest.php`

**Interfaces:**
- Consumes: Task 6 config prices, Task 8 `hasAccess`.
- Produces: `AccountSubscription::checkoutOptions(Account $account, string $plan): array` — `['success_url' => …, 'cancel_url' => …, 'billing_address_collection' => 'required', 'subscription_data' => ['trial_end' => $account->trial_ends_at->timestamp]]` with `trial_end` only when the trial is still in the future; `$plan ∈ {monthly, yearly}` → `config('contiu.prices.'.$plan)`. Actions: `subscribe{Monthly,Yearly}` → `$account->newSubscription('default', $price)->checkout($options)`; `manage` → `$account->redirectToBillingPortal(AccountSubscription::getUrl())`.

- [ ] **Step 1: Write failing tests**: `checkoutOptions` keeps remaining trial days and omits `trial_end` after the trial; `it('records an invoice.paid webhook and emails super admins')` (POST `/stripe/webhook` with an `invoice.paid` payload for the account's `stripe_id`, `Mail::fake` → `SubscriptionPayment` row + `SubscriptionPaid` to super admins only); `it('ignores zero-amount invoices')`; `it('lists accounts and marks a payment as invoiced')` (super admin only; owner gets 403).
- [ ] **Step 2:** FAIL. **Step 3:** Implement; point the Task 8 banner link at `AccountSubscription::getUrl()`. **Step 4:** PASS + full suite.
- [ ] **Step 5:** Commit `Contas: página Assinatura, pagamentos Stripe e lista de contas`.

### Task 10: Seeder, help, and end-to-end check

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php` — dev account "Conta de exemplo" (`billing_exempt`), admin as owner + super admin, `SeedAccountDefaults`
- Modify: `resources/views/filament/pages/help-centre.blade.php` — short section "A tua conta e a assinatura" (experiência de 14 dias, mensal/anual, só leitura, portal da Stripe)
- Test: `HelpCentreTest` (new section present)

- [ ] **Step 1:** Failing help test → **Step 2:** FAIL → **Step 3:** implement → **Step 4:** PASS.
- [ ] **Step 5:** `php artisan migrate:fresh --seed` locally on a copy, full suite, `vendor/bin/pint --dirty`, `npm run build`.
- [ ] **Step 6:** Browser (1280×800, reset to desktop after): register a new freelancer through all 6 steps with a valid test NIF, confirm the empty dashboard, trial banner, categories; open a second account as super admin and check no data mixes; check the read-only banner by expiring the trial in tinker.
- [ ] **Step 7:** Commit `Contas: seeder, ajuda e verificação final`.

## Before going live (not code; tell Pedro)

- MAIL configured in production; scheduler cron running.
- Stripe live prices created (5 €/mês, 54 €/ano), `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_YEARLY`; webhook endpoint `/stripe/webhook` with `invoice.paid` and Cashier's subscription events.
- Terms and Privacy text written by Pedro / a lawyer.
- `CONTIU_FIRST_ACCOUNT_NAME` set before running the migration in production; Pedro fills his NIF in the account profile afterwards.
