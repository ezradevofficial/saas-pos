# ADR 019: Procurement seal keys and application key rotation

Status: Accepted (Phase 7, stage 7B: PRC-08, PRC-09, PRC-10)

## Context

Sealed bids (PRC-09) are encrypted with a random 256-bit key per tender. That key is stored in `procurement_tenders.seal_secret`, encrypted ("wrapped") with the application key through Laravel's `Crypt`. An open tender's notice token (PRC-08) is random too; it is stored as its sha256 for lookup and, wrapped the same way, in `public_token_secret`, so the buyer can be shown the link again.

So anything that changes `APP_KEY` can make tender keys unreadable. Without its key, a tender's bids can never be opened. That is a business loss, not just an error.

## Decision

**Unwrapping is all or nothing.** The opening (PRC-10) unwraps the tender key once, before it reads any bid.

- If unwrapping fails, the confirmation answers 500 `seal_key_unavailable` and the transaction rolls back. The tender stays `published`, the bids stay `submitted`, and nothing is marked `tampered`.
- A bid is `tampered` only when its own ciphertext fails: the fingerprint differs, the MAC fails, or the payload names another tender, bidder or version.

**Rotating `APP_KEY`.** Laravel decrypts with `APP_KEY` first and then with each key in `APP_PREVIOUS_KEYS`. To rotate:

1. Set the new `APP_KEY` and put the old key in `APP_PREVIOUS_KEYS` on every server (web, queue workers, scheduler).
2. Run `php artisan procurement:rewrap-keys`. It works tenant by tenant, under each tenant's row-level security. For every tender it re-encrypts `seal_secret` and `public_token_secret` with the current key. Bid ciphertext and sealed files are untouched, because they are sealed with the tender key, and the tender key does not change.
3. The command prints how many tenders it re-encrypted and how many failed, and exits non-zero on any failure. A failure means no key in `APP_KEY` or `APP_PREVIOUS_KEYS` opens that tender.
4. Remove the old key from `APP_PREVIOUS_KEYS` only after a run reports `failed: 0`.

**What `APP_PREVIOUS_KEYS` must keep:** every key that wrapped any tender key that has not yet been re-wrapped. Keep it until step 4. Tenders in every status count, including awarded and cancelled ones: their sealed files are still opened for download.

**Backups.** A database backup is only useful with the application keys that were current when it was taken. Store old keys with the backups they open, in the secret store, never in the repository.

## Consequences

- Rotating the key is a deliberate, run-once operation with a check. It is never done by just replacing `APP_KEY`.
- Someone holding both the database and the application key could still open bids early. Committee-held keys (threshold encryption) remain open question 3 in `docs/modules/procurement.md`.
- Tests: `ReviewFixesTest::test_an_unreadable_tender_key_stops_the_opening_and_marks_nothing_tampered` and `test_the_notice_token_is_random_and_keys_survive_an_app_key_rotation` (the key is rotated, the keys are re-wrapped, the old key is dropped, and the tender still opens).
