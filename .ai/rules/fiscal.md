---
paths:
  - 'app/Support/Tax*'
  - 'app/Support/SocialSecurity.php'
  - 'app/Support/Irs*'
  - 'app/Support/*Vat*'
  - 'app/Support/TerritorialTurnover.php'
  - 'app/Support/FinanceSettings.php'
  - 'app/Filament/Support/TaxSettingsFields.php'
  - 'app/Filament/Pages/Auth/**'
---

# Fiscal rules

## The legal reference is `docs/fiscal/memoria-fiscal-ti-eni-portugal-2026.md`
It holds the Portuguese tax and Social Security rules for freelancers and sole traders (IRS category B, VAT, Social Security, invoicing, calendar), with the legal article and an official link per rule. It was researched on 2026-11-11 and was not checked independently against the Diário da República.

Do not read it whole (about 1,900 lines). Search it by rule ID (`SS040`, `IVA08`, `INI001`, ...) or by topic, and read that block.

## Check the rule before changing a calculation, an obligation or the sign-up questions
Find the rule that covers the change and follow its conditions, including the boundary cases it names (equal to a limit versus above it). Name the rule ID in the PHPDoc of the method that applies it.

## A rule marked `NÃO CONFIRMADO` never becomes an automatic calculation
Show the user why the value cannot be worked out and where to confirm it. `DOUTRINA` is guidance from the tax or Social Security authority: it can be applied, and the PHPDoc says it is guidance.

## What the app deduces is a proposal until the user confirms it
An exemption, a rate or a regime deduced from the user's answers is shown with its reason and where to confirm it (Segurança Social Direta, the activity declaration). The estimate uses the confirmed fiscal profile.

## Values that change every year live in per-year tables
They are in `config/contiu.php` (IAS, the art. 53.º limits, the art. 25.º deduction, the payments-on-account factor, the IRS brackets) and are read through `App\Support\YearlyParameter`. Add a new yearly value the same way, never as a constant, and take the year from the period the value applies to (a contribution of January 2027 uses the 2027 IAS). Each new year needs its row in every table: until then the previous year's value is used and flagged as provisional.

## Deadlines go through `App\Support\PortugueseDeadline`
A deadline on a weekend or national holiday moves to the next working day; one in August ends on 31 August. Never compare against the raw legal date.
