# ADR 013: Platform staff and the plan catalogue

Status: Accepted (Phase 6, task 1: SUB-01, ONB-03, AUD-01; ADM-07 minimal)

## Context

Our own team has to maintain what we sell: modules, bundles, prices per country, coupons and the annual discount (SUB-01). The onboarding module picker needs a live price (ONB-03). Staff are not tenant users. They belong to no tenant, and their mistakes must not leak into tenant data. The full super-admin console (ADM-) comes in phase 8.

## Decision

**Global tables, no row-level security, a separate role.** `platform_users`, `platform_access_tokens`, `platform_audit_logs` and the `catalogue_*` tables have no `tenant_id`. Staff belong to no tenant, and the catalogue is the same for every tenant. `tests/Support/GlobalTables::PLATFORM` lists each table with its reason. Grants, not RLS, keep them apart:

| Role (connection) | Staff tables | Catalogue tables | Tenant tables |
| --- | --- | --- | --- |
| `app` (`pgsql`, tenant runtime) | none | SELECT only (public catalogue, quote, billing snapshots) | as ADR 002 |
| `app_platform` (`pgsql_platform`, staff) | users: SELECT, INSERT, UPDATE; tokens: also DELETE; audit: SELECT, INSERT | SELECT, INSERT, UPDATE (no DELETE; countries SELECT only) | none |

- `app_platform` is `NOSUPERUSER NOBYPASSRLS`. `create-roles.sql` creates it locally and in CI (`setup-local-db.sh`); in production it is created once by hand like the other roles, and the host's `.env` sets `DB_PLATFORM_*`. Migration `2026_10_28_000300_harden_platform_tables` grants and revokes, and fails when the role is missing.
- Default privileges grant every new table to `app`, so a new platform table must revoke it, as that migration does.
- `RlsCoverageTest` checks the grants, and fails if a platform table gains a `tenant_id`.
- Staff models, auth, tokens, the audit log and catalogue edits use `pgsql_platform`. Public reads use `::on(PlatformDatabase::READ)` (the runtime role). Cross-tenant work in later tasks still never bypasses RLS: it uses `TenantContext::run` or a narrow security-definer function (ADR 002).

**A separate guard.** The `platform` guard (`Auth::viaRequest`) reads only `platform_access_tokens`. Its tokens look like `plat_<uuid>|<secret>`, and only the sha256 of the secret is stored.
- Sanctum's lookup wants a bare UUID before the bar, so it refuses a staff token on every tenant route.
- The `platform` guard refuses any token without the prefix.
- Staff routes sit under `/api/v1/platform/*` with `auth:platform`. The isolation suite asserts this, and asserts that a tenant token gets 401 there. `PlatformAuthTest` covers both directions.

**Two-factor is required.**
- `php artisan platform:user {email} [--name] [--role] [--reset]` creates the account and prints a one-time setup link (24 hours, stored hashed). It needs only the runtime connection.
- Setting the password from the link starts TOTP enrolment. The token issued then carries only `platform:enrol`, which reaches `me`, the enrolment routes and sign-out.
- A confirmed code makes the account active and lifts that token to full access. `EnsurePlatformAccess` also refuses a full token when the account has no confirmed two-factor.
- Sign-in has two steps: the password, then a TOTP code against an encrypted challenge that expires in 5 minutes. A code's time step is accepted once.
- The tenant rules apply (AUTH-10): 10 attempts a minute per IP, 5 per email, and 5 failures lock the account for 15 minutes.
- A locked account answers exactly like wrong credentials, after the same password hash check, so neither answer nor timing reveals an account or a lock. Attempts on a locked account are audited at most once per account per 15 minutes.
- The setup link is used with a conditional update that checks the row count, so two racing requests cannot both set a password.
- Staff tokens last 8 hours (`PLATFORM_TOKEN_MINUTES`). The web keeps them in `sessionStorage` (one tab, gone when it closes).
- In production the console is served on its own subdomain (`PLATFORM_URL`, which setup links use), so no tenant page shares its origin.

