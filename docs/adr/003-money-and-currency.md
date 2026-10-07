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

## Consequences

- Sums are exact, and rounding happens at known points, on the server and on the device alike.
- Every money column comes in pairs (`*_amount bigint`, `currency char(3)`), plus `rate` and `base_*_amount` on multi-currency documents. Migrations must follow the pattern, and review checks it.
- The web and POS formatters are duplicated (same output, same tests). Move them into a shared package once a third consumer appears.
- A currency whose decimals change (rare; ISO revisions) needs a data migration of its amounts. Effective-dated currency settings keep history readable.
