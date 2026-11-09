# Calendário da atividade — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only "Calendário" page (group "Análise") showing every dated thing in the account — tax obligations, payments, open receivables, expenses, projected retainers and recurring expenses — with a "Prazos a seguir" column beside it.

**Architecture:** `TaxObligations` gains a public `between()` (and the "valor por apurar" rule for SS); a plugin-free `App\Support\ActivityCalendar` turns the account's data for a date range into `CalendarEntry` value objects; a `saade/filament-fullcalendar` widget maps entries to events, with type filters; the page puts that widget and a "Prazos a seguir" widget side by side.

**Tech Stack:** Laravel 13, Filament 5 (tenancy), Livewire, `saade/filament-fullcalendar` ^5.0 (FullCalendar 7), Pest 4.

**Spec:** `docs/superpowers/specs/2026-11-09-calendario-design.md`

## Global Constraints

- UI copy pt-PT. Money in integer cents, shown with `App\Support\Money::format()`.
- Read-only calendar: no `selectable`, no `editable`, no create/edit/delete actions on the widget.
- Never copy tax rules or schedule rules: obligations come from `TaxObligations`, projected dates from `ScheduleProjector`.
- Only free FullCalendar plugins: `interaction`, `dayGrid`, `list`, `multiMonth`. No license key.
- Icons are Phosphor only (`tests/Feature/PhosphorOnlyIconsTest.php` counts 24×24 SVGs): the plugin's own `prev`/`next`/`filters` tools use Heroicons and must be redefined.
- Business models use `BelongsToAccount`; nothing may show another account's records.
- TDD with Pest; `vendor/bin/pint --dirty --format agent` before each commit; `npm run build` after theme/Blade changes; whole suite `php artisan test --compact --parallel` at the end of each task.
- `resources/css/filament/admin/theme.css` (and possibly `AdminPanelProvider.php`) has someone else's uncommitted palette changes. Commit **only this plan's hunks**: build the staged version from `git show HEAD:<file>` plus this plan's additions and stage it with `git hash-object -w` + `git update-index --cacheinfo`, then check `git diff --cached` shows only this plan's lines. Never commit, revert or reformat their hunks.
- Commit on `main`, message ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`; never push; never touch `bin/release`; never stage files this plan does not list (Pedro may have uncommitted work).

## Review Focus

1. **Year view (`multiMonthYear`) over a year boundary**: `between()` must work across years (Dec→Jan) and `ScheduleProjector` dates must be cut to the visible range, not just "until". → test in Task 2 and Task 3.
2. **A partially paid receivable** shows as "A receber" with the outstanding amount (not the full amount), and is not duplicated by its payment's own event on the same day being counted as the same thing. → test in Task 3.
3. **A retainer whose period was already generated** must appear only once (as "A receber"), never also as "prevista". → test in Task 3.
4. **Obligations before the tracking start / older than 12 months** are absent, even when the user navigates back to that month. → test in Task 2.
5. **Another account's records** never appear, including projected ones. → test in Task 3.

---

### Task 1: Plugin installed, empty "Calendário" page, plugin checks

**Files:**
- Modify: `composer.json`, `composer.lock` (via `composer require saade/filament-fullcalendar:"^5.0"`)
- Modify: `resources/css/filament/admin/theme.css` (add the plugin `@import` and `@source` from the plugin README "Installation" step 2, after Filament's import)
- Create: `app/Filament/Pages/Calendar.php`
- Create: `app/Filament/Analysis/Widgets/ActivityCalendarWidget.php`
- Test: `tests/Feature/Analysis/CalendarPageTest.php`

**Interfaces:**
- Produces: `App\Filament\Pages\Calendar` (title `'Calendário'`, slug `'calendario'`, `$navigationGroup = 'Análise'`, `$navigationSort = 4`, icon `Phosphor::CalendarDots`); `App\Filament\Analysis\Widgets\ActivityCalendarWidget extends Saade\FilamentFullCalendar\Widgets\FullCalendarWidget` with `fetchEvents(FetchInfo $info): array` returning `[]` for now.

- [ ] **Step 1: Write the failing tests**

```php
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-09'));
    actingAsAdmin();
});

it('opens the calendar page in the Análise group', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSee('Calendário');
    expect(Calendar::getNavigationGroup())->toBe('Análise');
});

it('shows only Phosphor icons on the calendar page', function () {
    $html = $this->get(Calendar::getUrl())->assertOk()->getContent();
    expect(heroiconsOn($html))->toBe(0);
});

