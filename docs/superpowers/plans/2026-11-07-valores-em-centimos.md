# Valores em cêntimos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Store and compute every money amount as integer cents, so sums and comparisons are exact; euros only at the edges (forms, tables, charts, imports, emails).

**Architecture:** Money columns become integers (cents) through a conversion migration that keeps current data (`ROUND(x * 100)`). Models cast them to `int`. `App\Support\Money` owns conversion and the single rounding rule (half up, to the cent). Filament inputs convert euros↔cents (`MoneyInput`), columns use `->money('EUR', divideBy: 100)`. All tolerance hacks (`0.009`, `< 0.01`) go away.

**Spec:** Pedro's request (2026-11-07): "usar na base de dados sempre em cêntimos", accepted the cost; app is local.

## Global Constraints

- Money columns (cents, integer): `projects.net_amount`, `retainers.net_amount`, `recurring_expenses.net_amount`, `expenses.net_amount`, `one_off_incomes.net_amount`, `receivables.amount`, `payments.amount`, `bank_transactions.amount`, `bank_transactions.balance`; settings `current_bank_balance`, `vat_exemption_threshold`.
- Rates stay decimal fractions (`vat_rate` 0.2300, SS 0.214, shares).
- Rounding rule: `(int) round($value, 0, PHP_ROUND_HALF_UP)` in `Money` only — VAT per document line (`Money::vat()`), net from gross (`Money::netFromGross()`).
- `Money::format(?int $cents)` takes cents only (strict int: a float is a bug).
- The UI never shows cents: users type and read euros.
- Tests: user-facing inputs in tests stay in euros (form data); model/factory values are cents.
- No git commits (Pedro postponed git).

## Review Focus

- A form that saves 151,98 must store 15198, and editing it must show 151,98 (round trip).
- Sums over many payments (e.g. 0,10 + 0,20) must equal exactly 30 cents.
- VAT on 22,33 € at 23% → 5,14 € (514), total 27,47 € (2747), matching the invoices.
- Bank import "1,500.00" / 1500.5 / "-151.00" → 150000 / 150050 / -15100.
- Charts and stats show euros (not ×100).

## Tasks

1. **Money core** — `Money::{format,toCents,toEuros,vat,netFromGross}`, `MoneyInput`, `HasVatAmounts` in cents, model casts `integer`; tests in `tests/Unit/MoneyTest.php`.
2. **Conversion migration** — `2026_11_07_160000_store_money_in_cents.php`: per column `UPDATE … SET col = CAST(ROUND(col * 100) AS INTEGER)` then `->bigInteger()->change()`; settings rows ×100; `down()` divides back.
3. **Domain logic in cents** — Receivable (received/outstanding/status, `open` scope), Payment, BankTransaction (`difference`, `absolute_amount`), ApplyBankTransaction, MatchBankTransactions, RecordOneOffIncome, InstalmentPlanner, GenerateRecurringExpenses, ScheduleProjector, Support reports (PeriodSummary, MarginReport, TaxEstimator, CashForecast, MonthReview, ClientStatement, Change), FinanceSettings money getters return cents.
4. **UI** — every `->money('EUR')` on cents state gets `divideBy: 100`; every money `TextInput` → `MoneyInput`; `Money::format` callers pass cents; chart datasets convert to euros; mails and statement export.
5. **Imports** — Excel importer and bank parser produce cents.
6. **Verify** — full suite, run migration on local DB (backup first) and compare totals before/after, browser check of dashboard, extrato, margens, previsão, impostos and a form round trip.
