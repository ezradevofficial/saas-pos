# ADR 003: Money and currency

Status: Accepted. Convention set in Sprint 1; the currency catalogue, rates, conversion, FX snapshot and tender maths were built in Phase 2 (CUR-01 to CUR-04, CUR-06 to CUR-08). Dual display (CUR-05), the POS payment screen (CUR-06 UI) and device rate sync (CUR-09) come with the POS in phase 4.

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

**Built in Phase 2** (sections below): the currency catalogue and activation (CUR-01); base and reporting currencies per company, with the base locked by the first posting (CUR-02); reference and shop rates (CUR-03); the FX snapshot (CUR-04); mixed-currency payment and change maths (CUR-06); rate tolerance alerts (CUR-07); conversion (CUR-08).

On the web, `decimalsOf(code, tenantCurrencies)` (`web/src/lib/money.js`) reads a currency's decimals from the tenant's settings, falling back to `CURRENCY_DECIMALS`; the settings and catalogue screens use it. `formatAmount` (and so the `Money` component) still reads the static table, which is right for KES, USD and CDF; it moves to tenant data when another currency is sold. Dual-price display (CUR-05) is built on the till (phase 4 Task 5): the sale total, the payment screen and the receipt show the total in the company's second currency (the first of base and reporting currencies other than the sale currency that has a rate), converted at the current shop rate and rounded up to that currency's cash rounding, which is what the payment screen asks for. The till's tender maths (`pos/src/pos/tender.js`) is a port of `TenderCalculator` with the same test vectors.

## Implementation: catalogue, tenant and company currencies, Money (CUR-01, CUR-02)

