# Phase 2: Master Data and Multi-currency Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Shared master data (parties, items, taxes, payment methods, dimensions) with change history, and a complete multi-currency layer (currencies, base/reporting currencies, exchange rates with shop-rate override, conversion, tender/change maths) that every later module builds on.

**Architecture:** Core areas `api/app/Core/Currency`, `api/app/Core/MasterData`, `api/app/Core/CountryPacks` following Sprint 1 patterns: tenant tables with forced RLS via `tenantId()` + `Rls::enable`, `BelongsToTenant`, `Archivable`, `Audited`; endpoints with Form Requests + policies through `ScopeResolver`; company-scoped records implement `HasScope`. Money is `bigint` minor units + ISO code, arithmetic with `brick/math` (already installed); rates `numeric(18,8)`. Global reference data (ISO currencies, country packs) lives in global tables written only by the owner role. Web pages follow the Sprint 1 settings pages.

**Tech stack:** as Sprint 1 (Laravel 13, PostgreSQL, PHPUnit; React 19, TanStack Query, shadcn/ui + ds components). PHP extensions `intl` and `bcmath` are available; `brick/math` is installed.

**Spec:** `docs/platform-core-spec.md` §5 (MD-01..07, CUR-01..09), §11.1 (CP-01, CP-02, L10N-03), §2 (TEN-07, TEN-08); `CLAUDE.md` (Data conventions, country packs as data, never invent tax rates); `docs/adr/003-money-and-currency.md`; `docs/superpowers/reports/2026-10-08-sprint-1.md` (carried items).

## Global Constraints

- Everything in Sprint 1's Global Constraints still applies (no product name; ports 3008/8008; forced RLS on every tenant table + isolation suite; UUID v7; archive not delete; Form Request + ScopeResolver policy per endpoint; en+fr strings; tokens-only styling; tests with each task, run only touched tests; Playwright walkthrough for UI tasks; branch per task, merge to `main`).
- Money: `bigint` minor units + `char(3)` currency; never floats; conversions with `brick/math` `BigDecimal`/`BigInteger`, rounding `RoundingMode::HALF_UP` unless a currency's cash rounding says otherwise.
- Currency decimals: default from ICU (`NumberFormatter` fraction digits) EXCEPT the project overrides `CDF => 0` (CLAUDE.md); tenant may not change decimals of a currency once any amount in it is stored.
- Exchange rates `numeric(18,8)`; every multi-currency document stores currency, rate used, and base-currency amounts (CUR-04).
- Tax and payroll rates are never invented. Country packs ship tax code structure; rates the team cannot confirm from an official source are stored as `NULL` with `needs_confirmation = true`, surfaced in the UI as "Rate needed", and listed in the phase report.
- Global reference tables (`currencies`, `country_packs`, `country_pack_*`) have no `tenant_id`; the runtime role gets `SELECT` only (REVOKE writes, as for `permissions`); writes via owner commands.
- New list endpoints that accept `search`/filters must be added to the isolation suite's `LIST_QUERY_PARAMETERS` and exercised (the suite fails otherwise).

## Review Focus

1. **Rounding drift:** converting and summing many lines must equal converting the total within the documented rule (line-level rounding, then sum); tested with CDF (0 dp) ↔ USD (2 dp) at rates with 8 decimals.
2. **Rate used is frozen:** later rate changes never alter stored document amounts (CUR-04): test by storing an `FxSnapshot`, changing rates, re-reading.
3. **Shared vs per-company master data (TEN-08):** switching a data type from shared to per-company must not orphan or expose records; per-company records invisible to users scoped to another company.
4. **Barcode / code uniqueness:** unique within the sharing scope (tenant if shared, company if per-company), case-insensitive codes, barcode normalised (trim, no spaces).
5. **Mixed tender change:** change computed in the chosen currency using the shop rate and that currency's cash rounding; never negative; overpay in one currency with change in another.

---

