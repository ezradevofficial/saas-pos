# ADR 004: Offline POS and sync

Status: **Proposed** for the sync design. It will be built and proven in phase 4 ("offline sync prototype, then POS core"). **Accepted** for device pairing and device tokens, which shipped in Sprint 1 (TEN-05).

## Context

Shops in Kenya and the DR Congo lose connectivity for hours or days. The POS must sell for at least **7 days with no connection** and lose or duplicate no sale (NFR-04). Receipts must carry unique numbers even when made offline (NUM-02). Fiscal submissions must eventually be accepted: KRA eTIMS in Kenya, DGI in the DRC.

Each device belongs to one location of one tenant (TEN-05). It may stay unattended and offline for long periods, so its credentials cannot expire the way a person's session does.

## Decision

### Sync design (Proposed, phase 4)

- **Local store.** SQLite on the device, through WatermelonDB. It holds items, prices, customers, promotions, rates, taxes and unsent sales.
- **Identity.** Every record created on the device gets its UUID (v7) on the device. The server never renumbers it.
- **Sales are append-only.** A completed sale is never edited on the device. Voids and refunds are new records that refer to it.
- **Idempotent upload.** The device resends unsent sales until the server acknowledges them. The server ignores a sale whose UUID it already holds, and answers as if it had just stored it. A duplicate upload is harmless.
- **Master data syncs down** in increments with `updated_at` cursors per entity. Archived records (TEN-06) arrive as tombstones, so the device can hide them.
- **Conflict rules:**
  - the **server wins** for prices and master data
  - the **device wins** for completed sales: the sale happened at the price the till showed
- **Receipt numbers.** The server allocates number ranges to each device ahead of time (NUM-02). The device draws from its range offline and asks for the next range before running out. Ranges never overlap, so offline receipts never clash.
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

## Consequences

- The server's sale endpoints must be idempotent by UUID from day one, and sale tables need a unique key on the device-generated id.
- Master-data tables need a reliable `updated_at`, written by the server, and an index that serves the cursor queries.
- Number ranges add a per-device allocation table and a top-up flow. Lost devices waste the unused part of their range, which is acceptable.
- Because device tokens don't expire, unpairing is the only kill switch. The UI must say so.
- The design stays Proposed until the phase 4 prototype proves the 7-day offline scenario. Change this ADR then, rather than writing a new one.