- **Catalogue.** `currencies` is a global table (code, numeric code, default decimals, `active_in_iso`). It stores no names: they are reference data read at render time from ICU in the request language (`App\Core\Currency\CurrencyNames::for($code, $locale)`, the `ICUDATA-curr` bundle), so adding a language adds no column (owner decision 2026-10-08). `php artisan currencies:sync` fills the table from the ICU data of ext-intl (`App\Core\Currency\IcuCatalogue`): codes from the `ICUDATA-curr` bundle, numeric codes from `currencyNumericCodes`, decimals from `NumberFormatter` fraction digits, except the override `CDF => 0`. A code is `active_in_iso` when ICU's CurrencyMap lists it as some territory's current legal tender (historic codes such as ZWD and funds or metals such as XAU are kept but inactive). The runtime role may only read the table (ADR 002). The sync runs in the seeders and on every deploy, and it forgets the catalogue cache (`App\Core\Currency\Currencies`).
- **Tenant currencies** (`tenant_currencies`, CUR-01): the currencies a tenant uses, with its decimals, cash rounding in minor units and an active flag. Sign-up and company creation activate the country's currencies (KE: KES and USD; CD: USD and CDF, CDF cash rounding 50) and the base currency, never overwriting an existing row. `currencies:sync` does the same for tenants created earlier. `GET|POST|PATCH tenant/currencies` (`core.currency.view` anywhere to read, `core.currency.edit` at tenant scope to change). The Cashier and Waiter templates hold `core.currency.view`, since they take payment in these currencies. `currencies:sync` skips, with a warning, a company base code missing from the catalogue. A currency a company uses as base or reporting currency cannot be deactivated (`currency_in_use`).
- **Decimals lock.** Decimals change only while no amount in the currency is stored (`currency_decimals_locked`). `App\Core\Currency\CurrencyUsage` is a registry: modules that store amounts register a checker. None is registered yet.
- **Decimals lookup.** `CurrencyDecimals::for($code)`: the tenant's value, else the catalogue default, else 2.
- **Company currencies** (CUR-02): `GET|PUT companies/{company}/currencies`, at the company's scope. The base currency and up to three ordered reporting currencies (`too_many_reporting_currencies`), all active tenant currencies. Posting modules call `BaseCurrencyLock::lock($company)` inside the posting's transaction, **before** computing base amounts, and use the code it returns as the base currency. It re-reads the company row `FOR UPDATE`, so a stale `Company` instance or a concurrent base change cannot slip in. It sets `companies.base_currency_locked_at` once, after which the base cannot change (`base_currency_locked`, also through `PATCH companies/{company}`, which also refuses a base that is one of the company's reporting currencies). `companies.base_currency` has no foreign key to `currencies`: companies existed before the catalogue, and the catalogue is filled after the migrations. Validation checks it instead.
- **Money.** `App\Core\Currency\Money` is immutable, holds a `BigInteger` of minor units and a code, and serialises as `{"amount_minor": "12345", "currency": "KES"}`. `parse` refuses more decimals than the currency has. `multiply` rounds once (HALF_UP by default). `allocate` gives each part its floored share, then hands out the leftover units by largest remainder, so parts always sum to the total. Mixing currencies throws `CurrencyMismatch`.
- **Input.** Form Requests validate typed amounts with `App\Core\Currency\Rules\MoneyAmount::in('CDF')` or `::fromField('currency')`, optionally `->min()` and `->max()`. The rule takes a decimal string with at most the currency's decimals and refuses floats. The validated value then goes through `Money::parse`, which stays strict.

## Implementation: exchange rates, conversion, FX snapshot, tender (CUR-03, CUR-04, CUR-06 to CUR-08)

- **Rates** (`exchange_rates`, per company, append-only, RLS): `base`, `quote`, `kind` (`reference` from a feed, `shop` typed by a person), `mid` required and `buy`/`sell` optional, all `numeric(18,8)`, meaning **1 base = mid quote** (USD/CDF 2850.00000000). `effective_at` is `timestamptz(6)` (microseconds kept, so two rates entered within a second do not collide), plus `source` (`manual` or the feed name) and `entered_by`. Unique per (company, base, quote, kind, effective_at). Audited as `core.exchange_rate.create`.
- **Which rate applies** (`ExchangeRates::stored($company, $a, $b, $at)`): among the pair's rates effective on or before `$at` (normalised to UTC once; now by default), **stored in either direction**, the latest `shop` rate; if the company has none, the latest `reference` rate. Order: `kind = 'shop' desc, effective_at desc`, then direction only as the last tie-break (alphabetical base, then newest id), so the same row is chosen whichever way the pair is asked. `stored()` returns that row in its stored direction, and every conversion uses it. `current($company, $from, $to, $side, $at)` inverts it for display when it is stored the other way: 8 decimals HALF_UP, buy and sell swapped and inverted (buy' = 1/sell, sell' = 1/buy). `$side` is mid, buy or sell; a missing buy or sell falls back to mid. No rate: `RateUnavailable`, 422 `rate_unavailable`. (CUR-03's "shop rate defaults to the reference rate" is this fallback.)
- **Stored direction.** An 8-decimal inverse of a large rate keeps few significant digits (1/2850 = 0.00035088), so conversions never use one: `Converter`, `FxSnapshot` and `TenderCalculator` convert in either direction of the stored rate (multiply from its base; divide from its quote at 20 decimals, then round).
- **Conversion** (`Converter::convert($money, $to, $rate, $rounding = HALF_UP)`): one rounding, to the target's minor unit (a division first carries 20 decimals; `FxSnapshot::convert` rounds identically). **Drift rule:** a document converts each line, rounds it, and stores the sum of the rounded lines; it never recomputes from the converted total. The two may differ by at most half a minor unit per line (tested with 250 lines, CDF to USD and back, at an 8-decimal rate).
- **FX snapshot** (CUR-04): `Converter::toBase($money, $company, $at)` returns `['base' => Money, 'snapshot' => FxSnapshot]`. Posting modules call `BaseCurrencyLock::lock()` first, then store both. The snapshot is **self-describing**: `rate` (8 decimals), `base()`, `quote()` (1 base = rate quote, the rate's stored direction), `kind` and `effectiveAt`. `FxSnapshot::convert(Money $from)` converts into the other currency of the pair (multiply when `$from` is the base, divide at 20 decimals when it is the quote, half up to the target's decimals; any other currency throws). `toBase` computes the base amount with the snapshot itself, so the stored amount is reproducible from the stored snapshot and never changes when rates do. A document already in the base currency gets an identity snapshot (base = quote, rate 1, no kind or time). Cast: `'fx' => FxSnapshot::class.':fx'`.
- **Migration macros** (registered by `CurrencyServiceProvider`): `$table->money('total')` adds `total_minor bigint` and `total_currency char(3)` (`nullable: true` for optional amounts); `$table->fxSnapshot('fx')` adds `fx_rate numeric(18,8)`, `fx_rate_base char(3)`, `fx_rate_quote char(3)`, `fx_rate_kind varchar(10)` and `fx_rate_effective_at timestamptz(6)`, all nullable.
- **Tender** (CUR-06, `TenderCalculator::calculate($due, $tenders, $changeCurrency, $company, $at)`): one time (UTC) and **one chosen rate row per pair** serve every conversion in a calculation (tenders and change), and `amountDueIn` picks the same row at the same time, so paying exactly the amount asked leaves nothing due. **Rate side: mid** for both tenders and change; buy and sell are stored but reserved (a later ruling may apply them to cash exchange).
  - `paidInDue` is the exact sum rounded **down** to the due currency's minor unit; `remaining` = due - paid, never negative.
  - Each line's `in_due` (its share of `paidInDue`, for receipts and later accounting) is allocated by **largest remainder**: every line's exact value is floored, then the minor units still missing to reach `paidInDue` (always fewer than the number of lines) go one each to the lines with the largest fractional parts, earlier lines first on a tie. Each line is within one minor unit of its own exact value, and the lines sum to `paidInDue` exactly. Example: 57 tenders of CDF 1,000 (35.0877 US cents each) plus USD 1.00 pay USD 21.00; five CDF lines show 36 cents, fifty-two show 35, and the USD line shows exactly 100.
  - An amount still due, asked in another currency (`amountDueIn`), is rounded **up** to that currency's cash rounding: the customer never underpays (USD 8.50 at 2,850 = CDF 24,225, asked as CDF 24,250).
  - `change` is the exact overpayment converted into the change currency and rounded **down** to its cash rounding: the shop never over-gives, change is never negative.
  - `rounding_minor` (due currency, minor units, half up) is what the shop keeps from rounding the change, for later accounting. `overpaid` is true when `paidInDue` exceeds the amount due.
