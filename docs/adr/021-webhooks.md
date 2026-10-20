# ADR 021: Webhooks

Status: Accepted (Phase 8, Stage 8A: HOOK-01, with TEN-01, RBAC-04, RBAC-05, RBAC-08, AUD-01, NOT-01, EXP-02)

## Context

HOOK-01 asks for subscriptions to events (sale completed, stock low, invoice approved, payroll approved, any stage change), signed payloads, retries with back-off and a delivery log.

The platform already has:

- domain events in every module, all dispatched after commit;
- the automation "call webhook" action (AUTO-03), with its SSRF guard (`WebhookSender`, `AddressGuard`, `HostResolver`), which `OutboundHttp` also uses.

The public API (API-01..05, branch `feat/public-api`) is being built in parallel. Its API keys will be service principals that hold a role assignment and scopes. Webhooks must not depend on that branch, but a key must be able to own a subscription once it merges.

## Decision

### Public event catalogue

- **Versioned event types.** Each type is named `<noun>.<verb>.v<N>` and maps from one internal domain event. A breaking payload change ships as a new version (`.v2`) next to the old one, never as a change to `.v1`.
- **Registry.** `WebhookCatalogue` (core) holds `WebhookEventType`s. Each module registers its own types in its service provider:
  - the internal event class;
  - the owning module;
  - the permission a principal needs;
  - the field rules that strip payload keys;
  - a `capture` closure that builds the occurrence from the event's own properties, with no queries.
- **Module gating (RBAC-08).** A type is offered (catalogue endpoint, validation) and dispatched only while its module is active for the tenant (`ModuleRegistry::isActive`, memoised). Stage changes (core) are gated by the document type: `DocumentTypeRegistry::find()` finds only types of active modules.
- **First catalogue:**

  | Type | From | Permission | Module |
  | --- | --- | --- | --- |
  | `sale.completed.v1` | POS `SaleCompleted` | `pos.sale.view` | pos |
  | `sale.voided.v1` | POS `SaleVoided` | `pos.sale.view` | pos |
  | `sale.refunded.v1` | POS `SaleRefunded` | `pos.sale.view` | pos |
  | `stock.low.v1` | Inventory `StockLow` | `inventory.stock.view` | inventory |
  | `invoice.posted.v1` | Accounting `DocumentPosted` (invoice) | `accounting.invoice.view` | accounting |
  | `credit_note.posted.v1` | Accounting `DocumentPosted` (credit note) | `accounting.invoice.view` | accounting |
  | `supplier_invoice.approved.v1` | Procurement `SupplierInvoiceApproved` | `procurement.invoice.view` | procurement |
  | `purchase_order.approved.v1` | Procurement `PurchaseOrderApproved` | `procurement.po.view` | procurement |
  | `payroll.approved.v1` | Payroll `PayrollApproved` | `payroll.run.view` | payroll |
  | `order.placed.v1`, `order.paid.v1`, `order.status_changed.v1`, `order.completed.v1` | E-commerce `OrderEvent` | `ecommerce.order.view` | ecommerce |
  | `document.stage_changed.v1` | core `WorkflowStageEntered` | the document type's view permission and `sees()` | the document type's |
  | `webhook.test.v1` | the "send test event" action | none | core |

  HOOK-01 says "invoice approved". Accounting's customer invoices have no approval step: posting is the moment they become final. The catalogue names them honestly (`invoice.posted.v1`). Approved supplier invoices are `supplier_invoice.approved.v1`. The exact permission names follow each module's catalogue (the table is indicative; the code is the source).

### Payloads

- **Envelope** (the body of every delivery):

  ```json
  {"id": "<event id>", "type": "sale.completed.v1", "occurred_at": "2026-10-16T09:00:00Z", "data": {...}}
  ```

- **Data.** Ids plus a minimal, documented field set (amounts as minor-unit digit strings with their currency, numbers, dates, statuses). Never names of people, contact details, pay lines or secrets. The documented fields are listed per type in `docs/webhooks.md`.
- **Event id.** A UUID v7 made when the internal event is captured. It is the same for every subscription that gets the event and for every attempt and replay. It is also the `Webhook-Id` header. Receivers deduplicate on it.
- **Principal.** A subscription is owned by a principal (`principal_type`, `principal_id`). A delivery is written only when the principal may see the occurrence:
  - it holds the type's permission at the occurrence's scope (company, branch, location; `ScopeResolver`);
  - for stage changes, the document type's `sees()` is also true.

  Payload keys mapped to fields the principal's field rules hide (RBAC-05) are removed before the delivery row is written. Hidden values are never stored and never sent. A replay sends the stored body again, filtered through the principal's current rules.
- **Principal types.** Principals are resolved through `WebhookPrincipals`, a registry of resolvers keyed by type. Core registers `user`: the user who created the subscription, who must stay active. The public API branch registers `api_key` with its own `WebhookPrincipal` implementation (`can()`, `hiddenFields()`, `isActive()`, `actingUser()`). Nothing in webhooks imports the API-key code.

