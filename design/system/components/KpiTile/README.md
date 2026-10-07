# KpiTile

One headline number for a dashboard, with an optional change against a previous period.

**Props the consumer provides:** `label`, `value` (pre-formatted, with currency), optional `delta` (percent), `period`, `lowerIsBetter` (for costs, stock-outs, overdue counts).

**Do:** set `lowerIsBetter` where a rise is bad so the colour is right; keep labels to three words.
**Don't:** put more than four tiles in a row.