it('uses European Portuguese, Monday first and the month, list and year views', function () {
    $widget = Livewire::test(ActivityCalendarWidget::class)->instance();
    expect($widget->getLocale())->toBe('pt')
        ->and($widget->config())->toMatchArray(['initialView' => 'dayGridMonth', 'firstDay' => 1]);
});
```

(`heroiconsOn()` is defined in `PhosphorOnlyIconsTest.php`; move it to `tests/Pest.php` so both files share it.)

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Analysis/CalendarPageTest.php` — expect FAIL (class `Calendar` not found).

- [ ] **Step 3: Install and build**

`composer require saade/filament-fullcalendar:"^5.0"`, add the CSS lines to the theme, `npm run build`. Do not register `FilamentFullCalendarPlugin` on the panel: the widget sets everything itself.

- [ ] **Step 4: Implement the page and the widget**

Page: a `Filament\Pages\Page` like `CashForecast`, with `getSubheading()` = `'Tudo o que tem data: obrigações fiscais, recebimentos, valores a receber, despesas e o que ainda vai ser gerado.'` and the widget in the content (a 3-column grid is added in Task 5; for now full width).
Widget: `getLocale(): 'pt'`; `getPlugins()`: `['interaction', 'dayGrid', 'list', 'multiMonth']` (replace defaults, no `timeGrid`); `config()`: `['initialView' => 'dayGridMonth', 'firstDay' => 1, 'mobileInitialView' => 'listMonth']`; `toolbarButtons()`: `start => [['prev','next'], 'today']`, `center => ['title']`, `end => [['dayGridMonth','listMonth','multiMonthYear']]`; `tools()` redefining `prev`/`next` with `Phosphor::CaretLeft`/`CaretRight` (same `labelJsExpression`/`jsHandler` as the plugin's `HasToolbar::getDefaultTools()`). Button texts in `config()['buttons']`: `dayGridMonth` → `'Mês'`, `listMonth` → `'Agenda'`, `multiMonthYear` → `'Ano'`.

- [ ] **Step 5: Run the test file** — expect PASS.

- [ ] **Step 6: Plugin check in the browser (spike gate)**

With the dev server, open `/{account}/calendario` in light and dark mode and confirm: Portuguese labels ("hoje", "novembro de 2026", "seg."), week starts on Monday, the year view renders, and the calendar follows dark mode with the Pressiu green. If any of these fails on 5.x, switch to `saade/filament-fullcalendar:"^4.0"` and redo Steps 3–6; if 4.x fails too, stop and ask Pedro.

- [ ] **Step 7: Pint, whole suite, commit** — `composer.json composer.lock resources/css/filament/admin/theme.css public/build app/Filament/Pages/Calendar.php app/Filament/Analysis/Widgets/ActivityCalendarWidget.php tests/Pest.php tests/Feature/PhosphorOnlyIconsTest.php tests/Feature/Analysis/CalendarPageTest.php` (only add `public/build` if it is tracked). Message: `Calendário: página vazia com o plugin FullCalendar`.

---

### Task 2: `TaxObligations::between()` and SS "valor por apurar"

**Files:**
- Modify: `app/Support/TaxObligations.php` (`ss()` ~line 172, `dueEarlier()` ~line 152, new `between()`, new private `trackedFrom()`)
- Test: `tests/Feature/TaxObligationsTest.php` (reuse `obligationsOn()`, `byKey()`, `serviceBoughtAbroad()`, `paymentFrom()`)

**Interfaces:**
- Produces: `public function between(CarbonImmutable $from, CarbonImmutable $to): array` → `list<TaxObligation>`, by deadline, only with `dueOn` in `[$from, $to]` (inclusive, dates), none without `dueOn`, none before `trackedFrom()`.
- Produces (private): `trackedFrom(): CarbonImmutable` — the first month obligations may be listed: the later of `$this->month->subMonths(OVERDUE_MONTHS)` and `(obligationsTrackedFrom() ?? account created_at)->startOfMonth()`. `dueEarlier()` uses it too.

- [ ] **Step 1: Write the failing tests**

```php
it('lists the obligations of any range, past and future, by deadline', function () {
    serviceBoughtAbroad('2026-10-15', 1000);
    $obligations = obligationsOn('2026-11-09', since: '2026-01-01');

    $keys = array_map(fn ($o) => $o->key, $obligations->between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2027-01-31')));

    expect($keys)->toContain('ss:2026-11', 'vat-reverse:2026-10', 'ss:2026-12', 'ss:2027-01', 'ss-declaration:2026-Q4')
        ->and($keys)->not->toContain('ss:2026-10', 'ss:2027-02');
});

it('leaves out what is before the tracking start or more than 12 months back', function () {
    $obligations = obligationsOn('2026-11-09', since: '2026-06-15');

    $keys = array_map(fn ($o) => $o->key, $obligations->between(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2026-07-31')));

    expect($keys)->toBe(['ss:2026-06', 'ss:2026-07', 'ss-declaration:2026-Q2']);
});

it('leaves out exempt obligations, which have no deadline', function () {
    // First enrolment: the SS of November is exempt (no dueOn).
    $obligations = obligationsOn('2026-11-09', ['activity_started_on' => '2026-09-01', 'activity_start_kind' => 'first']);

    $keys = array_map(fn ($o) => $o->key, $obligations->between(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30')));

    expect($keys)->not->toContain('ss:2026-11');
});

it('shows the SS amount only once its income quarter has closed', function () {
    $rows = byKey(obligationsOn('2026-11-09', since: '2026-01-01')
        ->between(CarbonImmutable::parse('2026-12-01'), CarbonImmutable::parse('2027-02-28')));

    expect($rows['ss:2027-01']->amount)->not->toBeNull()
        ->and($rows['ss:2027-02']->amount)->toBeNull()
        ->and($rows['ss:2027-02']->detail)->toContain('valor por apurar')->not->toContain('contribuição mínima')
        ->and($rows['ss:2027-02']->dueOn->toDateString())->toBe('2027-02-20');
});
```

Also keep the existing `ss:2026-11` assertions (line ~68: "contribuição mínima") passing unchanged — the card must not change.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/TaxObligationsTest.php` — expect FAIL (`between` undefined).

- [ ] **Step 3: Implement** `between()`, `trackedFrom()` (refactor `dueEarlier()` onto it), and in `ss()`: when `$source->endOfQuarter()->gt($this->today)`, return the obligation with `amount = null` and the detail's last part `' · valor por apurar'` instead of the amount-based parts (keep "Pagar entre 10 e 20 de …"). `between()` iterates months from `max($from->startOfMonth(), trackedFrom())` to `$to`, collects `dueIn($month)`, filters by `dueOn` in range, sorts with `byDeadline()`.

- [ ] **Step 4: Run** the file and `tests/Feature/TaxObligationsCardTest.php tests/Feature/TaxObligationsAlertsTest.php` — expect PASS.

- [ ] **Step 5: Pint, whole suite, commit** — `app/Support/TaxObligations.php tests/Feature/TaxObligationsTest.php`. Message: `Obrigações: lista por intervalo e SS por apurar enquanto o trimestre não fecha`.

---

### Task 3: `ActivityCalendar` service

**Files:**
- Create: `app/Enums/CalendarEntryType.php`
- Create: `app/Support/CalendarEntry.php`
- Create: `app/Support/ActivityCalendar.php`
- Test: `tests/Feature/ActivityCalendarTest.php`

**Interfaces:**
- Consumes: `TaxObligations::between()` (Task 2); `ScheduleProjector::retainerDueDates(Retainer, CarbonInterface $until)`, `recurringExpenseDates(RecurringExpense, CarbonInterface $until)`.
- Produces:
  - `enum CalendarEntryType: string implements HasLabel` — `Obligation = 'obligation'` ("Obrigações fiscais"), `Payment = 'payment'` ("Recebimentos"), `Receivable = 'receivable'` ("A receber"), `Expense = 'expense'` ("Despesas"), `ProjectedRetainer = 'projected-retainer'` ("Avenças previstas"), `ProjectedRecurringExpense = 'projected-recurring-expense'` ("Recorrentes previstas").
  - `final readonly class CalendarEntry` — `string $id` (unique: obligation key, or `"payment:{id}"`, `"receivable:{id}"`, `"expense:{id}"`, `"retainer:{id}:{Y-m-d}"`, `"recurring-expense:{id}:{Y-m-d}"`), `CalendarEntryType $type`, `CarbonImmutable $date`, `string $title`, `?int $amount`, `string $status` (obligation status `due|overdue|done`; receivable `ReceivableStatus` value; expense `paid|unpaid`; projected `projected`; payment `paid`), `?string $url`, `?string $detail`.
  - `final class ActivityCalendar` — `__construct(FinanceSettings $settings, CarbonImmutable $today)`; `entries(CarbonImmutable $from, CarbonImmutable $to, array $types): list<CalendarEntry>` (`$types`: `list<CalendarEntryType>`, inclusive dates, sorted by date then type order above).

Titles (exact): obligation → `title` + (`amount` ? `' · '.Money::format(amount)` : ''); payment → `'Recebido · '.(client name ?? receivable description).' · '.amount`; receivable → `'A receber · '.$receivable->label.' · '.outstanding`; expense → `(supplier name ?? description).' · '.total` + (`! is_paid` ? `' · por pagar'` : ''); projected retainer → `'Avença · '.client name.' (prevista) · '.total`; projected recurring → `description.' (prevista) · '.total`.
URLs: obligation → its `url`; payment and receivable → `$receivable->billableUrl() ?? <PaymentResource|ReceivableResource>::getUrl()`; expense → `ExpenseResource::getUrl('edit', ['record' => $expense])`; retainer → `RetainerResource::getUrl('view', …)`; recurring → `RecurringExpenseResource::getUrl()`. Detail: obligation `detail`; others `null`.

- [ ] **Step 1: Write the failing tests** (travel to `2026-11-09`, `actingAsAdmin()`; one test each)

  - `puts each kind on its own date`: a payment `paid_on 2026-11-03`, an open receivable `due_on 2026-11-15`, an expense `spent_on 2026-11-12` (unpaid) → three entries on those dates with types Payment/Receivable/Expense; the expense title ends with `· por pagar`.
  - `shows the outstanding of a partly paid receivable and hides paid ones`: receivable 80000 with a 30000 payment → entry amount 50000, status `partial`; a fully paid receivable → no Receivable entry (its Payment entry is there).
  - `leaves out receivables of cancelled jobs` (`Project` with `ProjectStatus::Cancelled`).
  - `projects retainers and recurring expenses only after what was generated`: an active monthly retainer with a generated receivable `due_on 2026-11-25` → `ProjectedRetainer` entries on `2026-12-25` (range to 2026-12-31) and none on `2026-11-25`; same idea for a `RecurringExpense`.
  - `cuts projections to the range`: range `2026-12-01..2026-12-31` returns no projected entry in November or January.
  - `includes the obligations of the range with their status` (uses `between()`): `ss:2026-11` on `2026-11-20`, status `due`, url not null.
  - `filters by type`: `entries(..., [CalendarEntryType::Expense])` returns only expenses.
  - `never shows another account's records`: create a second account's payment/expense/retainer inside `CurrentAccount::run($other, …)` → none appear.
  - `works across the year boundary`: range `2026-12-01..2027-01-31` includes `ss:2027-01` and a projected retainer on `2027-01-25`.

- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/ActivityCalendarTest.php` — expect FAIL.

- [ ] **Step 3: Implement.** Query each model only within the range (`whereBetween` on the date column) with eager loads (`receivable.billable.client`, `supplier`, `client`); receivables via `open()->excludingCancelled()`; projected dates filtered to `[$from, $to]`; obligations via `new TaxObligations($this->settings, $this->today)` → `between()`.

- [ ] **Step 4: Run** the file — expect PASS.

- [ ] **Step 5: Pint, whole suite, commit** — the three app files and the test. Message: `Calendário: eventos da conta para um intervalo`.

---

### Task 4: Widget events, colours and type filters

**Files:**
- Modify: `app/Filament/Analysis/Widgets/ActivityCalendarWidget.php`
- Modify: `resources/css/filament/admin/theme.css` (two classes)
- Test: `tests/Feature/Analysis/CalendarPageTest.php`

**Interfaces:**
- Consumes: `ActivityCalendar::entries()`, `CalendarEntry`, `CalendarEntryType` (Task 3).
- Produces: widget filter state `filters['types']` (`list<string>` of `CalendarEntryType` values, default all).

- [ ] **Step 1: Write the failing tests** (Livewire on the widget, `2026-11-09`)

```php
it('shows the account events with their colours', function () {
    Expense::factory()->create(['spent_on' => '2026-11-12', 'description' => 'Coworking', 'is_paid' => true, 'net_amount' => 15000, 'vat_rate' => 0]);

    Livewire::test(ActivityCalendarWidget::class)
        ->assertCalendarHasEvent(['title' => 'Coworking · '.Money::format(15000), 'color' => 'gray', 'allDay' => true]);
});

it('colours obligations by status and marks projected and done events', function () { /* overdue → danger, due → warning, done → success + className contiu-calendar-done; projected → gray + className contiu-calendar-projected */ });

it('filters by type', function () {
    // one expense and one payment in November
    Livewire::test(ActivityCalendarWidget::class)
        ->set('filters.types', [CalendarEntryType::Payment->value])
        ->assertCalendarEventCount(1);
});

it('opens the record or the obligation page when an event is clicked', function () { /* event has 'url' = ExpenseResource edit URL */ });
```

(Check the exact array keys `assertCalendarHasEvent` sees for `className`/`color` with `getCalendarEvents()` first; assert on those.)

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement**

- `fetchEvents()` → `ActivityCalendar::entries($info->start, $info->end->subDay(), selected types)` mapped to `EventData::make()->id()->title()->start($date)->allDay()->color()->tooltip($detail)->url()` (when url) and `extraProperties(['className' => …])` for done/projected.
- Colours: obligation `overdue → danger`, `due → warning`, `done → success`; payment `primary`; receivable `overdue → danger`, else `info`; expense `gray`; projected types `gray`.
- `filtersSchema()`: one `CheckboxList::make('types')->hiddenLabel()->options(CalendarEntryType::class)->default(all values)->columns(3)`; `$filtersLayout = FiltersLayout::AboveContent`; `$hasDeferredFilters = false`; keep session persistence (plugin default). Redefine the `filters` tool icon to `Phosphor::Funnel` in `tools()` if the tool is shown.
- Theme CSS: `.contiu-calendar-projected` (dashed border, italic, transparent fill) and `.contiu-calendar-done` (`line-through`, reduced opacity), both readable in dark mode; `npm run build`.

- [ ] **Step 4: Run** — expect PASS.

- [ ] **Step 5: Pint, whole suite, commit** — widget, theme.css, build output if tracked, test. Message: `Calendário: eventos coloridos e filtros por tipo`.

---

### Task 5: "Prazos a seguir" column

**Files:**
- Create: `app/Filament/Analysis/Widgets/UpcomingDeadlines.php` (a `Filament\Widgets\Widget`)
- Create: `resources/views/filament/analysis/widgets/upcoming-deadlines.blade.php`
- Modify: `app/Filament/Pages/Calendar.php` (grid: calendar `columnSpan(['lg' => 3])`, column `columnSpan(['lg' => 1])`, stacked below `lg`)
- Test: `tests/Feature/Analysis/CalendarPageTest.php`

**Interfaces:**
- Consumes: `TaxObligations::pending()`, `upcoming()`, `TaxObligation::daysLeft(CarbonImmutable $today)`.

- [ ] **Step 1: Write the failing tests**

```php
it('lists the deadlines with the overdue ones first', function () {
    // account since 2026-10-01, settings complete, nothing marked done → ss:2026-10 (20 Oct) and
    // ss-declaration:2026-Q3 (31 Oct) overdue, then ss:2026-11 due on 20 Nov
    Livewire::test(UpcomingDeadlines::class)
        ->assertSeeInOrder(['Prazos a seguir', 'em atraso', 'Declaração trimestral da Segurança Social', 'em atraso', '11 dias']);
});

it('says when nothing is pending', function () {
    // settings incomplete and nothing due → only notes, no pending
    Livewire::test(UpcomingDeadlines::class)->assertSee('Sem prazos por cumprir');
});

it('shows the column beside the calendar on the page', function () {
    $this->get(Calendar::getUrl())->assertOk()->assertSee('Prazos a seguir');
});
```

- [ ] **Step 2: Run** — expect FAIL.

- [ ] **Step 3: Implement.** Rows: `pending()` (already overdue-first by deadline) then `upcoming()`; each row: title (+ amount when set), the obligation's period (as in the "Este mês" card), deadline line from `dueOn` (`'até 20 nov.'`, or `'10 a 20 nov.'` when `opensOn` is set), and a badge `'em atraso'` (danger) or `'N dias'` / `'hoje'` (warning when ≤ 7 days, gray otherwise). Title links to the obligation `url` when present. Use the card markup/classes already used in `resources/views/filament/widgets/tax-obligations-card.blade.php` (Filament section, `fi-badge`), so it fits both themes.

- [ ] **Step 4: Run** — expect PASS.

- [ ] **Step 5: Pint, whole suite, commit** — the two new files, `Calendar.php`, test. Message: `Calendário: coluna com os prazos a seguir`.

---

### Task 6: Final check in the browser

- [ ] **Step 1:** `npm run build`; open `/{account}/calendario` with demo data in **light** and **dark** mode: month, Agenda and Ano views; filters; click an expense and an obligation; the "Prazos a seguir" column; a phone-width check (list view first). Screenshot each mode.
- [ ] **Step 2:** `php artisan test --compact --parallel` — all green.
- [ ] **Step 3:** Fix anything found with a test first, then commit only touched files.
