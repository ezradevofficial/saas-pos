# ADR 004: Offline POS and sync

Status: **Accepted**. Device pairing and device tokens shipped in Sprint 1 (TEN-05). The sync design was built in phase 4 and accepted on 2026-10-09, once the 7-day offline tests passed (phase 4 Task 7, see Evidence).

## Context

Shops in Kenya and the DR Congo lose connectivity for hours or days. The POS must sell for at least **7 days with no connection** and lose or duplicate no sale (NFR-04). Receipts must carry unique numbers even when made offline (NUM-02). Fiscal submissions must eventually be accepted: KRA eTIMS in Kenya, DGI in the DRC.

Each device belongs to one location of one tenant (TEN-05). It may stay unattended and offline for long periods, so its credentials cannot expire the way a person's session does.

## Decision

### Sync design (Accepted, phase 4)

- **Local store.** SQLite on the device, through WatermelonDB. It holds items, prices, customers, promotions, rates, taxes and unsent sales.
- **Identity.** Every record created on the device gets its UUID (v7) on the device. The server never renumbers it.
- **Sales are append-only.** A completed sale is never edited on the device. Voids and refunds are new records that refer to it.
- **Idempotent upload.** The device resends unsent sales until the server acknowledges them. The server ignores a sale whose UUID it already holds, and answers as if it had just stored it. A duplicate upload is harmless.
- **Master data syncs down** in increments with `updated_at` cursors per entity. Archived records (TEN-06) arrive as tombstones, so the device can hide them.
- **Conflict rules:**
  - the **server wins** for prices and master data
  - the **device wins** for completed sales: the sale happened at the price the till showed
- **Receipt numbers.** The server allocates number ranges to each device ahead of time (NUM-02). The device draws from its range offline and asks for the next range before running out. Ranges never overlap, so offline receipts never clash.
  - Built in phase 4 Task 1: a range is a block reserved from the document type's NUM-01 counter (`App\Core\Numbering\Numbering::reserve`, one `UPDATE ... RETURNING` under the counter's row lock; a GiST exclusion constraint on `pos_number_ranges` backs it). Its pattern is frozen at allocation: place codes ({BRANCH}, {LOCATION}, {DEVICE}) and, for a yearly format, the year are filled in; {MM} (and the year of a format that never resets) come from the sale's local date. The device formats numbers itself; the server checks an uploaded number belongs to one of the device's ranges and matches the pattern.
  - `POST pos/number-ranges` (device token) with the next number the device will use: when fewer than `pos.ranges.threshold` (100) remain, a block of `pos.ranges.size` (500) is added. Receipts (`pos.receipt`) and refund receipts (`pos.refund`) have their own counters. Unpairing a device retires its ranges; a yearly format's past-year ranges retire when the device asks in the new year.
  - A ranged document type is never gapless: an unused tail of a range is a gap. Fiscal numbering (KRA eTIMS, DGI) is the authority's and comes with the fiscal adapters.
- **Upload contract (phase 4 Task 1).** `POST pos/shifts`, `pos/sales`, `pos/cash-movements`, `pos/voids`, `pos/refunds` take batches of device-made records; each record gets its own transaction and result (`stored`, also for a resend, with the same answer; or `rejected` with `code`, `message`, `field`, `retryable`). The answer is 200 when anything was stored, 422 `upload_rejected` when nothing was. Upload order: shifts, sales, cash movements, voids, refunds, then shifts again to close them; a record whose shift or sale is not on the server yet is refused as retryable. Completed sales keep the till's prices, discounts, tax and rates; differences are recorded in the sale's `flags`. See `docs/modules/pos.md`.
- **Fiscal queue.** Fiscal submissions queue on the server and retry with back-off until the authority accepts them. The device records the fiscal state it received, or "pending" when offline.
- **Tests (NFR-04).** Jest sync tests simulate going offline, duplicate uploads, conflicting master-data edits and a 7-day backlog.

### Device pairing and tokens (Accepted, Sprint 1)

`App\Core\Tenancy\DevicePairing`:

- **Pairing.**
  - An admin issues a one-time 8-character code. The alphabet has no 0/O, 1/I or L. The code is shown once, stored as a SHA-256 hash, and valid for 15 minutes.
  - The device sends the code to the public pair endpoint. The endpoint finds the tenant with the security-definer function `auth_tenant_for_pairing` (ADR 002), then completes pairing under that tenant's row-level security.
  - The device receives its own Sanctum token with the ability `device`.
  - The endpoint is limited to 10 attempts a minute per IP and 300 a minute overall.
- **Device tokens never idle out.** People's tokens expire after the tenant's idle timeout (AUTH-09). A device token is exempt, because a till must keep working across a long offline period. It stays valid only while the device's status is `active` (`IdentityServiceProvider`).
- **Suspend keeps the tokens.** A suspended device's tokens are refused while it is not active. Resuming the device makes them valid again, so the pairing survives.
  - Cost: if a device is stolen, resuming it re-enables a stolen token. **Admins should unpair a lost device, not suspend it.**
- **Unpair revokes** every token of the device. Re-pairing needs a new code.
- **Auditing.** Pair, suspend, resume and unpair are audited (AUD-01). Requests made with a device token record the device and location in the audit context (AUD-02).

### Master data pull (phase 4, Task 2)

Built in `App\Core\Sync`. Accepted with the rest of the sync design (Task 7, see Evidence).

**Endpoints** (device token, ability `device`, 120 requests a minute per device):

- `GET sync/bootstrap`: server time (clock-skew check), the device, where it sits (`settings`), the entities to pull in order (`key`, `mode`, `module`, `version`), page sizes and the PIN scheme.
- `GET sync/pull?entities[]=items&cursors[items]=...&limit=500`: per entity `{mode, reset, replace, upserts, tombstones, cursor, has_more}`. Without `entities`, every entity the tenant has.
- `GET sync/media/{item_image}`: an item image, for items the device may hold.
- `GET sync/device-secret/challenge`, `POST sync/device-secret/rotate`, `POST sync/device-secret/activate`: rotating the device secret (below).

**Registry.** `SyncSources` lists the entities. Core registers settings, currencies, exchange rates, tax codes, tax categories, price lists, payment methods, units, item categories, items, customers and staff. Modules register their own sources. For example, the POS module adds its settings and number ranges. An entity of a module the tenant has not activated is not served (RBAC-08).

**Two kinds of entity.**

- *Incremental* (items, item categories, units, customers): pages of changes since an opaque cursor, plus tombstones.
- *Snapshot* (staff, taxes, payment methods, currencies, rates, settings, price lists): small sets sent whole. The cursor is a hash of the rows. An unchanged set costs nothing, and a changed set replaces the device's copy (`replace: true`). This suits sets whose membership depends on several tables. For example, "who works at this location" changes when a role, an assignment or a user changes. Per-row tombstones would be fragile there.
- *Snapshot cache (NFR-05).* A device's snapshot rows are rebuilt at most every `sync.snapshot_ttl_seconds` (30 by default; `SnapshotCache`). PIN changes, lockouts and device secret changes invalidate the tenant's cache at once. Other snapshot changes (a new payment method, a rate, a price list, a role change) reach tills within the TTL.

**Cursor: transaction id, not `updated_at`.** This deviates from the wording "updated_at cursors" in CLAUDE.md. Its intent is that no update is ever lost, and this design meets that intent better (accepted by the owner, phase 4). A cursor on `updated_at` loses rows. A transaction that started earlier but commits later writes an `updated_at` older than rows already handed out, so a device that pulled in between never sees it. It also depends on the app servers' clocks. Instead:

- Every synced table has `sync_xid` (the writing transaction's id) and `sync_seq` (a global sequence). The database trigger `sync_stamp` sets both on every insert and update, never PHP.
- Changes are paged in `(sync_xid, sync_seq)` order. Equal timestamps cannot tie: `sync_seq` is unique.
- A pull hands out only rows whose `sync_xid` is below `pg_snapshot_xmin(pg_current_snapshot())`, the oldest transaction still running. Below that horizon every transaction has finished, and no later one can get a smaller id. So nothing ever lands behind a cursor, whatever the commit order or clocks.
- `updated_at` is still sent in the payload.
- Cost: the horizon is cluster wide. A transaction left open (a long import, an idle-in-transaction session, a prepared transaction, another database on the same cluster) delays every device's changes until it ends. Changes are delayed, never lost, and rows are never skipped to catch up. Guards:
  - the runtime connection sets `idle_in_transaction_session_timeout = 60s` and `statement_timeout = 120s` (`server_options` in `config/database.php`); the database default for idle transactions is also 60 seconds (migration `2026_10_19_000200`; the schema owner may not alter roles)
  - `max_prepared_transactions = 0`, and the database runs on a cluster of its own (deploy README)
  - `SyncLag` measures the hold-back. It is shown on `GET /up` as `X-Sync-Lag-Seconds` and `X-Sync-Lag-Xids`, and by `php artisan sync:lag` (exit 1 when lagging). Above 2 minutes it logs a warning.
  - in tests only, a pull's own uncommitted transaction counts as committed for itself (tests read inside their wrapping transaction). Production pulls never write before reading.
- `SyncConcurrencyTest` proves it with real concurrent connections.

The cursor is `base64url("i1.{version}.{xid}.{seq}")` for incremental entities and `base64url("s1.{version}.{sha256}")` for snapshots. A source raises its `version` when its payload changes meaning. Devices holding an older cursor then get `reset: true` and the entity from the start.

**Tombstones.**

- An archived row (TEN-06) is changed in its own table, so it arrives as a tombstone with no extra table.
- A row that leaves a device's scope or is deleted gets a `sync_tombstones` row from a trigger, naming the company it left (null: every company). This covers an item or category moved to another company or out of the shared pool, a party that is no longer a customer or moves company, and deletes.
- Whether a changed id becomes an upsert or a tombstone is decided by its state at pull time, so the device always ends with the latest state.
- Tombstones are not pruned yet. When they are, a cursor older than the retention will need `reset`.

**Items are self-contained.** An item carries its other units, barcodes and images (`url` = `/api/v1/sync/media/{id}`). Changes to those rows re-stamp the item by trigger (they are replaced wholesale on edit). Changes to the tax data that decides `sellable` (tax rates, a category's default code, a code's archive) re-stamp the items concerned from a queued job after commit (`RestampItemsForTax`), 500 rows per statement: a VAT change can touch every item, and one long transaction would hold back every device. An item is `sellable: false` with a `reason` (`tax_category_missing`, `tax_code_missing`, `tax_code_archived`, `tax_rate_needed`) when its tax for the device's company is not known today (`ItemTaxStatus`). Rates are never guessed. The `tax_codes` entity carries every dated rate, so the device re-checks when a dated rate starts while it is offline. `sellable` is the till's guide, not the authority: the server re-checks the tax of every line when the sale is uploaded (the POS module).

**Minimal fields.** Devices have no user, so field rules (RBAC-05) cannot be applied when pulling. Each entity sends only what a till needs. For example, customers have no emails or addresses, and payment methods have no settings or secrets. Each staff row carries that user's field rules for `item` and `party`, and the till hides those fields from whoever is signed in.

**Customer data on tills (data minimisation decision).** A till holds the customers of its company and the group's shared ones: name, phone numbers, tax ID, currency, price list, payment terms and credit limit. That is what selling on account and printing fiscal receipts offline needs. A stolen till therefore exposes those fields for those customers. This is accepted for now. The owner may revisit it, for example by syncing only customers with recent sales at the location and looking up the rest online.

### POS PINs, device secrets and manager overrides (AUTH-06..AUTH-08)

**Device secrets and rotation.** Each paired device has secrets of 32 random bytes, each named by a key id (`kid`). They are kept in `device_secrets`, encrypted with the application key, with `issued_at`, `activated_at` and `retired_at`. At any time one is current, at most one is pending, and retired ones are kept.

- *Pairing* returns the first one once, as `device_secret` (base64url) with `device_secret_kid`. Unpairing retires them all.
- *Storage on the device.* The app keeps both its secret and its device token in the platform keystore (Android Keystore / iOS Keychain, through SecureStore), never in its SQLite database.
- *Rotation* proves possession at each step, and a lost answer is harmless:
  1. `GET sync/device-secret/challenge` returns a one-time `nonce`, valid for 5 minutes.
  2. `POST sync/device-secret/rotate {kid, nonce, proof}`, with `proof = base64url(HMAC-SHA256(current secret, "rotate:v1\n{device_id}\n{nonce}"))`, returns a *pending* secret and its `kid`. The current secret stays current. A new rotation replaces a pending secret whose answer was lost.
  3. `POST sync/device-secret/activate {kid, proof}`, with `proof = base64url(HMAC-SHA256(new secret, "activate:v1\n{device_id}\n{kid}"))`, makes the new secret current and retires the old one.
- Rotation is limited to 15 steps an hour per device (5 rotations).
- *A device that lost its secret cannot rotate.* The only way back is to unpair and pair it again.
- *Whoever holds a device's current secret can mint offline overrides as that device* (below) and check its staff's PINs offline. The token and the secret together are the device's identity. That is why both live in the keystore, and why offline overrides are device claims that are reviewed.

**PIN storage.** A PIN is 4 to 6 digits. Repeated digits, runs (1234, 987654), repeated pairs or triples and common PINs are refused. A staff card code is 6 to 64 letters or digits, compared in upper case. The server stores, per user:

- an Argon2id hash, for online checks
- a PBKDF2-HMAC-SHA256 key: 16-byte random salt, at least 100,000 iterations (150,000 by default, stored per user), 32 bytes, encrypted with the application key

It never stores the PIN.

**Offline verification.** The staff entity gives each device, per user, `{scheme: "pbkdf2-sha256+hmac-sha256/v1", kid, salt, iterations, verifier}`. The verifier is `HMAC-SHA256(device secret kid, "pin:v1:{user_id}:" || PBKDF2 key)` (`card:v1:` for the card), always under the device's *current* secret. The app recomputes it from the PIN typed and compares in constant time. Each device therefore gets a different verifier, bound to the user, and the verifier cannot be checked without the device secret.

**Who is staff, and whose material a till holds.**

- *Staff.* Staff of a location hold `pos.till.sign_in` through a role covering it. The cashier template has it; branch managers and Owners get it through `pos.*` and `*`.
- *Offline material.* PIN material goes to a device only when that permission comes from an assignment at the company, branch or location, or from a tenant-wide role that is not an Owner role, which is a deliberate grant. Owners covered only by the `*` template appear as staff with `offline: false` and no material. They sign in online, or get a role where they work. Without this rule, their PIN material would sit on every till of the group.
- *Six-digit PINs.* Holders of a permission that approves overrides (`pos.sale.void`, `pos.sale.refund`, `pos.price.override`, `pos.discount.give`, set in `sync.override_permissions`) must choose 6 digits. Someone who gains such a role with a shorter PIN gets `must_change`.
- *Changing a PIN.* A PIN an administrator sets for someone else is `must_change`. The till asks for a new PIN (`POST pos/pin/change`, online, current PIN checked with lockout) before anything else. Administrators changing their own PIN through `users/{self}/pos-pin` confirm their password, as on `me/pos-pin`.

**Lockout.**

- Wrong attempts count per user and device. The fifth locks the user's PIN on that device until a new PIN is set, by the user or by an administrator with `core.user.edit`.
- Online, `POST pos/pin/verify` counts under a row lock.
- Offline, the device enforces the count itself and reports it with `POST pos/pin/attempts`, for staff of its location only. Reports only ever raise the count, so a report can never unlock. A lock is audited once.
- The staff entity carries each user's lock state on that device.

**Threat model.**

- Someone with the device's database *and* its secret (a rooted or stolen device) can test all 10^6 six-digit PINs offline. Each guess costs one PBKDF2 at 150,000 iterations, so hours on a phone and minutes on a GPU. A 4-digit PIN falls much faster. The 5-attempt lockout does not apply to such an attacker.
- With the database alone, the verifiers are useless, because the secret stays in the keystore.
- Mitigations:
  - unpair a lost device: its token dies, its secrets are retired, and nothing it signs afterwards verifies (an override must be dated while its secret was current)
  - rotate PINs (any change gives every device a new verifier)
  - prefer 6-digit PINs
  - online sign-in is rate-limited and locked server-side
- PINs only open a till. They never sign in to the back office.

**Manager override (AUTH-08).**

- *Online.* `POST pos/override {manager_user_id, pin|card, permission, cashier_user_id?, reference}` checks the manager's PIN, with the same lockout. It then checks that the manager holds the permission at the device's location, and returns `ovr1.{payload}.{HMAC-SHA256(k, "ovr1.{payload}")}` with `k = HMAC-SHA256(app key, "pos-override-token:v1")`. The payload binds tenant, device, manager, cashier, permission and the record (`reference`, required), and expires after 120 seconds.
- *Offline.* The device checks the manager's PIN itself and signs, with `HMAC-SHA256(device secret kid)`:

  ```
  override:v2\n{device_id}\n{kid}\n{id}\n{manager_user_id}\n{cashier_user_id}\n{permission}\n{reference}\n{authorised_at}
  ```

  `authorised_at` must fall while that secret was current, from its issue to its retirement (or now), with 10 minutes of clock skew. Otherwise offline overrides do not expire.
- *Redemption.* The POS module calls `OverrideVerifier::redeem($device, $override, $permission, $reference)` with the action it records. It checks either form. `reference` (the sale or line id) is required.
  - Each override id is used once (`override_redemptions`).
  - Using it again for the same device, permission and record throws `OverrideAlreadyApplied` (409 `override_already_applied`, carrying the earlier result). The caller must treat this as "already recorded", never as a fresh approval.
  - Any other use is refused (`override_replayed`).
- *Recording.* Both users are recorded and audited (`core.user.override_issue`, `core.user.override_redeem`). The result says whether the manager is still active (`managerActive`), still works at the location (`managerStaffAtLocation`) and still holds the permission there (`managerHoldsPermission`).
- *Review.* Offline overrides are the device's claim: anyone holding the device secret could have signed one. The POS module records every offline override, and every override whose flags are not all true, for review (`needsReview()`). Tasks 1 and 6 show them in the back office. They are never dropped: the device wins for completed sales.

**Sign-in attestation (AUTH-07).** When someone signs in at the till, by an offline PIN check or `POST pos/pin/verify`, the device makes a session id (a UUID) and signs, with `HMAC-SHA256(device secret kid)`:

```
signin:v1\n{device_id}\n{kid}\n{session_id}\n{user_id}\n{signed_in_at}
```

- *Shape.* Every record the device uploads for that person carries it as `actor_proof: {session_id, user_id, signed_in_at, kid, signature}`, with the signature in base64url without padding. `signed_in_at` is ISO 8601 with `Z` or an offset (the server's clock as the device knows it), signed exactly as sent. No field may contain CR or LF.
- *Checks* (`ActorProofVerifier`, core). `kid` names a secret of this device. The signature matches (constant time). `signed_in_at` falls while that secret was current, with the same 10 minutes of skew as offline overrides. `user_id` is the record's actor (voided_by_id, cashier_id, user_id, opened_by_id, closed_by_id). That user exists in the tenant, is active, and is staff of the device's location with `pos.till.sign_in`. Anything else leaves the record unproven. It is never a rejection, but a proof of the wrong shape is a 422.
- *Online or offline.* `POST pos/pin/verify` takes optional `session_id` and `signed_in_at`. When the PIN is right, it records the session in `till_sign_ins` (once per device and session, audited `core.user.till_sign_in`). A proof naming a session recorded for the same device and user is `online`. Any other proof is the device's claim, like an offline override: anyone with the device secret could sign it.
- *Effect in the POS module.* A verified actor removes `actor_unverified`. Voids, refunds and pay-outs by a verified actor who holds the permission within their limit are applied instead of held. When the proof is not online, they are also flagged `actor_offline` for review. Sales and pay-ins are never flagged `actor_offline`. A sale line without its own proof uses the sale's. Shift opening and closing record `actor_verified` and `actor_online` in their audit entries.
- *Not bound to a record.* The proof covers the sign-in, not one action. A device that keeps its secret can reuse a session's proof until the secret is retired. That is why offline proofs on money out are reviewed.
- *Session flags (review only, never a refusal).* A record dated before the second of the sign-in it cites is flagged `before_sign_in` (device clocks drift); a record made more than `pos.actor.session_max_hours` (24) after it is flagged `session_stale`. Sales, voids, refunds and cash movements carry these flags; shifts, which have no flags column, record them as `actor_flags` in their audit entries. A sale line uses the sale's proof; per-line proofs are not required (owner ruling).
- *On the till* (`pos/src/auth/attestation.js`). The sign-in screen makes the session (`session_id` UUID v7, `signed_in_at` from `engine.serverNow()`) before the PIN check, sends it with an online check, and signs it once the PIN is right (`signActorProof`, tested against the server's vector). The session context keeps it until the next user switch; every record that person makes carries it. A till without a device secret signs nothing, so its records are flagged.

### Selling on the till (phase 4, Task 5)

- *Local records.* Schema v4 adds the POS module's synced `pos_number_ranges` and `pos_open_shift`, and local `pos_sales`, `pos_records` (voids, refunds, cash movements), `pos_shifts`, `pos_held` and `pos_counters`. A record, its receipt counter and its outbox row commit in one WatermelonDB write (`engine.enqueue(kind, id, payload, {group, prepare})`), so a crash never leaves a sale without its upload or a number used twice. Records are grouped by shift: the shift goes up first, its close last.
- *Receipt numbers.* The till draws from its active ranges in order (`pos/src/pos/numbering.js`, the pattern rendered as `Pattern::render`); the larger of the server's `next` and the local counter wins. Below 100 numbers left it asks `POST pos/number-ranges` with the in-use range's next number (never a later range's start, which the server would read as "everything below is used") and pulls the ranges again. With no number left it refuses to complete a sale and says to go online.
- *Money.* Tender maths (`pos/src/pos/tender.js`) is a port of `TenderCalculator`, with exact BigInt fractions and the same 20-decimal quantisation when dividing by a rate; tests reuse the PHP vectors. Tax per line (`tax.js`) follows `TaxCalculator`; a "Rate needed" item is shown disabled and cannot be charged. The rate rows used are cached (CUR-09) and their ids kept on the local sale; each payment and the change carry the rate in the API's shape.
- *Overrides on the till (AUTH-08).* A discount above the signed-in person's limit, a price change, a void, a refund above the limit or a pay-out without `pos.cash.move` opens the manager dialog: staff holding the permission within the limit are listed, the manager's PIN is checked like a sign-in (offline material, else online, same lockout), and the till signs `override:v2` with `reference` = the line or record id. The till always signs offline rather than asking for an online token, because a token expires after 120 seconds and the record may upload later. Every such override therefore reaches the server as an offline override: it is applied and flagged `override_offline` for review, never held (owner ruling, phase 4). Owners: the staff entity carries `owner: true` for users with an Owner role covering the location, and the till, like the server's `Authority::within`, applies no limit to them.
- *Held sales* stay on the device (POS-02). *Fast user switching* keeps the cart and shift: they live in `PosProvider`, above the sign-in screen.

**PIN check speed on the till (POS app).** Hermes has no JIT, so PBKDF2 at 150,000 iterations in pure JavaScript (@noble/hashes) takes about 14 s on an M-series Mac without JIT, and longer on a low-end Android phone. The app therefore derives the key natively, through its own local Expo module `pos/modules/app-crypto` (no third-party dependency): Android `SecretKeyFactory("PBKDF2WithHmacSHA256")`, iOS CommonCrypto `CCKeyDerivationPBKDF`. `src/auth/pinCrypto.js` tries the native module, then WebCrypto (web preview, Jest), then @noble/hashes, falling back on any error. The server's iteration count is not lowered.

To check the timing on a device (not possible in CI):

1. Build a development build (`npx expo run:android` or `npx expo run:ios`). The module is autolinked from `pos/modules`.
2. In the app's JS console, run `require('./src/auth/pinCrypto').pbkdf2Engines()`. It must list `native` first.
3. Time a derivation: `const t = Date.now(); await require('./src/auth/pinCrypto').pbkdf2Sha256(new TextEncoder().encode('482913'), new Uint8Array(16), 150000); Date.now() - t`. Expect well under a second on a mid-range phone. Record the phone and the result in the phase report.
4. Sign in with a staff PIN offline (airplane mode) and confirm it is accepted.

**Sync status.** Devices record `last_pull_at`, `last_push_at` (the POS module calls `DeviceSyncStatus::recordPush`) and `last_bootstrap_at`, written at most once a minute, and shown in the devices API.

## Consequences

- The server's sale endpoints must be idempotent by UUID from day one, and sale tables need a unique key on the device-generated id.
- Master-data tables need a server-written change marker and an index that serves the cursor queries: `sync_xid`/`sync_seq` stamped by the database, indexed with `tenant_id` (see Master data pull).
- A long-running write transaction delays every device's changes until it commits (never loses them). Timeouts and the lag metric guard against it.
- Number ranges add a per-device allocation table and a top-up flow. Lost devices waste the unused part of their range, which is acceptable.
- Because device tokens don't expire, unpairing is the only kill switch. The UI must say so.
- The 7-day offline scenario is proven by tests (Evidence). Change this ADR when the design changes, rather than writing a new one.

## Evidence (phase 4 Task 7, 2026-10-09)

The design is accepted on these tests. The API tests drive one paired till through the real device endpoints only (device token, offline overrides signed with the device secret); the app tests run the sync engine over the HTTP client with `fetch` mocked by answers the PHP endpoints recorded.

- `api/modules/POS/tests/OfflineWeekTest.php` (NFR-04, NUM-02, POS-04, POS-05, POS-09): bootstrap and pull, then 7 days offline while the server changes a price, a tax rate and an exchange rate, renames, adds and archives items. The backlog (7 shifts, 301 sales, a pay-in, a pay-out, a void and a refund) goes up in the engine's order. Every record is stored once, also when the whole outbox is sent again. Sales keep the till's prices and tax, with `price_differs`, `tax_differs`, `rate_differs` and `price_unknown` flags. Receipt numbers are 1 to 301 from the device's range. Each shift closes with zero variance. Pulls from the week-old cursors, in pages of 2, deliver every change, the tombstones included, in cursor order with no gaps, and end equal to a fresh device's copy.
- `api/modules/POS/tests/OfflineEdgeCasesTest.php`:
  - duplicates: a resend, and a resend inside one batch, answer the same; another body gets `payload_mismatch`
  - conflicts: the server wins on master data, the device wins on the sale
  - NUM-02: range exhaustion and the next range
  - backlog order: records sent before their shift or sale are retryable; sales may arrive out of order; two open shifts in one batch; a shift sent closed before its sales
  - one known gap, marked incomplete: sales of a shift the server refused for good stay retryable for ever
- `api/modules/POS/tests/ConcurrencyTest.php`: the same sale posted by six concurrent sessions through the endpoint is stored once; a copy with another body gets `payload_mismatch`.
- `api/modules/POS/tests/OfflineTimingTest.php` (NFR-03): the upload and catalogue pull timings, printed, with bounds generous enough for CI. A local run: one sale uploads in 48 ms (median), a batch of 50 in 2.2 s, and 5,000 items pull in 11 pages in 0.2 s.
- `api/modules/POS/tests/ShiftUploadTest.php`: a batch that closes one shift and opens the next in the same currency. The proof found this bug: the batch was refused as a whole.
- `pos/src/sync/offline.test.js`:
  - 7 days offline with 332 queued records, each uploaded once and in order on reconnect, then the pull resumes from the old cursors
  - Retry-After and exponential backoff
  - a duplicate answer after a lost acknowledgement counts as success; `payload_mismatch` is kept for review
  - a pull interrupted mid-way resumes from the last applied page
  - the server wins on master data
- `pos/src/test/fixtures/device-api.json`: the recorded shapes. Refresh them with `POS_RECORD_FIXTURES=1 php artisan test modules/POS/tests/DeviceApiShapesTest.php`.

Still to prove on hardware, as noted below: the WatermelonDB JSI adapter and the PBKDF2 timing in an Android development build.

### expo-doctor exceptions (2026-10-09)

`pos/package.json` excludes three packages from expo-doctor's React Native Directory check: `@nozbe/watermelondb` (listed as untested on the New Architecture), its dependency `@nozbe/simdjson` (no metadata) and our local `app-crypto` module (not a published package). The WatermelonDB risk is real and accepted for now: the native JSI SQLite adapter must be proven in an Expo development build on Android (RN 0.86, New Architecture) before the POS ships, together with the PBKDF2 timing check above. If it fails there, the fallback is WatermelonDB's non-JSI SQLite adapter or a different store behind the same sync engine.