### Task 1: Deploy-time environment guard and seeder connection (carried from Sprint 1)

**Files:** `api/app/Core/Support/EnvironmentGuard.php`, `api/app/Providers/CoreServiceProvider.php`, new `api/app/Core/Support/Console/Preflight.php` (`php artisan app:preflight`), `.github/workflows/deploy-target.yml`, `api/app/Core/Rbac/Console/SyncPermissions.php`, tests `api/tests/Feature/Core/Support/EnvironmentGuardTest.php`, `api/tests/Feature/Core/Rbac/SystemRoleRefreshTest.php`, README, ADR 001.

- Guard enforces only on HTTP kernel requests, queue workers (`Queue::looping` first tick) and `schedule:run`/`schedule:work`; never during console bootstrap commands (`package:discover`, `config:*`, `optimize*`, `down`, `up`, `app:preflight` itself reports instead of throwing).
- `app:preflight` prints each check (mail transport, SMS driver, cache store, queue connection, DB roles are not superuser/bypassrls, APP_KEY set, APP_DEBUG false outside local) and exits non-zero on failure.
- Deploy: `rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php` before `composer install`; run `php artisan app:preflight` after `config:clear` and before `migrate`.
- `SyncPermissions::refreshSystemRoles` runs every per-tenant refresh on `TenantContext::CONNECTION` (runtime role) explicitly, regardless of the default connection; test by invoking the seeder path with populated tenants and asserting RLS applied (owner-default connection does not leak other tenants' roles into a tenant's refresh).
- Tests: guard does not throw for `package:discover` with production-like stale config; does throw on an HTTP request; preflight exit codes.

### Task 2: Currency core — catalogue, tenant currencies, company base/reporting, Money

**Files:** `api/app/Core/Currency/{Money.php, Currencies.php (ISO catalogue service), Models/{Currency.php (global), TenantCurrency.php, CompanyCurrency.php}, Http/...}`, migrations `currencies` (global: code pk, numeric_code, name_en, name_fr, default_decimals, active_in_iso), `tenant_currencies` (tenant_id, code fk, decimals, cash_rounding_minor bigint default 1, active, timestamps), `company_reporting_currencies` (company_id, code, position 1..3), `companies.base_currency_locked_at`; command `currencies:sync` (owner) filling `currencies` from ICU (`ResourceBundle` currency names en/fr + `NumberFormatter` fraction digits) applying the `CDF => 0` override; web money lib already handles decimals — add `GET currencies` (ISO list) and tenant currency endpoints.

**Interfaces:**
- `Money` (immutable): `Money::ofMinor(int|string $minor, string $currency)`, `::parse(string $decimal, string $currency, CurrencyDecimals $d)`, `plus`, `minus`, `multiply(string $factor, RoundingMode)`, `allocate(array $ratios)`, `isZero`, `isNegative`, `minor(): string`, `currency()`, `toDecimalString()`; throws `CurrencyMismatch`.
- `CurrencyDecimals::for(string $code): int` (tenant override → catalogue → 2).
- Endpoints: `GET currencies` (catalogue, cached), `GET|POST|PATCH tenant/currencies` (activate, cash rounding; decimals editable only while unlocked), `GET|PUT companies/{company}/currencies` (base currency — 422 `base_currency_locked` once locked; up to 3 reporting currencies — 422 `too_many_reporting_currencies`). Permission `core.currency.view|edit`.
- `BaseCurrencyLock::lock(Company $c)` called by future posting modules (tested directly).
- Seed on sign-up: tenant currencies KES+USD for KE companies, USD+CDF for CD companies (CDF cash rounding 50 by default, editable — CUR-01 example).

**Tests:** Money arithmetic incl. allocate remainder distribution and CDF 0dp; mismatch throws; catalogue has CDF decimals 0 and KES 2; base currency lock; reporting currency cap; RLS coverage passes; i18n keys.

### Task 3: Exchange rates, conversion, FX snapshot, tender maths

