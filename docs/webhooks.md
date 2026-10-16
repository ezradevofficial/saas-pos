# Receiving webhooks

HOOK-01. This is the developer guide for webhooks. The developer portal links to it, and the same text is in the web app under Settings → Webhooks → Developer guide (`webhooks.guide.*` in `web/src/locales`). Design decisions are in [ADR 021](adr/021-webhooks.md).

## Setting up

- Owners and Admins (`core.webhook.manage`) add webhooks in Settings → Webhooks.
- Each webhook has:
  - an HTTPS endpoint URL;
  - the events it receives;
  - optionally one company or one branch, whose events alone it receives.
- The signing secret (`whsec_…`) is shown once, when the webhook is added or when its secret is rotated. Store it with your endpoint.
- A webhook sends as the user who added it. It receives only events for records that user may see, without the fields that user's field rules hide.
  - An API key will also be able to own a webhook, once the public API is available.
- Events are offered only for the modules the organisation uses.
- **Send test event** sends a `webhook.test.v1` event to check the endpoint. It is tried once, never retried, and does not count towards switching the webhook off.

## The request

Each event is an HTTPS `POST` with a JSON body:

```json
{
  "id": "0199f0b2-7c1e-7d3a-9f00-2b1f4c0e8a11",
  "type": "sale.completed.v1",
  "occurred_at": "2026-10-16T09:00:00Z",
  "data": { "sale_id": "…", "receipt_number": "R-0001", "currency": "KES", "totals": { "total_minor": "116000" } }
}
```

- Answer with any `2xx` status within 10 seconds. Do slow work after answering.
- Amounts are minor-unit digit strings with their currency (KES and USD have 2 decimals, CDF has 0).
- Quantities are decimal strings.
- Times are ISO 8601 in UTC.

## Checking the signature

Requests follow the [Standard Webhooks](https://www.standardwebhooks.com/) specification, so its libraries verify them as they are. Each request carries three headers:

| Header | Value |
| --- | --- |
| `Webhook-Id` | The event id (the same as `id` in the body) |
| `Webhook-Timestamp` | Unix seconds when this attempt was signed |
| `Webhook-Signature` | `v1,<base64 signature>`, with several values separated by spaces |

To check a request by hand:

1. Take the secret without its `whsec_` prefix and base64-decode it. This is the key.
2. Compute HMAC-SHA256 with that key over `<Webhook-Id>.<Webhook-Timestamp>.<raw body>`.
3. Base64-encode the result and compare it, in constant time, with each `v1,` value in `Webhook-Signature`. Accept the request if any value matches.
4. Refuse the request if the timestamp is more than 5 minutes from your clock. This protects against replays.

The specification's published example checks an implementation:

- secret `whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw`;
- id `msg_p5jXN8AQM9LWM0D4loKWxJek`;
- timestamp `1614265330`;
- body `{"test": 2432232314}`;
- expected signature `v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=`.

**Rotating the secret.** For 24 hours after a rotation, requests carry two signatures, one per secret. Accept either until your endpoint uses the new secret.

## Retries and failures

- Any answer other than `2xx`, or no answer within 10 seconds, is retried after 1 minute, 5 minutes, 30 minutes, 2 hours, 6 hours and 24 hours.
- After that last attempt the delivery is marked failed.
- Redirects are never followed: a `3xx` counts as a failure.
- After 10 failed deliveries in a row, the webhook is switched off and its managers are notified. Switch it back on in Settings → Webhooks once the endpoint works.
- The delivery log keeps every delivery for 30 days, with the status code, the first kilobyte of the answer and the time taken. Any delivery can be replayed from there. A replay sends the same event id and body.

## Duplicates and order

- **Duplicates.** An event can arrive more than once, for example after a timeout, a retry or a replay. Use the event id to ignore repeats.
- **Order.** Events can arrive in any order, because retries and parallel delivery mean a later event may come first. Use `occurred_at`, or read the record itself, to decide which is newest.

## What is sent

- Payloads carry ids and a few documented fields.
- They never carry contact details, pay lines or secrets.
- Fields hidden from the webhook's user are left out.
- A versioned type (`.v1`) never changes shape. A change ships as a new version (`.v2`) alongside the old one.

| Type | When | Data fields |
| --- | --- | --- |
| `sale.completed.v1` | A POS sale is stored | `sale_id`, `receipt_number`, `status`, `company_id`, `branch_id`, `location_id`, `device_id`, `customer_id`, `sold_at`, `offline`, `currency`, `totals.{subtotal,discount,tax,total,paid}_minor`, `lines[].{line_no,item_id,uom_id,qty,unit_price_minor,discount_minor,tax_minor,total_minor}` |
| `sale.voided.v1` | A completed sale is voided | `sale_id`, `void_id`, `receipt_number`, `company_id`, `branch_id`, `location_id`, `voided_at`, `currency`, `total_minor` |
| `sale.refunded.v1` | Lines of a sale are refunded | `refund_id`, `sale_id`, `receipt_number`, `company_id`, `branch_id`, `location_id`, `customer_id`, `refunded_at`, `currency`, `total_minor`, `tax_minor`, `lines[].{sale_line_id,item_id,qty,total_minor}` |
| `stock.low.v1` | Stock of an item at a location falls to or below its reorder level | `item_id`, `company_id`, `location_id`, `qty`, `reorder_level`, `reorder_qty` |
| `invoice.posted.v1` | A customer invoice is posted (final) | `document_id`, `kind`, `number`, `company_id`, `branch_id`, `party_id`, `issue_date`, `due_date`, `currency`, `subtotal_minor`, `tax_minor`, `total_minor`, `credits_document_id`, `posted_at` |
| `credit_note.posted.v1` | A customer credit note is posted | as `invoice.posted.v1` |
| `supplier_invoice.approved.v1` | A supplier invoice is approved (Procurement) | `supplier_invoice_id`, `number`, `supplier_reference`, `company_id`, `branch_id`, `party_id`, `invoice_date`, `due_date`, `currency`, `net_minor`, `tax_minor`, `total_minor` |
| `purchase_order.approved.v1` | A purchase order is approved | `purchase_order_id`, `number`, `company_id`, `branch_id`, `party_id`, `currency`, `total_minor`, `base_currency`, `base_total_minor` |
| `payroll.approved.v1` | A payroll run is approved | `payroll_run_id`, `number`, `company_id`, `kind`, `period_start`, `period_end`, `pay_date`, `totals` (per pay currency; left out when the user's field rules hide payroll totals) |
| `order.placed.v1`, `order.paid.v1`, `order.completed.v1` | An online order is placed, paid or delivered | `order_id`, `number`, `company_id`, `currency`, `total_minor` |
| `order.status_changed.v1` | An online order changes status | as above, plus `from_status`, `to_status` |
| `document.stage_changed.v1` | A document enters a stage or approval of its workflow | `document_type`, `document_id`, `workflow_id`, `stage.id`, `stage.type`, `company_id`, `branch_id`, `location_id` |
| `webhook.test.v1` | **Send test event** | `message`, `webhook_id` |

HOOK-01's "invoice approved" is `invoice.posted.v1`. A customer invoice has no separate approval step: it becomes final when it is posted.
