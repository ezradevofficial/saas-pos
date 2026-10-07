# Money

Displays an amount with its currency code in tabular figures, optionally with a second currency underneath.

**Props the consumer provides:** `amount`, `currency` (ISO code), optional `secondary` `{amount, currency}` for dual-currency display, `size` (`lg` for totals), `tone`, `locale`.

**Do:** always pass the currency; use `secondary` in the DRC to show CDF under USD (or the reverse) at the shop rate. CDF shows no decimals.
**Don't:** format money yourself or drop the currency code; never colour an amount without a minus sign or word as well.