**Files:** `api/app/Core/Currency/{ExchangeRates.php, Converter.php, FxSnapshot.php, Tender/{TenderCalculator.php, TenderLine.php, TenderResult.php}, Feeds/{RateFeed.php (interface), CbkFeed.php, BccFeed.php, FakeRateFeed.php, NotConfiguredFeed.php}, Jobs/FetchReferenceRates.php}`, migrations `exchange_rates` (tenant_id, company_id, base char3, quote char3, kind reference|shop, buy/sell/mid numeric(18,8), effective_at timestampTz, source text, entered_by uuid null, created_at; unique (company_id, base, quote, kind, effective_at)), `rate_alerts` (tenant_id, company_id, pair, previous_mid, new_mid, change_percent numeric(9,4), entered_by, created_at), company setting `rate_tolerance_percent` (default 5); Blueprint macros `$table->money('total')` → `total_minor bigint`, `total_currency char(3)`; `$table->fxSnapshot('fx')` → `fx_rate numeric(18,8) null`, `fx_rate_base char(3) null`, `fx_rate_quote char(3) null`, `fx_rate_kind varchar(10) null`, `fx_rate_effective_at timestampTz(6) null` (as built: the snapshot is self-describing, 1 `fx_rate_base` = `fx_rate` `fx_rate_quote`, rate kept in its stored direction; ledger ruling P2 T3).

**Interfaces:**
- `ExchangeRates::stored(Company $c, string $a, string $b, ?CarbonImmutable $at = null): Rate` (as built) — among the pair's rates effective at or before `$at`, stored in **either direction**: the latest `shop` rate, else the latest `reference` rate; direction is only the final tie-break; returns the row in its stored direction, which every conversion uses. `ExchangeRates::current($c, $from, $to, $side = 'mid', $at)` inverts that row (8 dp) for display only. Throws `RateUnavailable` (422 `rate_unavailable`). (Ledger ruling P2 T3; ADR 003.)
- `Converter::convert(Money $m, string $to, Rate $rate): Money` (half-up to target decimals); `Converter::toBase(Money $m, Company $c, ?at): array{base: Money, snapshot: FxSnapshot}`.
- `FxSnapshot` value object (rate, base, quote, kind, effective_at) + cast for models.
- `TenderCalculator::calculate(Money $due, TenderLine[] $tenders, string $changeCurrency, Company $c, ?at): TenderResult{paidInDue: Money, remaining: Money, change: Money (in changeCurrency, cash-rounded down to the currency's cash rounding), overpaid: bool, roundingMinor: string, lines: [{tender, in_due, rate}]}` (CUR-06). As built: one rate row per pair and one time for the whole calculation; mid rate for tenders and change (ledger ruling P2 T3); paid floored to the due currency's minor unit; each line's `in_due` allocated by largest remainder (sums to paid exactly); `amountDueIn` rounds the amount still due up to the paying currency's cash rounding; change rounded down.
- Endpoints: `GET companies/{company}/exchange-rates?pair=USD/CDF&from=&to=` (history, paginated), `POST companies/{company}/exchange-rates` (shop rate; permission `core.exchange_rate.override`; tolerance check → creates `rate_alerts` row + audit `core.exchange_rate.alert`, still saves, response includes `warning`), `GET companies/{company}/exchange-rates/current`. Reference feed job scheduled daily per company with a configured feed; drivers return `NotConfigured` until the owner supplies endpoints (documented; tests use `FakeRateFeed`).
- Permissions `core.exchange_rate.view|override`.

**Tests:** current() precedence and inverse; frozen snapshot after rate change (Review Focus 2); conversion rounding drift with many lines (Review Focus 1); tender: USD 48.50 due, paid USD 20 + CDF 57,000 at 2,850 → change in CDF rounded to 50; overpay single currency; tolerance alert; feed job with fake driver; RLS.

### Task 4: Country packs and taxes

