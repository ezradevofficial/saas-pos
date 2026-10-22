# ADR 007: Country packs and taxes

Status: Accepted (Sprint 2, Phase 2 Task 4). Covers CP-01, CP-02, CP-03 and MD-03.

## Context

The platform serves Kenya and the DR Congo. Tax codes, rates and fiscal mappings differ by country and change by law on a given date. CLAUDE.md requires country packs to be data, never constants in code, and forbids inventing tax rates. Tenants also need to adjust taxes for their own situation without the platform overwriting their choices.

## Decision

### Packs are data

- A pack is a JSON file per country (`api/country-packs/{KE,CD}/pack.json`): tax code structure, effective-dated rate periods, `sources` (official references) and a `todo` list of figures still to confirm. It carries no names.
- **Labels are translated reference data, not pack columns** (owner decision 2026-10-08, platform-core-spec Conventions). The pack's name and each code's label live in `api/lang/{en,fr}/country_packs.php`, keyed by pack and code (`KE.name`, `KE.VAT_STD`), and are read through `App\Core\CountryPacks\PackLabels`. Neither `country_pack_tax_codes` nor the pack summary stores a name, so adding a language adds a translation file, never a column. A label missing from the files shows the code; `country-packs:publish` warns about every missing key, and a test checks that the shipped packs have a label in every supported language. Label changes are not pack versions.
- `PackFile` validates a file before it is published:
  - kinds are `vat`, `withholding`, `excise`, `exempt` or `zero_rated`
  - a rate is a percentage from 0 to 100 with at most 4 decimals, given as a string (`"12.5"`) or an integer, never a JSON float
  - an exempt code has no rate, and a zero-rated code has the rate 0
  - a null rate must say `needs_confirmation: true`
  - a code's periods never overlap, at most one is open-ended, and all have the same kind
  - no key starting with `name` or `label`, at the top or in a code (labels belong in the translation files)

### Global tables, written only by the owner

- `country_packs` (one row per version, with a content hash and a change summary) and `country_pack_tax_codes` (one row per code and period) have no `tenant_id`.
- The runtime role gets `SELECT` only. `country-packs:publish {code} {--file=}` writes them through the owner connection, under an advisory lock per pack. The seeder, `composer setup` and deploy publish KE and CD. The seeder fails if a publish fails.

### Versioning

- The hash of the file's canonical JSON decides whether a pack changed. The same content keeps the version in force, whatever the formatting.
- Changed content becomes version n + 1, with a summary of codes added, removed and changed (CP-03). Earlier versions are kept.

### Copying to tenants

- A new company gets the codes of its country's pack version in force: tax codes with `pack_code` set, and rate rows with `source = pack`. Each code gets one `name`, the pack label in the tenant's language (`tenants.default_locale`, else English). From then on the name is the tenant's: it can rename it, and neither apply-pack nor propagation ever touches it (propagation changes rate rows only).
- `POST companies/{company}/tax-codes/apply-pack` adds missing codes only. It never changes a code the company already has.
- Rates the tenant enters through `POST tax-codes/{id}/rates`, and the rates of codes the tenant creates, have `source = tenant`.

### Propagation of a new version (CP-02, CP-03)

Each publish (changed or not, so a run that stopped half-way is completed by the next one) then updates existing tenants:

1. The owner connection lists tenant ids only. Each tenant is entered with `TenantContext::run` on the runtime connection, under row-level security, in its own transaction.
2. For every company of the pack's country, each tax code copied from the pack (`pack_code`) is considered:
   - **Any rate row with `source = tenant`:** the code is skipped entirely. The tenant owns it from then on. Skipped codes are counted in the command output.
   - **All rate rows from the pack:** the code takes the pack's periods, matched by start date. A period with the same start updates that row (rate, end date, needs_confirmation). A new period is added. So a version confirming a rate from a date reaches the company from that date.
   - **A pack row whose start date is no longer in the pack:** the row is never deleted. The code is left unchanged and reported as a conflict for staff to resolve.
3. Each changed code is audited as the system (no user) as `core.tax.pack_update`, with the rates before and after, the pack and the version. The rate rows' own `core.tax_rate.*` entries are recorded too.
4. Re-publishing the same content changes nothing.

### No invented rates; "Rate needed"