- **Ownership (review finding 1).**
  - Only the principal changes a subscription's URL, events or filter. Anyone else with `core.webhook.manage` may change its description or switch it on or off; other changes get 403 `webhook_not_owner`.
  - An Owner may take a subscription over (`POST webhooks/{id}/take-over`). It then sends as the Owner, its events are checked against them first, and the change is audited (`core.webhook.take_over`).
  - Events are checked against the principal's permissions at the subscription's scope: its branch, its company, or the whole tenant when it has no filter.
- **Queue payloads (review finding 9).** The fan-out job carries ids, where the event happened and when, never more than the event needs:
  - read in the job from the module's own tables: `sale.completed`, `sale.voided`, `sale.refunded` (POS), `payroll.approved` (the run's totals), `invoice.posted`, `credit_note.posted` (Accounting), `document.stage_changed`;
  - carried as raised, because they describe a moment that cannot be read back faithfully or carry nothing a field rule governs: `stock.low` (the quantities at the crossing), `order.*` (the status change), `purchase_order.approved` and `supplier_invoice.approved` (amounts of an approved, unchangeable document).
- **`occurred_at` (review finding 10).** The event's own time where it has one (a sale's `sold_at`, a void's time, a refund's `refunded_at`, an invoice's posting time), else when it was raised.

### Subscriptions

- **Table `webhook_subscriptions`** (tenant, RLS):
  - URL (encrypted, write-only after save; only host and path are shown);
  - description;
  - event types (`jsonb` list);
  - active flag;
  - optional `company_id` or `branch_id` filter;
  - principal;
  - signing secret (encrypted);
  - previous secret with its expiry (rotation overlap);
  - consecutive failure count, disabled reason and time;
  - archived time (TEN-06: archived, never deleted).
- **Permission.** `core.webhook.manage` at tenant scope (Owner and Admin through `core.*`). Every change is audited (`core.webhook.create`, `update`, `rotate_secret`, `archive`, `test`, `replay`), without the URL or the secret.
- **Secret.** `whsec_` plus 32 random bytes in base64 (the Standard Webhooks format). It is shown once, on create and on rotate. Rotation keeps the previous secret for `webhooks.rotation_overlap_hours` (24 h). During that window each delivery carries two signatures, so receivers can switch without dropping events.
- **Shown URL (review finding 4).** `url_display` keeps only the scheme, host and port and masks any path or query (`https://hooks.slack.com/…/••••`): the app, notifications, the audit log and the export never show a path token.
- **Cap (review finding 6).** Subscriptions are counted inside the creating transaction, under a per-tenant advisory lock (`pg_advisory_xact_lock`), so two requests never both pass the cap.
- **URL rules.** The URL is checked with `WebhookSender::urlProblem` on save:
  - HTTPS only;
  - no credentials in the URL;
  - no private, loopback, link-local or metadata address literals;
  - no numeric host shorthands.

  It is checked again at send time by `WebhookSender::target`. The host is resolved once, every address must be public, and the connection is pinned to the checked address (`CURLOPT_RESOLVE`), which defeats DNS rebinding. Redirects are never followed: a 3xx is a failed attempt. There is no proxy from the environment.

  For local development only (`APP_ENV=local`), `WEBHOOKS_LOCAL_HOSTS` lists hosts (for example `127.0.0.1`) that may be called over plain HTTP. It is ignored in every other environment, so production, staging and the test suite always apply the full rules. This is stricter than "HTTPS only in production": staging and dev also need HTTPS.

### Delivery

- **Table `webhook_deliveries`** (tenant, RLS). There is one row per subscription and event (an outbox). It holds:
  - the event id, type and stored body (already filtered);
  - status `pending | sending | retrying | delivered | failed`;
  - attempts and the next attempt time;
  - the last response code, the first `webhooks.response_bytes` (1 KB) of the answer and the duration in ms;
  - the error;
  - `replay_of` (a replay is a new row with the same event id and body).
- **Dispatch.**
  - A listener registered for each catalogued internal event runs after commit, because every catalogued event is `ShouldDispatchAfterCommit`.
  - It first asks whether the tenant has any active subscription to that type (`WebhookSubscriptions::subscribedTypes()`: one query per tenant, memoised for the request or job, flushed before every queued job and on every subscription change) and whether the module is active (memoised by `ModuleRegistry`). If not, it does nothing.
  - Otherwise it queues one `FanOutWebhookEvent` job carrying the captured occurrence. That job, in the tenant, finds the matching subscriptions, applies the filter, permission and field rules, writes the delivery rows and queues one `SendWebhook` per row.
  - So a POS upload costs no extra query per sale (UploadQueryBudgetTest), and a tenant with no subscriptions pays one memoised query per request at most.