- **Endpoints:** `GET companies/{company}/exchange-rates` (history, newest first, `?pair=USD/CDF&from=&to=&kind=&per_page=`; the pair filter matches rows stored either way, and each row then carries `direction`: `direct` or `inverse`; dates in the company's time zone), `GET companies/{company}/exchange-rates/current` (`?pair=` for one pair, inverted for display if needed, both currencies active; otherwise every pair whose two currencies are active), both with `core.exchange_rate.view` (or `override`, which implies it) at the company or beneath it (cashiers at an outlet; out of sight 404, in sight without it 403). `POST companies/{company}/exchange-rates` enters a shop rate (both currencies active tenant currencies, buy <= mid <= sell, `effective_at` defaults to now) and needs `core.exchange_rate.override` at the company's scope.
- **Tolerance** (CUR-07): a new shop mid is compared with the previous rate of the pair (shop or reference, either direction, effective at or before the new one). A change above the company's `rate_tolerance_percent` (default 5) still saves the rate, adds a `rate_alerts` row (change stored at most 99999.9999 %), audits `core.exchange_rate.alert` and answers `meta.warning` (`rate_tolerance_exceeded`). Delivering the alert as a notification comes with the notification centre (phase 3).
- **Reference feeds** (CUR-03): `companies.rate_feed` is `none` (default), `cbk` or `bcc`. `exchange-rates:fetch` runs daily at 06:30 and queues `FetchReferenceRates` for every active company with a feed (tenants listed as the owner, companies read under RLS). The job asks the feed for 1 base = mid quote for the tenant's other active currencies and stores new rows as `reference`. `CbkFeed` and `BccFeed` read `services.rate_feeds.{cbk,bcc}.url` (`RATE_FEED_CBK_URL`, `RATE_FEED_BCC_URL`): no bank page is scraped; the configured endpoint answers a normalised JSON list (see `EndpointFeed`); a malformed row is logged and skipped and the rest of the day loads: a row that is not an object, a mid, buy or sell that is not a decimal string, or one that is zero **after** rounding to the stored 8 decimals (`0.000000004` would be stored as 0, so it is skipped, never a failed job), or a bad time. Without a URL the driver throws `FeedNotConfigured`, which the job logs and skips. Tests use `FakeRateFeed`.
- **Permissions:** `core.exchange_rate.view` (Branch Manager, Cashier, Waiter, Accountant; Read-only Auditor through `*.view`) and `core.exchange_rate.override` (Owner and Admin through their wildcards).

## Rulings recorded in Phase 2

**Rate choice across directions (CUR-03).** Staff may type a pair either way (USD/CDF 2,850 or CDF/USD 0.00035088). The chosen rate is the latest `shop` rate of the pair stored in **either direction**, else the latest `reference` rate; within a kind the newest `effective_at` wins, and direction is only the final tie-break. So a shop override holds whichever direction staff typed it in, and the same row is chosen whichever way the pair is asked. "Inverse" in the API means the chosen row is stored the other way. Cost if wrong: none known.

**Snapshot columns.** `fx_rate`, `fx_rate_base`, `fx_rate_quote`, `fx_rate_kind`, `fx_rate_effective_at`: the rate is kept in its stored direction (no precision lost to an 8-decimal inverse), and the snapshot names both currencies, so reports and a later revaluation read it without context. Cost: one more `char(3)` column per snapshot.

**Tender uses the mid rate** for both converting tenders and computing change. The buy and sell sides are stored but reserved: a shop that wants a spread on change will need a per-company setting later. Rounding instead protects both sides: what is still due is asked rounded **up** in the paying currency's cash rounding (the customer never underpays), change is rounded **down** (the shop never over-gives), and `rounding_minor` reports what the shop kept. Cost if wrong: shops wanting a spread need that setting.

**BaseCurrencyLock contract (CUR-02).** Every posting module, inside its posting transaction and **before** computing base-currency amounts:

1. calls `BaseCurrencyLock::lock($company)`, which re-reads the company row `SELECT ... FOR UPDATE`, sets `base_currency_locked_at` the first time (idempotent, audited as a company update) and refreshes the given `Company` instance;
2. uses the code it **returns** as the base currency, never the one on a `Company` it already held;
3. computes base amounts with `Converter::toBase` and stores the amounts together with the `FxSnapshot`.

A concurrent base-currency change takes the same row lock, so it either committed first (and `lock()` returns the new base) or waits for the posting and then finds the base locked (422 `base_currency_locked`). A nested call uses a savepoint; the row lock is held until the outermost transaction ends.

## Consequences

- Sums are exact, and rounding happens at known points, on the server and on the device alike.
- Every money column comes in pairs (`*_amount bigint`, `currency char(3)`), plus `rate` and `base_*_amount` on multi-currency documents. Migrations must follow the pattern, and review checks it.
- The web and POS formatters are duplicated (same output, same tests). Move them into a shared package once a third consumer appears.
- A currency whose decimals change (rare; ISO revisions) needs a data migration of its amounts. Effective-dated currency settings keep history readable.
