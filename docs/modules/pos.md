# POS module spec (phase 4: retail mode)

Source: concept note sections 3, 5 (POS row), 6.4, 7.1 and 7.2; platform
core spec (NUM-01, NUM-02, CUR-04 to CUR-06, CUR-09, RBAC-06, AUTH-06 to
AUTH-08, NFR-03, NFR-04); ADR 003 (money), ADR 004 (offline sync). When this
file and the core spec disagree, the core spec wins.

## Scope

The POS is a module (`api/modules/POS`, permissions `pos.*`), sold and
switched on per tenant (RBAC-08). It works with only the core installed: it
reads core master data (items, units, tax codes, price lists, parties,
payment methods, currencies and rates) and raises events. It never reads
another module's tables.

**Phase 4 builds the retail mode**: scan or search, cart, pay; returns;
shifts. The other verticals of concept note section 3 (restaurant, quick
service, bar, hotel, pharmacy, wholesale, route sales, salon, fuel) are later
**modes built from the same blocks**: when payment happens (upfront, at the
end, on credit), what an order attaches to (nothing, table, tab, room,
appointment, route), fulfilment and pricing. Nothing in the retail tables
assumes there is no table or tab: a later mode adds its own tables that
reference `pos_sales`.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| POS-01 | **Sale.** A completed sale records the device, location, branch, company, shift, cashier (AUTH-07), optional customer, lines (item, unit, quantity, unit price, price list, discount, tax code, rate and amount, line total), payments and totals in minor units of the sale currency. The device generates its UUID v7. | Must |
| POS-02 | **Held sales.** A cart can be parked and resumed on the same device. Held carts live on the device only; they are not sales and never reach the server until completed. | Must |
| POS-03 | **Split and multi-currency tender (CUR-06).** One sale is paid by any mix of methods and currencies; change is given in a chosen currency, rounded down to its cash rounding. Each payment stores its currency, amount, amount in the sale currency and the rate used; the sale stores base-currency amounts and its FX snapshot (CUR-04). | Must |
| POS-04 | **Shifts and cash-up.** A cashier opens a shift with an opening float per currency and closes it by counting cash per currency. The server computes expected cash per currency and the variance. Pay-ins and pay-outs are recorded with a reason. | Must |
| POS-05 | **Voids and returns.** A void cancels a whole completed sale; a return refunds some lines or quantities. Both are new records that reference the sale (append-only, ADR 004). Each needs the permission (`pos.sale.void`, `pos.sale.refund`) or a manager override (AUTH-08); refunds respect the approver's `max_refund_amount` (RBAC-06). | Must |
| POS-06 | **Receipts.** Every sale and refund carries a number from the device's pre-allocated range (NUM-02), formatted by the document type's number format (NUM-01). The receipt shows the currency code first on every amount and, when the company sells in two currencies, both prices (CUR-05). Printed receipts are black on white; fiscal elements are locked (TPL). | Must |
| POS-07 | **Discounts and price overrides within limits.** A line discount above the cashier's `max_discount_percent`, or a price different from the list price, needs `pos.discount.give` / `pos.price.override` within limits, or a manager override (AUTH-08). Both users are recorded and audited (AUD-01). | Must |
| POS-08 | **Customers on sales.** A sale may name a customer (a core party with the customer role, shared or of the sale's company). | Must |
| POS-09 | **Offline selling (NFR-04).** The till sells for 7 days without a connection. Sales, shifts, cash movements, voids and refunds are uploaded idempotently by UUID; the device wins for completed sales (prices as sold are kept, differences are flagged, not refused); the server wins for master data. Receipts use cached rates (CUR-09) and the sale records the rate used. | Must |
| POS-10 | **Fiscal (KRA eTIMS, DRC DGI).** Every sale and refund is queued for the authority on the server and retried until accepted; the device shows "pending" while offline. (Phase 4 Task 3 builds the queue and adapters; this module raises the events it listens to.) | Must |
| POS-11 | **Tax at sale.** Tax per line from the item's tax category → the company's tax code, rate effective at the sale time in the company's time zone, inclusive or exclusive per price list (MD-03). A taxable item whose rate is "Rate needed" cannot be sold: the till blocks it (`sellable: false, reason: rate_needed`) and the API refuses the sale. Rates are never invented. | Must |
| POS-12 | **Back-office lists.** Sales and shifts lists with search, sort and export (EXP-01), scoped by company, branch and location (RBAC-04), and their detail. | Must |
| POS-13 | **Events.** `SaleCompleted`, `SaleVoided`, `SaleRefunded`, `ShiftOpened`, `ShiftClosed`, dispatched after commit, for the fiscal queue, Accounting, Inventory and Loyalty when those are active. | Must |

## Permissions

| Permission | What it allows |
| --- | --- |
| `pos.sale.view` | Read sales (back office) |
| `pos.sale.create` | Sell at the till |
| `pos.sale.print` | Reprint a receipt |
| `pos.sale.void` | Void a completed sale |
| `pos.sale.refund` | Refund lines of a sale, up to `max_refund_amount` |
| `pos.shift.view` | Read shifts |
| `pos.shift.open`, `pos.shift.close` | Open and close one's own shift |
| `pos.shift.manage` | Close another cashier's shift |
| `pos.cash.move` | Pay cash in or out of the drawer |
| `pos.price.override` | Sell at a price other than the list price |
| `pos.discount.give` | Give a line discount, up to `max_discount_percent` |

| `pos.sale.review` | Acknowledge a flagged sale |
| `pos.till.sign_in` | Sign in at the tills where the role is held (AUTH-06) |

Role templates: **Cashier** signs in, sells, prints, views sales and shifts,
and opens and closes their own shift; discounts need a manager, since
`pos.discount.give` approves overrides and its holders need 6-digit PINs
(AUTH-08); **Branch
Manager** holds `pos.*` (voids, refunds, overrides, cash movements, other
cashiers' shifts) within limits; **Accountant** and **Read-only Auditor** read
(`pos.*.view`). The Owner role has no limits; any other role without a limit
rule is not allowed the limited action (RBAC-06).

## Acceptance criteria (phase 4)

1. Uploading the same sale twice stores one row and answers the same result both times; a batch with one bad sale stores the others.
2. A sale naming another device's shift, receipt range or location, or another tenant's ids, is refused.
3. A sale with an item whose tax rate is "Rate needed" is refused with `rate_needed`, naming the item; the sync data marks the item not sellable.
4. A sale paid in USD and CDF with change in CDF stores each payment's rate and amount in the sale currency, the change, the rounding kept, and base-currency amounts.
5. Receipt numbers come from device ranges that never overlap, even when devices ask at the same time.
6. A refund above the approver's refund limit, or without permission or override, is refused; a refund never exceeds the quantity sold.
7. Closing a shift computes expected cash per currency (opening float + cash taken − change given + pay-ins − pay-outs − cash refunds) and the variance.
8. Every new table has row-level security and is covered by the tenant isolation suite.

## API (phase 4 Task 1)

Prefix `/api/v1`. Every route is behind `module:pos` (403 `module_inactive`).

**Till (device token, ability `device`; place from the device, never the body).**

| Route | Body | Notes |
| --- | --- | --- |
| `POST pos/number-ranges` | `{document_type: pos.receipt\|pos.refund, next?}` | The device's active ranges `{id, document_type, period, pattern, from, to, next, status, allocated_at}`, topped up below the threshold |
| `POST pos/shifts` | `{shifts: [{id, opened_by_id, opened_at, opening_float: [{currency, amount_minor}], closing?: {closed_by_id, closed_at, counted: [{currency, amount_minor}], note?}}]}` | Send again with `closing` to close; one upload may open and close. Never refused for who opened or closed it: an opener without `pos.shift.open`, or a closer without `pos.shift.close` (own shift) or `pos.shift.manage`, is stored and flagged `opener_not_permitted` / `closer_not_permitted` (the shift's `flags`, in the result and the back office) |
| `POST pos/sales` | `{sales: [...]}`, at most 50, shape below | Idempotent by sale id |
| `POST pos/cash-movements` | `{movements: [{id, shift_id, user_id, kind: pay_in\|pay_out, currency, amount_minor, reason, occurred_at, override?}]}` | `pos.cash.move` or override |
| `POST pos/voids` | `{voids: [{id, sale_id, voided_by_id, voided_at, reason, override?}]}` | `pos.sale.void` or override |
| `POST pos/refunds` | `{refunds: [{id, sale_id, shift_id, cashier_id, receipt_seq, receipt_number, refunded_at, reason, total_minor, lines: [{id, sale_line_id, qty}], payments: [...], override?}]}` | `pos.sale.refund` within `max_refund_amount` or override |

A sale: `{id, shift_id, cashier_id, actor_proof?, customer_id?, receipt_seq, receipt_number, number_range_id?, sold_at, offline?, currency, price_list_id?, lines: [{id, item_id, item_name?, uom_id, qty, unit_price_minor, list_price_minor?, price_list_id?, tax_inclusive, discount_minor, tax_code_id?, tax_rate?, tax_minor, total_minor, override?, price_override?, actor_proof?}], totals: {subtotal_minor, discount_minor, tax_minor, total_minor}, payments: [{id, payment_method_id, currency, amount_minor, amount_in_sale_minor, rate?: {rate, base, quote, kind?, effective_at?}, provider_reference?, status?: confirmed\|pending}], change?: {currency, amount_minor, rate?}}`. Ids are UUID v7 made on the device; amounts are minor units as digit strings; quantities decimal strings; `override` (a discount) and `price_override` (a price) take core's override shape, `{token}` online or the offline signed form `{id, kid, manager_user_id, cashier_user_id, permission, reference, authorised_at, signature}` with `reference` = the record's id (AUTH-08); `actor_proof` (on sales, sale lines, voids, refunds, cash movements, shift opening and `closing`) is the till's AUTH-07 sign-in attestation `{session_id, user_id, signed_in_at, kid, signature}`, signed `signin:v1\n{device_id}\n{kid}\n{session_id}\n{user_id}\n{signed_in_at}` with the device secret (ADR 004). A line without one uses the sale's. A verified proof for the record's actor removes `actor_unverified`. Voids, refunds and pay-outs it proves are applied, flagged `actor_offline` when the server did not check that sign-in online (`POST pos/pin/verify` with the `session_id`). A malformed proof is a 422. Voids, refunds and cash pay-outs whose override or actor can't be proven are held (`status: held`) until approved in the back office (`GET pos/held`, `POST pos/{voids|refunds|cash-movements}/{id}/approve|reject`); sales and pay-ins are kept and flagged. A sale naming a shift the server does not hold is refused `shift_unknown` (retryable) while it was sold less than `pos.unknown_shift_grace_hours` ago (72); after that it is stored on a closed placeholder shift that keeps the device's shift id (flagged `placeholder`, audited `pos.shift.placeholder`), flagged `shift_missing`, and waits in the flagged-sale review (`GET pos/sales?flag=shift_missing&reviewed=0`, `POST pos/sales/{id}/review`). A real shift uploaded later with that id is answered as stored; its float and count are left to the review. A resend of an id with other content is `payload_mismatch`. Line rules: gross = round(unit price × qty); total = gross − discount (inclusive) or + tax (exclusive); subtotal = Σ gross.

Every upload answers `{results: [{id, status: stored, ...}|{id, status: rejected, error: {code, message, field, retryable}}]}`: 200 when anything was stored, 422 `upload_rejected` when nothing was.

**Back office (people's tokens).** `GET pos/sales` and `GET pos/shifts` (`pos.sale.view`, `pos.shift.view`; `?status`, `?company`, `?branch`, `?location`, `?from`, `?to`, `?search`, `?sort`, `?per_page`, export `?format=csv|xlsx|pdf`), `GET pos/sales/{id}` (lines, payments, void, refunds) and `GET pos/shifts/{id}` (balances, cash movements).

## Deferred

- Restaurant, quick service, bar, hotel, pharmacy, wholesale, route sales, salon and fuel modes (tables, tabs, rooms, kitchen display and printers, courses, tips, appointments, pump readings).
- Scales and weighed items, PLU codes, barcode label printing, customer display.
- Loyalty: points, vouchers, gift cards, store credit as tender (Loyalty module).
- Promotions engine (Loyalty module); this phase only has manual line discounts.
- Inventory deduction and costing: Inventory listens to `SaleCompleted` and `SaleRefunded` once it exists. Accounting journals likewise.
- Item prices per price list are not in core master data yet: the till sends the list price it used; server-side price comparison waits for item prices.
- Serial numbers, batches and expiry (Inventory).
- Credit sales on account (Loyalty and credit).
