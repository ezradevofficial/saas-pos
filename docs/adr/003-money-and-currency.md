# ADR 003: Money and currency

Status: Accepted as a convention (Sprint 1). The currency tables, rates and conversions are built in Sprint 2 (CUR-01 to CUR-08).

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
- **Tenant currencies** (`tenant_currencies`, CUR-01): the currencies a tenant uses, with its decimals, cash rounding in minor units and an active flag. Sign-up and company creation activate the country's currencies (KE: KES and USD; CD: USD and CDF, CDF cash rounding 50) and the base currency, never overwriting an existing row. `currencies:sync` does the same for tenants created earlier. `GET|POST|PATCH tenant/currencies` (`core.currency.view` anywhere to read, `core.currency.edit` at tenant scope to change). The Cashier and Waiter templates hold `core.currency.view`, since they take payment in these currencies. `currencies:sync` skips, with a warning, a company base code missing from the catalogue. A currency a company uses as base or reporting currency cannot be deactivated (`currency_in_use`).
- **Decimals lock.** Decimals change only while no amount in the currency is stored (`currency_decimals_locked`). `App\Core\Currency\CurrencyUsage` is a registry: modules that store amounts register a checker. None is registered yet.
- **Decimals lookup.** `CurrencyDecimals::for($code)`: the tenant's value, else the catalogue default, else 2.
- **Company currencies** (CUR-02): `GET|PUT companies/{company}/currencies`, at the company's scope. The base currency and up to three ordered reporting currencies (`too_many_reporting_currencies`), all active tenant currencies. Posting modules call `BaseCurrencyLock::lock($company)` inside the posting's transaction, **before** computing base amounts, and use the code it returns as the base currency. It re-reads the company row `FOR UPDATE`, so a stale `Company` instance or a concurrent base change cannot slip in. It sets `companies.base_currency_locked_at` once, after which the base cannot change (`base_currency_locked`, also through `PATCH companies/{company}`, which also refuses a base that is one of the company's reporting currencies). `companies.base_currency` has no foreign key to `currencies`: companies existed before the catalogue, and the catalogue is filled after the migrations. Validation checks it instead.
- **Money.** `App\Core\Currency\Money` is immutable, holds a `BigInteger` of minor units and a code, and serialises as `{"amount_minor": "12345", "currency": "KES"}`. `parse` refuses more decimals than the currency has. `multiply` rounds once (HALF_UP by default). `allocate` gives each part its floored share, then hands out the leftover units by largest remainder, so parts always sum to the total. Mixing currencies throws `CurrencyMismatch`.
- **Input.** Form Requests validate typed amounts with `App\Core\Currency\Rules\MoneyAmount::in('CDF')` or `::fromField('currency')`, optionally `->min()` and `->max()`. The rule takes a decimal string with at most the currency's decimals and refuses floats. The validated value then goes through `Money::parse`, which stays strict.

## Implementation: exchange rates, conversion, FX snapshot, tender (CUR-03, CUR-04, CUR-06 to CUR-08)

- **Rates** (`exchange_rates`, per company, append-only, RLS): `base`, `quote`, `kind` (`reference` from a feed, `shop` typed by a person), `mid` required and `buy`/`sell` optional, all `numeric(18,8)`, meaning **1 base = mid quote** (USD/CDF 2850.00000000). `effective_at`, `source` (`manual` or the feed name), `entered_by`. Unique per (company, base, quote, kind, effective_at). Audited as `core.exchange_rate.create`.
- **Which rate applies** (`ExchangeRates::current($company, $from, $to, $side, $at)`): among rates effective on or before `$at` (now by default), the latest `shop` rate of the pair as asked; if the company has none, the latest `reference` rate. Only when the pair has no rate of either kind in the asked direction is the stored inverse used, inverted at 8 decimals HALF_UP, with buy and sell swapped and inverted (buy' = 1/sell, sell' = 1/buy). `$side` is mid, buy or sell; a missing buy or sell falls back to mid. No rate: `RateUnavailable`, 422 `rate_unavailable`. (CUR-03's "shop rate defaults to the reference rate" is this fallback.)
- **Stored direction.** An 8-decimal inverse of a large rate keeps few significant digits (1/2850 = 0.00035088). `ExchangeRates::stored()` returns the same choice without inverting, and `Converter` and `FxSnapshot` convert in either direction of a rate (multiply from its base, divide from its quote), so conversions, `toBase` and the tender maths never go through an inverted rate.
- **Conversion** (`Converter::convert($money, $to, $rate, $rounding = HALF_UP)`): one rounding, to the target's minor unit. **Drift rule:** a document converts each line, rounds it, and stores the sum of the rounded lines; it never recomputes from the converted total. The two may differ by at most half a minor unit per line (tested with 250 lines, CDF to USD and back, at an 8-decimal rate).
- **FX snapshot** (CUR-04): `Converter::toBase($money, $company, $at)` returns `['base' => Money, 'snapshot' => FxSnapshot]`. Posting modules call `BaseCurrencyLock::lock()` first, then store both. `FxSnapshot` holds `rate` (8 decimals), `baseCurrency`, `kind` and `effectiveAt`. **`fx_base_currency` is the base of the rate as quoted**, not necessarily the company's base: 1 `fx_base_currency` = `fx_rate` of the other currency (the document's or the company's base, whichever it is not). A document already in the base currency gets an identity snapshot (rate 1, no kind or time). `FxSnapshot::convert()` re-applies the frozen rate with the same rounding, so the stored base amount is reproducible and never changes when rates do. Cast: `'fx' => FxSnapshot::class.':fx'`.
- **Migration macros** (registered by `CurrencyServiceProvider`): `$table->money('total')` adds `total_minor bigint` and `total_currency char(3)` (`nullable: true` for optional amounts); `$table->fxSnapshot('fx')` adds `fx_rate numeric(18,8)`, `fx_base_currency char(3)`, `fx_rate_kind varchar(10)` and `fx_rate_effective_at timestamptz`, all nullable.
- **Tender** (CUR-06, `TenderCalculator::calculate($due, $tenders, $changeCurrency, $company)`): each tender is converted into the due currency exactly, at the current mid rate in its stored direction.
  - `paidInDue` is the exact sum rounded **down** to the due currency's minor unit; `remaining` = due - paid, never negative.
  - An amount still due, asked in another currency (`amountDueIn`), is rounded **up** to that currency's cash rounding: the customer never underpays (USD 8.50 at 2,850 = CDF 24,225, asked as CDF 24,250).
  - `change` is the exact overpayment converted into the change currency and rounded **down** to its cash rounding: the shop never over-gives, change is never negative.
  - `rounding_minor` (due currency, minor units, half up) is what the shop keeps from rounding the change, for later accounting. `overpaid` is true when `paidInDue` exceeds the amount due.
- **Endpoints:** `GET companies/{company}/exchange-rates` (history, newest first, `?pair=USD/CDF&from=&to=&kind=&per_page=`; dates in the company's time zone), `GET companies/{company}/exchange-rates/current` (`?pair=` for one pair, inverse computed, both currencies active; otherwise every pair the company has), both with `core.exchange_rate.view` at the company or beneath it (cashiers at an outlet; out of sight 404, in sight without it 403). `POST companies/{company}/exchange-rates` enters a shop rate (both currencies active tenant currencies, buy <= mid <= sell, `effective_at` defaults to now) and needs `core.exchange_rate.override` at the company's scope.
- **Tolerance** (CUR-07): a new shop mid is compared with the previous rate of the pair (shop or reference, either direction, effective at or before the new one). A change above the company's `rate_tolerance_percent` (default 5) still saves the rate, adds a `rate_alerts` row (change stored at most 99999.9999 %), audits `core.exchange_rate.alert` and answers `meta.warning` (`rate_tolerance_exceeded`). Delivering the alert as a notification comes with the notification centre (phase 3).
- **Reference feeds** (CUR-03): `companies.rate_feed` is `none` (default), `cbk` or `bcc`. `exchange-rates:fetch` runs daily at 06:30 and queues `FetchReferenceRates` for every active company with a feed (tenants listed as the owner, companies read under RLS). The job asks the feed for 1 base = mid quote for the tenant's other active currencies and stores new rows as `reference`. `CbkFeed` and `BccFeed` read `services.rate_feeds.{cbk,bcc}.url` (`RATE_FEED_CBK_URL`, `RATE_FEED_BCC_URL`): no bank page is scraped; the configured endpoint answers a normalised JSON list (see `EndpointFeed`). Without a URL the driver throws `FeedNotConfigured`, which the job logs and skips. Tests use `FakeRateFeed`.
- **Permissions:** `core.exchange_rate.view` (Branch Manager, Cashier, Waiter, Accountant; Read-only Auditor through `*.view`) and `core.exchange_rate.override` (Owner and Admin through their wildcards).

## Consequences

- Sums are exact, and rounding happens at known points, on the server and on the device alike.
- Every money column comes in pairs (`*_amount bigint`, `currency char(3)`), plus `rate` and `base_*_amount` on multi-currency documents. Migrations must follow the pattern, and review checks it.
- The web and POS formatters are duplicated (same output, same tests). Move them into a shared package once a third consumer appears.
- A currency whose decimals change (rare; ISO revisions) needs a data migration of its amounts. Effective-dated currency settings keep history readable.