**Files:** `api/app/Core/CountryPacks/{Models/{CountryPack.php, PackTaxCode.php}, Console/PublishCountryPack.php}`, `api/country-packs/{KE,CD}/pack.json` (data files), `api/app/Core/MasterData/Taxes/{TaxCategory.php, TaxCode.php, TaxRate.php, TaxCalculator.php, PriceList.php}`, migrations: global `country_packs` (code, version, published_at, summary jsonb), global `country_pack_tax_codes` (pack_id, code, name_en, name_fr, kind vat|withholding|excise|exempt|zero_rated, rate numeric(9,4) null, needs_confirmation bool, effective_from date, effective_to date null, fiscal_code varchar null (eTIMS band / DGI group, null if unknown)); tenant `tax_codes` (company_id, code, name_en, name_fr, kind, pack_code null, archived_at), `tax_rates` (tax_code_id, rate numeric(9,4) null, effective_from, effective_to, needs_confirmation), `tax_categories` (company or shared per TEN-08 items setting: name, default tax_code per company via `tax_category_codes`), `price_lists` (company_id, name, currency, tax_inclusive bool, is_default).

**Rules:** Pack data files hold only what can be stated without inventing figures: structural codes (standard VAT, zero-rated, exempt, withholding) with `rate: null, needs_confirmation: true` unless a rate is definitional (zero-rated = 0, exempt = no rate). Do not fill standard VAT, excise or withholding percentages; the phase report lists them as owner/compliance inputs. `PublishCountryPack` (owner) loads the JSON into the global tables with a version; on company creation and via `POST companies/{company}/tax-codes/apply-pack` tenant tax codes are copied from the company's country pack. As built (ledger rulings P2 T4): publishing a new pack version propagates its rate periods to tenant tax codes copied from the pack whose rates the tenant has not edited (`tax_rates.source` = `pack` | `tenant`; tenant-entered rates are never overwritten), per tenant under RLS, audited as system; `TaxCalculator` and `TaxCode::rateOn` take an instant and resolve the date in the tax code's company time zone.

**Interfaces:** `TaxCalculator::forLine(Money $amount, TaxCode[] $codes, bool $inclusive, CarbonImmutable $date): TaxLineResult{net, tax[], gross}` — throws `TaxRateMissing` (422 `tax_rate_missing`, names the code) when a needed rate is null; inclusive back-calculation `tax = gross × r / (1 + r)` half-up per line. Endpoints CRUD for tax codes (rates are effective-dated: adding a rate closes the previous one), tax categories, price lists. Permissions `core.tax.view|edit`, `core.price_list.view|edit`.

**Tests:** pack publish idempotent per version; company creation copies codes; missing rate error; inclusive/exclusive maths incl. CDF 0dp; effective dating; runtime role cannot write global pack tables.

### Task 5: Master data sharing (TEN-08), parties (MD-01), duplicates (MD-06), history (MD-07)

**Files:** `api/app/Core/MasterData/{Sharing/MasterDataSharing.php, Parties/{Party.php, PartyController.php, ...}, History/HistoryController.php, Duplicates/DuplicateFinder.php}`, migrations `master_data_settings` (tenant_id, data_type items|customers|suppliers|employees, mode shared|per_company, changed_at), `parties` (tenant_id, company_id null, kind person|organisation, name, legal_name, tax_id, phones jsonb, emails jsonb, addresses jsonb, currency char3 null, payment_terms_days int null, credit_limit_minor bigint null, credit_limit_currency null, price_list_id null, tags text[] default '{}', roles text[] (customer, supplier, contact, employee_link), archived_at; GIN on tags and roles; trigram index on name (enable `pg_trgm` in an owner migration)).