**Roles (minimal).** `owner`, `billing` and `support`. Every role can view the catalogue (`catalogue.view`); only owner and billing can change it (`catalogue.manage`). Form Requests check the role. The full staff RBAC is ADM-07 in phase 8.

**Audit.** Every staff action and sign-in event is appended to `platform_audit_logs`: one hash chain for the platform, built like AUD-03 with `Auditor::canonicalJson`. Appends are serialised by an advisory lock. A trigger refuses updates, deletes and truncation for every role, and the runtime role has no UPDATE or DELETE grant. `platform:audit-verify` walks the chain.

**Catalogue as data.**
- Modules have a key matching the module registry, a kind (`module`; `extra` for extra POS devices; `addon` for Brand Pack), a unit, names in English and French, and what they include (`{"pos_device": 2}` per outlet).
- Bundles have their modules, the add-ons sold with them (Brand Pack with Starter), the outlets the price covers, and `quoted` for Enterprise (contact sales).
- Prices are rows per item, country and currency in minor units, from an `effective_from` date, with an optional minimum (HR, Payroll) and an extra-outlet price (Business).
- **Prices are versioned and never edited.** A trigger refuses any update or delete of `catalogue_prices` and `catalogue_annual_discounts`, except archiving a row that has not taken effect yet. A change is a new row dated today or later. Task 2 keeps the price row id a subscription was sold at.
- **Dates are UTC.** `effective_from` is a calendar date in UTC: "today" is the UTC date, for the API rule and for the trigger that refuses inserting a row dated before it. A price takes effect at 00:00 UTC (03:00 in Nairobi, 01:00 in Kinshasa).
- **Make-up is fixed while priced.** While a module or bundle has a live (not archived) price, its make-up cannot change: for a bundle `quoted`, `modules`, `addons` and `included_outlets`; for a module `kind`, `unit`, `includes` and `requires`. A Form Request rule refuses it (422) and a trigger refuses it in the database. A change is a new item with its own price. So Enterprise cannot be flipped to priced.
- The annual discount is data: 10 months charged for 12.
- A coupon is a percent or a fixed amount in a currency, with an expiry, a redemption limit, and the modules or bundles it applies to. Its code, value and scope are fixed once created (Form Request and trigger); only the expiry, the limit and the redemption count change. Codes have at least 8 characters, and quotes carrying a coupon are limited to 5 a minute per IP and 100 a minute in all, against guessing.
- A percent coupon takes `round_half_up(eligible × percent / 100)` minor units: half a minor unit goes to the customer.
- The billing currency comes from `catalogue_countries` (KE: KES, CD: USD), never from input.
- Concept note 8.4 is seeded by the migration, so every environment has it.

**Pricing engine.** `PriceCalculator` is pure: no database and no clock, integer minor units only. Every multiplication and sum is checked; an overflow is a `QuoteError` (`amount_too_large`, 422), never a float. Unit prices are at most 1,000,000,000 minor units. It prices a `QuoteRequest` (modules, quantities per unit, cycle, optional bundle and coupon) against a `CatalogueSnapshot`. `Catalogue::snapshot($country, $date)` builds the snapshot. A quoted bundle is never priced. `suggestion` is the cheapest priced bundle that sells the same selection for less. Unit and property tests cover 8.4.

**Public endpoints.** `GET /api/v1/catalogue?country=` and `POST /api/v1/catalogue/quote` are unauthenticated, rate-limited per IP (60 a minute) and read only global tables.

**Web.** `/platform/*` routes sit outside the tenant shell. They have their own token storage (`app.platformToken`) and client, so a staff session and a tenant session never share a token.

## Consequences

- Staff accounts cannot be self-registered. The first one comes from the command on the API host.
- Fixing a wrong price that is already in effect means adding a corrected row from today. History is never rewritten.
- Brand Pack is sold only as an add-on of a bundle that lists it (Starter). Buying it on its own, or with Business, is refused. Without a bundle the 422 still carries `suggestion` (the cheapest bundle that sells it, `saving: null`). This is our reading of 8.4's "Starter only" and should be confirmed.
- Warehouses are priced as outlets (8.4: "per outlet/warehouse"). The `warehouse` unit exists for later use.
