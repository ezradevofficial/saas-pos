# ADR 003: Money and currency

Status: Accepted as a convention (Sprint 1). The currency tables, rates and conversions are built in Sprint 2 (CUR-01 to CUR-06).

## Context

The platform sells in Kenya (KES) and the DR Congo, where CDF and USD circulate side by side. Customers pay in a mix of currencies, and a document must always show what it was worth when it was posted. Floating-point money drifts, and so do recomputed conversions. CDF amounts are large: a mid-size shop's yearly turnover can exceed what a 32-bit integer or a JavaScript `Number` holds exactly.

## Decision

**Amounts:**

- Store every amount as a `bigint` in **minor units**, next to a `currency` column holding an ISO 4217 code (`char(3)`). Never use floats or `numeric` for amounts.
- Each currency has a number of decimals:

  | Currency | Decimals | Minor units |
  | --- | --- | --- |
  | KES | 2 | KES 12,450.00 is stored as `1245000` |
  | USD | 2 | |
  | CDF | 0 | CDF 135,000 is stored as `135000` |

  CUR-01 makes the decimals and cash rounding per currency data, not code.
- Arithmetic on amounts uses integers only. Percentages and conversions round once, explicitly, to the currency's minor unit.

**Rates:**

- Store exchange rates as `numeric(18,8)`, with an effective date and time and full history (CUR-03).
- Every multi-currency document stores:
  - the document currency
  - the rate used
  - the base-currency amounts next to the document amounts (CUR-04)
- Later rate changes never alter a posted document. Reports read the stored base amounts and never recompute them.

**Display:**

- Money always shows the currency code first: `KES 12,450.00`, `CDF 135,000`.

**Clients:**

- Both apps format money with `BigInt`, so an amount never passes through a float.
  - Web: `web/src/lib/money.js`, using `Intl.NumberFormat` for grouping.
  - POS: `pos/src/lib/money.js`, which groups by hand because Hermes' `Intl` cannot format `BigInt`.
- Both accept a `bigint`, a whole `number` or a digit string, and share one table: `CURRENCY_DECIMALS = { CDF: 0, KES: 2, USD: 2 }`.
- The design-system `Money` component uses these helpers and tabular figures.
- Amounts that can exceed 2^53 should travel as strings in JSON. The client helpers already accept strings.

**Sprint 2 builds the rest:**

- the currency catalogue and activation (CUR-01)
- base and reporting currencies per company, with the base locked after the first posting (CUR-02)
- reference and shop rates (CUR-03)
- dual-price display (CUR-05)
- mixed-currency payment and change (CUR-06)

The client `CURRENCY_DECIMALS` table will then come from the API instead of the code.

## Implementation: catalogue, tenant and company currencies, Money (CUR-01, CUR-02)

- **Catalogue.** `currencies` is a global table (code, numeric code, English and French names, default decimals, `active_in_iso`). `php artisan currencies:sync` fills it from the ICU data of ext-intl (`App\Core\Currency\IcuCatalogue`): names from the `ICUDATA-curr` bundles, numeric codes from `currencyNumericCodes`, decimals from `NumberFormatter` fraction digits, except the override `CDF => 0`. A code is `active_in_iso` when ICU's CurrencyMap lists it as some territory's current legal tender (historic codes such as ZWD and funds or metals such as XAU are kept but inactive). The runtime role may only read the table (ADR 002). The sync runs in the seeders and on every deploy, and it forgets the catalogue cache (`App\Core\Currency\Currencies`).
- **Tenant currencies** (`tenant_currencies`, CUR-01): the currencies a tenant uses, with its decimals, cash rounding in minor units and an active flag. Sign-up and company creation activate the country's currencies (KE: KES and USD; CD: USD and CDF, CDF cash rounding 50) and the base currency, never overwriting an existing row. `currencies:sync` does the same for tenants created earlier. `GET|POST|PATCH tenant/currencies` (`core.currency.view` anywhere to read, `core.currency.edit` at tenant scope to change). A currency a company uses as base or reporting currency cannot be deactivated (`currency_in_use`).
- **Decimals lock.** Decimals change only while no amount in the currency is stored (`currency_decimals_locked`). `App\Core\Currency\CurrencyUsage` is a registry: modules that store amounts register a checker. None is registered yet.
- **Decimals lookup.** `CurrencyDecimals::for($code)`: the tenant's value, else the catalogue default, else 2.
- **Company currencies** (CUR-02): `GET|PUT companies/{company}/currencies`, at the company's scope. The base currency and up to three ordered reporting currencies (`too_many_reporting_currencies`), all active tenant currencies. `BaseCurrencyLock::lock($company)` is called by posting modules in the posting's transaction. It sets `companies.base_currency_locked_at` once, after which the base cannot change (`base_currency_locked`, also through `PATCH companies/{company}`). `companies.base_currency` has no foreign key to `currencies`: companies existed before the catalogue, and the catalogue is filled after the migrations. Validation checks it instead.
- **Money.** `App\Core\Currency\Money` is immutable, holds a `BigInteger` of minor units and a code, and serialises as `{"amount_minor": "12345", "currency": "KES"}`. `parse` refuses more decimals than the currency has. `multiply` rounds once (HALF_UP by default). `allocate` gives each part its floored share, then hands out the leftover units by largest remainder, so parts always sum to the total. Mixing currencies throws `CurrencyMismatch`.

## Consequences

- Sums are exact, and rounding happens at known points, on the server and on the device alike.
- Every money column comes in pairs (`*_amount bigint`, `currency char(3)`), plus `rate` and `base_*_amount` on multi-currency documents. Migrations must follow the pattern, and review checks it.
- The web and POS formatters are duplicated (same output, same tests). Move them into a shared package once a third consumer appears.
- A currency whose decimals change (rare; ISO revisions) needs a data migration of its amounts. Effective-dated currency settings keep history readable.