- **Queue.** The `webhooks` queue on its own Redis connection (`redis-webhooks`), with its own Horizon supervisor (`supervisor-webhooks`), as for exports and imports. A slow receiver never delays notifications, automation or fiscal work.
- **Signing (Standard Webhooks).** Each delivery sends these headers:
  - `Webhook-Id`: the event id;
  - `Webhook-Timestamp`: Unix seconds;
  - `Webhook-Signature`: `v1,<base64 HMAC-SHA256>`, with several values separated by spaces during a rotation.

  The signed content is `<id>.<timestamp>.<body>`, with the key being the bytes of the secret after `whsec_`. The brief described signing `timestamp.body`. We follow the Standard Webhooks specification instead, which also signs the id: receivers can use the published Standard Webhooks libraries, and an attacker cannot swap the id that receivers deduplicate on. The implementation is checked against the specification's published test vector. Receivers should refuse timestamps more than 5 minutes old (replay protection).
- **Timeouts.** `webhooks.timeout` (10 s for the whole call, 5 s to connect) plus `automation.dns_timeout`. These stay well inside the supervisor's 60 s.
- **Retries.**
  - No answer, or any non-2xx answer, is retried after 1 min, 5 min, 30 min, 2 h, 6 h and 24 h (`webhooks.backoff`), so there are 7 attempts in all.
  - A refused address (SSRF guard), a subscription archived or disabled meanwhile, or a principal who may no longer see the event fails the delivery at once.
  - After the last attempt the delivery is `failed` (the dead-letter state). It stays in the log and can be replayed.
- **Auto-disable.**
  - Each delivery that fails for good adds one to the subscription's consecutive failure count, and each delivered one resets it.
  - At `webhooks.disable_after` (default 10) the subscription is switched off (`disabled_reason = failing`).
  - Its pending retries then fail at their next attempt.
  - The tenant's webhook managers (active users with `core.webhook.manage` at tenant scope) and the user principal are notified through core notifications (`core.webhook.disabled`, in-app and email).
  - Switching it back on resets the count.
- **Checked again (review findings 2, 3).** Each delivery stores where its event happened (`occurrence`: company, branch, location, document).
  - At send time the type's module must still be active and the principal must still see the event there. Otherwise the delivery fails as `principal`, uncounted. Fields the principal's rules hide by then are taken out of the body.
  - Replays go to the subscription's current URL and secret, only while its current principal still sees the event.
  - The delivery log shows a body only to a reader who may see the event there (the type's own check, also for types checked per document), without the fields the reader's rules hide.
- **Exactly one row per event (review findings 7, 8).**
  - A partial unique index on `(subscription_id, event_id) where replay_of is null`, with `insert … on conflict do nothing`, makes a fan-out run twice harmless.
  - `SendWebhook` is unique per delivery until a worker starts it.
  - The sweep marks what it queues again (`updated_at`, `next_attempt_at`), so the next sweep leaves it alone.
- **Indexes (review finding 5).** `created_at`, `replay_of`, `updated_at where status in (pending, sending)` and `next_attempt_at where status = retrying`. `app_tenants_with_webhook_work` is a union of three queries, each served by one of them. Old rows are pruned in batches of 1,000.
- **Ordering.** Ordering is not guaranteed. Retries, parallel workers and replays can deliver a later event first. Receivers order by `occurred_at` and the resource's own state, and deduplicate by event id.
- **Replay.** Any delivery can be replayed by a webhook manager. The replay is queued as a new delivery row (`replay_of`) with the same event id and body, to the subscription's current URL and secret. It needs the subscription to be active and the principal to still see the event.
- **Test event.** `POST webhooks/{id}/test` queues a `webhook.test.v1` delivery with a new event id to that subscription. It does so even if the subscription does not list the type, so a receiver can be checked before going live.
- **Retention.** `webhooks:sweep` runs every five minutes.
  - It finds tenants through the security-definer function `app_tenants_with_webhook_work(stale_before, prune_before)` (DueTenants, ADR 002).
  - In each tenant it deletes deliveries older than `webhooks.retention_days` (30). The delivery log is operational data, not a business record, so pruning it does not break TEN-06.
  - It requeues deliveries left `sending` or `retrying` by a dead worker.

### Data export (EXP-02)

`webhook_subscriptions.url`, `.secret` and `.previous_secret` are denied columns in the full export (`ExportColumns::DENIED`). This follows the Phase 6 rule that webhook URLs carry secrets. Delivery rows carry no URL.

## Consequences

- Receivers get the Standard Webhooks contract and can verify with off-the-shelf libraries.
- One memoised query per request is the whole cost for tenants that do not use webhooks.
- When API keys land, the public API branch registers an `api_key` principal resolver and lets its key management create subscriptions with that principal. No webhook table changes.
- New public events are added by a module registering a `WebhookEventType`. A breaking change means a new version.
- The developer guide (`docs/webhooks.md`, and its text in the web app's i18n) is the page the public API's developer portal links to.
