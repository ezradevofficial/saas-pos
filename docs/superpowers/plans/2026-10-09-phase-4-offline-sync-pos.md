# Phase 4: offline sync, then POS core

Roadmap exit criteria: WatermelonDB sync with idempotent sales, cursors,
conflict rules and 7-day offline tests; POS retail sale, split
multi-currency payment and phone layout matching `design/screens/Main`,
`PosPayment` and `PosPhone`; M-Pesa Daraja adapter (sandbox); KRA eTIMS
adapter with a server queue (sandbox).

Requirements: NFR-03, NFR-04, NUM-01, NUM-02, AUTH-06, AUTH-07, AUTH-08,
CUR-05, CUR-06, CUR-09, TEN-05 (pairing UI), TEN-07 (moved from phase 2),
and the POS module spec written in Task 1 (`docs/modules/pos.md`, from
concept note sections 3, 5, 7.1 and 7.2). ADR 004 (offline sync) moves from
Proposed to Accepted once the 7-day test passes.

## Ground rules

- CLAUDE.md in full: tenancy and RLS with isolation tests on every new
  table; UUID v7 (device-generated for offline records); money as minor
  units plus currency, rates `numeric(18,8)`, every multi-currency sale
  stores the rate used (CUR-04); audit; Form Requests and policies;
  permissions `pos.<resource>.<action>`; en/fr for every string; tokens-only
  styling (spacing scale only 0, px, 1–6, 10, 12); single-language tenant
  text; owner rulings in earlier reports.
- POS is a module (`api/modules/POS`, permissions `pos.*`, feature-flagged
  per tenant, RBAC-08). It reads core services and raises events
  (`SaleCompleted`, `SaleRefunded`, `SaleVoided`, `ShiftClosed`); it never
  reads another module's tables. Accounting/Inventory postings come later
  through those events.
- Integrations sit behind adapters with fake drivers (tests and local) and
  sandbox drivers configured from credentials the owner provides. Never
  invent tax rates: a taxable item whose rate is "Rate needed" is refused
  at the till with a clear message (and in the API).
- Check CI on main after every merge (memory: review-depth).
- Phase review: risky tasks (sync, money, payments, fiscal, PIN/auth) get a
  reviewer; screens get browser checks (POS screens through the Expo web
  preview on port 3009 with Playwright).

## Tasks

| # | Task | Risk | After |
|---|------|------|-------|
| 1 | POS module spec (`docs/modules/pos.md`) and API module skeleton: sales, lines, payments, shifts, cash movements, tax at sale, idempotent sale upload, voids/returns as new records; NUM-01 numbering service in core and NUM-02 device ranges | risky | – |
| 2 | Sync API: per-entity pull with `updated_at` cursors and tombstones for everything the till needs; device bootstrap; PIN login data (AUTH-06/07/08 server side: PIN set/reset, offline-verifiable hash, lockout, manager override audit) | risky | – |
| 3 | Payments and fiscal: provider adapter interface (initiate, confirm, refund, reconcile), cash, M-Pesa Daraja (STK push, C2B validation/confirmation, B2C refunds; callbacks secured), manual confirmation fallback; fiscal adapter interface with a server queue, KRA eTIMS (OSCU) and a DGI (e-MCF) interface with fakes | risky | 1 |
| 4 | POS app foundation: WatermelonDB schema, sync engine (pull cursors, push queue, retries, idempotency), pairing screen, PIN sign-in and fast user switching, sync status | risky | 2 |
| 5 | POS app selling: catalogue grid and search/scan, cart, held sales, customer, totals with tax, payment screen (split, multi-currency, change in another currency, M-Pesa STK, CUR-05 dual price, CUR-09 offline rates), receipt view, shifts and cash-up, voids/returns with manager override; tablet `Main`, `PosPayment`, phone `PosPhone` | normal (money UI → reviewer) | 1, 3, 4 |
| 6 | Back office: POS settings (devices and pairing, PINs, receipt ranges, payment providers, fiscal queue), sales and shifts lists and detail, consolidated sales dashboard (TEN-07) | normal | 1, 3 |
| 7 | Offline proof: 7-day offline, duplicate upload, conflict and backlog tests end to end (device engine against the API), NFR-03 timing checks; ADR 004 → Accepted | – | 4, 5 |
| 8 | Whole-phase review, Playwright walkthrough of POS (Expo web) and back office, report | – | all |