**Rules:** When a data type is `per_company`, `company_id` is required and records are visible only to users who can view that company's scope; when `shared`, `company_id` is null and visible tenant-wide to holders of the permission. Switching shared → per_company requires every existing record to be assigned a company first (endpoint returns 422 `records_need_company` with a count); per_company → shared clears company_id after confirmation. `DuplicateFinder` returns likely duplicates on create/update (same normalised phone, same tax_id, name trigram similarity ≥ 0.6) as `meta.possible_duplicates` — never blocks. History: `GET history/{type}/{record}` (as built; ledger ruling P2 T5) for parties, items, item categories, units, tax codes, tax categories, price lists, payment methods, dimensions, companies, branches, locations, users, roles — reads `audit_logs` by auditable type/id under RLS, permission = view on the record, paginated, newest first, with actor names.

**Interfaces:** `GET|POST parties`, `GET|PATCH parties/{id}`, archive/restore, `?role=customer|supplier&search=&tag=&status=`; `GET|PUT master-data/settings`. Permissions `core.party.view|create|edit|archive`, `core.master_data_settings.edit`.

**Tests:** sharing visibility both modes, switching rules (Review Focus 3); duplicate warnings; history shows before/after; search isolation; RLS.

### Task 6: Items catalogue core (MD-02)

**Files:** `api/app/Core/MasterData/Items/{Item.php, ItemCategory.php, Uom.php, ItemUom.php, ItemBarcode.php, ItemImage.php, controllers, requests, resources, policies}`, migrations `item_categories` (tree via parent_id, name_en, name_fr, colour token name null — later LAY-05), `uoms` (code, name_en, name_fr, kind count|weight|volume|length|time), `items` (company_id null per TEN-08, code citext, name_en, name_fr, category_id, type stock|service|non_stock|kit, base_uom_id, tax_category_id, custom jsonb default '{}' (CF later), archived_at), `item_uoms` (item_id, uom_id, factor numeric(18,6) to base, is_sales_default, is_purchase_default), `item_barcodes` (item_id, uom_id null, barcode varchar normalised), `item_images` (item_id, disk, path, position); file storage disk `media` (local in dev, S3-compatible via env in prod).

**Rules:** code unique per sharing scope (case-insensitive); barcode unique per sharing scope; kit components table deferred to Inventory (type kit allowed, components later — note); image upload validated (jpeg/png/webp ≤ 2 MB), stored under `tenants/{tenant_id}/items/{item_id}/`; signed temporary URLs in resources. Seed default UoMs per tenant on sign-up (each, kg, g, l, ml, m, box, pack — names en/fr).

**Interfaces:** CRUD + archive/restore for categories, uoms, items; `POST items/{id}/images`, `DELETE item-images/{id}` (images are files, deletion allowed; audited); `GET items?search=&category=&type=&barcode=` (barcode exact match for POS lookup). Permissions `core.item.view|create|edit|archive`, `core.item_category.*`, `core.uom.view|edit` (as built: edit covers create, archive and restore; ledger ruling P2 T6).

**Tests:** uniqueness incl. case and spaces (Review Focus 4); per-company sharing; images upload/limit/signed url; history; search/isolation.

### Task 7: Payment methods (MD-04) and dimensions (MD-05)

**Files:** `api/app/Core/MasterData/{PaymentMethods/*, Dimensions/*}`, migrations `payment_methods` (company_id, type cash|mobile_money|card|credit|voucher|points|bank_transfer, name_en, name_fr, currency char3 null (required for cash), provider null (mpesa_ke|airtel_ke|vodacom_mpesa_cd|orange_money_cd|airtel_money_cd|afrimoney_cd|card_aggregator — adapters in phase 4), settings jsonb, position, archived_at), `departments`, `cost_centres`, `projects` (company_id, code, name, parent_id null, owner_user_id null (cost centre owner for APR-02), archived_at).

**Rules:** seed per company on creation: cash in each active currency of the company's country default set; mobile money entries per country (inactive until configured); card (inactive). Provider settings never returned in plain (secrets masked) — store secrets encrypted cast.

**Interfaces:** CRUD/archive + reorder for payment methods; CRUD/archive for each dimension. Permissions `core.payment_method.*`, `core.dimension.*`.