- Rates the team cannot confirm from an official source, or support from public research, are stored as `NULL` with `needs_confirmation = true`. Since 2026-10-22 no shipped tax rate is null (the KE withholding VAT is researched, 2%). The phase report lists the figures needed, and the pack's `todo` list repeats them.
- The API returns `rate_needed: true` for a code whose rate in force is null or unconfirmed, and the UI shows it as "Rate needed".
- `TaxCalculator` refuses such a code with 422 `tax_rate_missing`, naming the code. No document is ever taxed with a guessed rate.
- A tenant confirms a period on its own start date: a null or unconfirmed rate is replaced, since nothing can have been taxed with it. Any other rate dated on or before the latest start is refused (`tax_rate_overlap`).
- Archived codes take no new rate, and the calculator refuses them (422 `tax_code_archived`).

### Dates

- Rate periods are calendar dates, inclusive.
- `TaxCode::rateOn` and `TaxCalculator::forLine` take an instant and resolve its date in the company's time zone. A sale at 00:30 in Nairobi takes that day's rate, even though it is still the previous day in UTC.

### Withholding

- `TaxCalculator::forLine` leaves withholding codes out unless it is called with `includeWithholding: true`. They then add no amount and are not checked for a rate.
- The buyer withholds this tax when paying; it is not charged on a sale line. Purchase invoices and payments, which do account for it, pass `true`.

### Default tax codes per category

- `tax_category_codes` holds a category's default tax code for each company. Each row is a configuration row: the link from a category to a company's code. It is not a business record.
- Clearing a company's default (`tax_code_id: null`) deletes the row. The change is audited on the category as `core.tax_category.codes_update`, with the codes before and after. The archive rule (TEN-06) applies to the category and the tax codes, not to these links.

### Researched values (2026-10-17)

- A pack row has a status (`ResearchStatus`): `confirmed`, `researched`
  (from public research: `confidence` high or medium, `researched_on`,
  https `source` URLs, `needs_confirmation: false`) or
  `needs_confirmation`. Every value row of every pack kind states its
  `status` explicitly: `PackFile` (tax) and the release schemas (payroll,
  HR, fixed assets) refuse a row without one; at run time an older row is
  read from `needs_confirmation`. Low-confidence research was never
  entered until 2026-10-22 (see below). A release that changes a row's status (researched → confirmed,
  needs_confirmation → researched) shows a warning per row, so the
  approver sees what they certify (`PackValidator::transitions`).
- A researched rate is used. `country_pack_tax_codes.research` and
  `tax_rates.research` keep its research; `TaxCalculator` marks each
  `TaxAmount` `researched`; the API says `rate_researched`. Propagation
  updates the research with the rate, so a version that confirms the rate
  clears it.
- Payroll and HR packs use the same status (docs/modules/payroll.md,
  hr.md). What was applied: docs/country-packs-research-2026-10.md.

### Low-confidence research (owner decision, 2026-10-22)

- The owner decided to use the researched values for every statutory item
  still blocked, knowing they are not confirmed by an accountant. This
  overrides, for those items only, CLAUDE.md's "never invent rates": each
  value still comes from a cited source, with `researched_on` and an
  honest confidence, and none is made up.
- `ResearchStatus` accepts `confidence: low` on a researched row and
  treats it as usable (`USABLE_CONFIDENCE` = high, medium, low).
  `ResearchStatus::low()` and `lowResearch()` flag it.
- Where it shows: the tax code API (`rate_low_confidence`), payroll runs
  (`uses_low_confidence_values`), the statutory rates page
  (`low_confidence`), leave types and requests (`low_confidence`) and the
  fixed assets tax classes. The web app shows "Researched, low confidence:
  not confirmed by an accountant" (en, fr) instead of the usual researched
  notice.
- The pack release validator warns on every low-confidence row
  (`country_packs.releases.low_confidence`), next to the status-change
  warnings, so approvers see each one.
- New pack fields the values needed: a leave type's `pay_share` (an exact
  fraction, CD maternity "2/3") and `seniority` (CD annual leave), a tax
  class's `first_year_rate` (KE machinery 50%, then the rate on the
  residue), and a holiday's `sunday_rule` (`next_free_day`, KE Public
  Holidays Act s. 4) with research fields on holiday rows.
- What was applied, with confidence and sources:
  docs/country-packs-research-2026-10.md, "Applied 2026-10-22 by owner
  decision".

## Consequences

- Legal changes reach every tenant that has not taken over a code, with a full audit trail, without per-tenant code.
- A tenant that enters one rate stops receiving pack updates for that code. Notifying tenants of new pack versions (CP-03) is still to be built.
- Removing a period from a pack never rewrites a tenant's history automatically. Staff must resolve it.