**Tests:** seeding per country; cash requires currency; secrets masked; scope; RLS.

### Task 8: Web — currencies & rates, taxes, payment methods, dimensions, master data sharing

**Files:** `web/src/pages/settings/{Currencies.jsx, ExchangeRates.jsx, Taxes.jsx, PaymentMethods.jsx, Dimensions.jsx, MasterDataSharing.jsx}` (+ subcomponents, tests), sidebar Settings group additions (permission-gated), `web/src/lib/money.js` additions (parse decimal input to minor, dual display), `web/src/components/ds/MoneyInput.jsx` (TextField with currency prefix, locale-aware parsing, minor-unit value).

**Behaviour:** Currencies: active currencies, cash rounding, company base (locked badge) and up to 3 reporting currencies. Exchange rates: per company pair list with current reference + shop rates, history table, "Set shop rate" dialog (warning shown when tolerance exceeded). Taxes: codes with effective-dated rates, "Rate needed" status badge for null rates, apply country pack, categories, price lists. Payment methods: list by type with reorder, activate, provider settings form (masked). Dimensions: tabs Departments / Cost centres / Projects. Master data sharing: per data type shared/per-company with the switching rules explained.

**Verification:** vitest per page; Playwright: set a CD company's shop rate USD/CDF, see history; mark a tax rate; screenshots; console clean.

### Task 9: Web — catalogue (items) and contacts (parties), history

**Files:** `web/src/pages/catalogue/{Items.jsx, ItemDetail.jsx, ItemForm.jsx, Categories.jsx, Units.jsx}`, `web/src/pages/contacts/{Parties.jsx, PartyDetail.jsx, PartyForm.jsx}`, `web/src/components/HistoryPanel.jsx`, sidebar groups "Catalogue" (Items, Categories, Units) and "Contacts" (Customers, Suppliers) gated by permissions, `web/src/lib/format.js` (dates/numbers per locale and company time zone — L10N-03).

**Behaviour:** Lists with search (debounced), filters, server pagination; item form with EN/FR names, category, type, base UoM, extra UoMs with factors, barcodes, tax category, images (upload/reorder/delete); party form with roles, contacts (multiple phones/emails), addresses, credit limit (MoneyInput), payment terms, tags; possible-duplicate warning banner; History tab on detail pages showing actor, time (company time zone) and changed fields.

**Verification:** vitest; Playwright walkthrough: create items with barcode and image, duplicate party warning, history tab, French spot-check, 390px screenshots.

### Task 10: Isolation, CI, docs

**Files:** `api/tests/Feature/Isolation/*` (extend parameter map and `LIST_QUERY_PARAMETERS` with all new routes/params; TwoTenants builds Phase 2 data), ADR 003 (completed: Money, rates, snapshot, tender rules), new ADR 007 (country packs as data; global reference tables; rate confirmation policy), roadmap phase 2 ticked, phase report `docs/superpowers/reports/<date>-phase-2.md` with rulings, deferred items and the list of tax figures needing owner/compliance input.

## Self-review notes

- Coverage: MD-01 (T5), MD-02 (T6), MD-03 (T4), MD-04 (T7), MD-05 (T7), MD-06 basic warnings (T5; merge later), MD-07 (T5 + T9), CUR-01 (T2), CUR-02 (T2), CUR-03 (T3; feeds stubbed pending endpoints), CUR-04 (T3), CUR-05 dual display helpers (T8 lib; POS phase 4), CUR-06 (T3 calculator; POS UI phase 4), CUR-07 (T3), CUR-08 (T3 converter), CUR-09 (phase 4 sync uses `exchange-rates/current`), CP-01/CP-02 tax part (T4), L10N-03 (T9), TEN-08 (T5).
- Deferred with reason: TEN-07 consolidated dashboards need sales data (phase 4); MD-06 merge (later); kit components (Inventory); real CBK/BCC feed endpoints and confirmed tax rates (owner inputs).
- Execution order: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10.
