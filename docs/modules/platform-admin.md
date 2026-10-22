# Super-admin console spec (phase 8, stage 8A)

Source: platform core spec ADM-01..ADM-04, ADM-06, ADM-07 and RBAC-13;
the phase 8 plan (`docs/superpowers/plans/2026-10-16-phase-8-platform-hardening.md`);
ADR 002 (tenancy and row-level security), ADR 007 (country packs), ADR 013
(platform staff and catalogue), ADR 016 (billing), ADR 017 (onboarding),
ADR 018 (usage limits). When this file and the core spec disagree, the
core spec wins. ADM-05 (marketplace review) is stage 8B: docs/adr/024-marketplace.md.

## Scope

The console our own team uses to run the platform. It is not a module a
tenant buys: it is part of the core (`api/app/Core/Platform`, the billing
and onboarding platform controllers, `web/src/platform`). It extends what
phases 6 and 7 built:

- staff accounts with mandatory two-factor (ADR 013);
- the catalogue editor (ADR 013);
- the tenants list from the operator's accounts, trial extension, grace override and bank transfers (ADR 016);
- assisted onboarding (ADR 017).

Six parts:

1. **Staff roles (ADM-07).** Our own RBAC for staff, with platform-scoped permissions.
2. **Tenant management (ADM-01).** Search, a tenant's plan, modules, usage, health and billing, and suspend, reactivate and extend trial.
3. **Plans and pricing (ADM-02).** Per-country prices and coupons (from phase 6), plus custom Enterprise deals.
4. **Support access with consent (ADM-03, RBAC-13).** Staff ask, the tenant decides, and access is time-limited, logged in both chains and visible to the tenant.
5. **Country pack management (ADM-04).** Load, diff, validate, approve and publish pack updates.
6. **Revenue and usage reports (ADM-06).** MRR, churn, trials, revenue by country, module and plan, usage totals, with CSV.

Staff never see a tenant's business data except through a support
session the tenant approved.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| ADM-07 | **Staff roles.** Roles `owner` (platform owner), `support`, `sales`, `finance`, `compliance` and `engineering`. Each role holds a fixed set of platform permissions (table below), named `platform.<resource>.<action>`. Requests check them, and `me` returns them (`abilities`) for the console. Two-factor stays mandatory: no full token without a confirmed authenticator (ADR 013). Platform owners manage staff: list, invite (a one-time setup link), change a role, deactivate and reactivate. The last active platform owner cannot be demoted or deactivated. Every staff action is audited in the platform chain. | Must |
| ADM-01a | **Tenant search.** Filter by name or slug (contains), owner email (exact), country, plan (bundle key), status, and module. Results come from the operator's accounts, a tenant table of the operator. Owner email goes through the existing `auth_tenant_for_login` lookup, then checks in that tenant's context that the user holds the Owner role. No other tenant table is read. | Must |
| ADM-01b | **Tenant detail.** Plan, modules, subscription, billing status and invoices (phase 6). Usage: the latest Limits meters, each unit with its count and allowance. Health: last activity (the latest tenant audit entry's time), devices (paired, with the oldest last contact and its lag), failed jobs, and open posting exceptions (Accounting registers the counter). Ids and counts only, read in the tenant's own context, never business records. | Must |
| ADM-01c | **Actions.** Suspend, reactivate and extend a trial (`platform.tenants.manage`). Each takes a reason (10 to 500 characters). Each is audited in the tenant's chain (as the system, with the staff id) and in the platform chain, and notifies the tenant's Owners in the app and by email. Suspending sets the tenant and its subscription to `suspended`: writes are refused with 403 `tenant_suspended` except billing, the own account, the data export, approvals and payroll (owner decision 2026-10-17, below) and data protection duties (privacy requests and consent withdrawals, NFR-08; Phase 8 review L8, to confirm in docs/privacy.md), the tills stop selling, webhooks stop (held deliveries resume on reactivation, ADR 021), SSO sign-in still works (ADR 023), and the scheduler skips the tenant. Reactivating restores the subscription's status from before the suspension. | Must |
| ADM-02a | **Per-country prices and coupons.** These already exist from phase 6: versioned price rows per item, country and currency, and coupons. The console groups prices by country. | Must |
| ADM-02b | **Enterprise deals.** A per-tenant negotiated price. It overrides catalogue lines (a module's or bundle's unit price, its extra-outlet price and minimum) and can price a quoted bundle (Enterprise). A deal has an effective period (`effective_from`, optional `effective_to`) and a reason. Sales or finance draft it (`platform.deals.manage`). Finance approves it (`platform.deals.approve`), and the approver is never the drafter. An approved deal is never edited: a change is a new deal, and the old one can be ended. | Must |
| ADM-02c | **Deals in pricing.** The subscription engine prices with the deal in force on the pricing date: quotes, changes, renewals and their invoices, and overage. The PriceCalculator stays pure: the deal is applied to the catalogue snapshot it prices (`CatalogueSnapshot::withDeal`). Deal lines show `deal: true` and are locked for the period like catalogue rows (`price_ids` gets `deal:<id>`). | Must |
| RBAC-13a | **Request.** Staff with `platform.support.request` ask a tenant for access: a reason (10 to 500 characters), a duration (1 to 72 hours) and a mode (`read` or `write`). The request is a tenant-side record, `support_access_grants` (RLS), mirrored for the console in `platform_support_requests` (global, staff only). | Must |
| RBAC-13b | **Consent.** Users with `core.support_access.manage` (the Owner and Admin templates) see the request in the app (a notification and Settings → Security → Support access) and by email. They approve or deny it. Approving records who approved, when, the mode, the duration, and whether payroll, HR-sensitive and payment data are included (a checkbox, default off, recorded). The tenant can revoke an approval at any time. Staff can cancel their own pending request or end their own session. | Must |
| RBAC-13c | **The support session.** An approved grant lets the staff member open a session (`POST platform/support/requests/{id}/session`). The session is a support principal bound to the tenant: a tenant user row marked with the grant (`users.support_grant_id`). It has no password or contact and cannot sign in. Its Sanctum token expires with the grant. The console hands over a one-time entry code (60 seconds, hashed). The tenant web exchanges it for the token at `POST auth/support-session`. The staff member sees a "Support session" banner with the tenant, mode and end time. The tenant's users with `core.support_access.view` see "Support access active". | Must |
| RBAC-13d | **What the principal may do.** Every catalogue permission of an active module, at tenant scope, except: (1) permissions it never gets: roles, users, invitations, settings for domains, subscription changes, the full data export, audit and access-review exports, fiscal and payment-provider configuration, module changes, support access itself, and anything that makes a credential, an outbound link or a schedule (device creation and pairing, automation rules, notification templates and settings, CRM and shop settings, sale share links, tender and RFQ invitations, webhooks: HOOK-01; marketplace installs and the developer portal: MKT-01, MKT-02; API keys and the sandbox reset: API-02, API-05), single sign-on settings (an identity provider staff control would outlive the grant: AUTH-11), data subject requests and their access reports, and consent records (NFR-08); (2) sensitive permissions (payroll, HR bank, pay, identity, medical, disciplinary and documents, payments, payment methods, bank and payment records in Accounting, gift cards, subscription billing), unless the grant includes sensitive data; (3) in read-only mode, every permission whose action is not `view` or `view_all`. Read-only sessions also refuse every write method (403 `support_read_only`). The principal's own account routes (two-factor, PIN, sessions, profile) are refused, and so is every list export, however the format is asked for. When a session ends, the devices it paired are suspended, its automation rules switched off, and the SSO connections and email domains it created or changed are archived with their links (enforced SSO left without a connection is switched off), audited in both chains. Staff may ask one tenant at most three times an hour, cancelled requests included. | Must |
| RBAC-13e | **Logging.** Every request of a support session (method, route, path, status, duration) is recorded in the tenant's audit (`core.support_session.request`, visible to the tenant) and in the platform chain (`platform.support.request_made`). Opening, ending, revoking and expiring are audited in both chains too. | Must |
| RBAC-13f | **Expiry and isolation.** Access ends at `expires_at`. Every request checks the grant (approved, not expired, not revoked) before anything else. `support:expire` (every five minutes) marks expired grants, deactivates their principals, deletes their tokens and audits it. The principal belongs to one tenant. Its token sets that tenant's context (TEN-01), and RLS applies as for any user. A grant of tenant A never opens tenant B. | Must |
| ADM-04a | **Pack releases.** Staff load a JSON document for one country and one kind (`tax`, `payroll`, `hr`, `holidays`, `fixed_assets`, `accounts`), with an effective date and notes. A release is versioned per country and kind. Its lifecycle is `draft` → `approved` → `published`, or `rejected`. | Must |
| ADM-04b | **Diff and validation.** On load, the release is validated and diffed against the content in force (the latest published release, else the shipped file). Validation covers: the kind's schema (the existing validators for tax, holidays and charts; schemas for payroll, HR and fixed assets), the code matching the country, overlapping periods of any entry, and missing required values (a period marked confirmed, `needs_confirmation: false`, with a null value is an error; unconfirmed nulls are listed as warnings). The diff lists entries added, removed and changed. A release with errors cannot be approved. | Must |
| ADM-04c | **Two-person publish.** A release with `platform.packs.manage` is loaded. It is approved by a staff member with `platform.packs.approve` who did not load it. It is published by a staff member with `platform.packs.publish` who did not approve it. Every step is audited in the platform chain. | Must |
| ADM-04d | **Published releases override the files.** From its effective date, the latest published release of a country and kind is the pack the app reads (`PackDocuments`). This holds for payroll, HR (leave days, identifier types), fixed asset tax classes and chart templates at runtime. For tax and holidays it also holds for the deploy commands `country-packs:publish` and `country-packs:holidays`, which read the release instead of the file. Publishing a tax release also writes the tax pack version (ADR 007) and propagates it to tenants, one queued job per tenant in its context. Publishing a holidays release replaces the country's public holidays. ADR 022 records the design. | Must |
| ADM-06 | **Reports.** MRR now, by currency, country, plan and module; for a date range: trials started and converted, churned accounts and churned MRR; revenue (paid operator invoices, net of tax) by country, module and plan; usage totals per unit across tenants. Computed from the operator tenant's accounts, account events and invoices in the operator's context, and a narrow security-definer aggregate for usage (`app_platform_usage_totals`, totals only). Each report downloads as CSV. | Must |

## Staff permissions (ADM-07)

| Permission | owner | support | sales | finance | compliance | engineering |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| `platform.tenants.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `platform.tenants.manage` (suspend, reactivate, extend trial) | ✓ | ✓ | | ✓ | | |
| `platform.tenants.create` (assisted onboarding) | ✓ | ✓ | ✓ | | | |
| `platform.catalogue.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `platform.catalogue.manage` | ✓ | | | ✓ | | |
| `platform.billing.view` (subscriptions, invoices, settings) | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `platform.billing.manage` (grace, bank transfers, settings) | ✓ | | | ✓ | | |
| `platform.deals.manage` | ✓ | | ✓ | ✓ | | |
| `platform.deals.approve` | ✓ | | | ✓ | | |
| `platform.support.request` | ✓ | ✓ | | | | ✓ |
| `platform.packs.view` | ✓ | | | | ✓ | ✓ |
| `platform.packs.manage` (load, validate) | ✓ | | | | ✓ | ✓ |
| `platform.packs.approve` | ✓ | | | | ✓ | ✓ |
| `platform.packs.publish` | ✓ | | | | ✓ | |
| `platform.reports.view` | ✓ | | ✓ | ✓ | ✓ | |
| `platform.staff.manage` | ✓ | | | | | |
| `platform.marketplace.view` (ADM-05, ADR 024) | ✓ | ✓ | | ✓ | ✓ | ✓ |
| `platform.marketplace.review` (approve, reject, suspend, reinstate apps) | ✓ | | | | ✓ | ✓ |
| `platform.marketplace.payouts` (MKT-03: balances, manual payouts) | ✓ | | | ✓ | | |

The old roles map onto the new ones: `owner` stays, `billing` becomes
`finance`, and `support` stays.

## Decisions

1. **Staff permissions are code, not data.** Six roles, each with a fixed list in `PlatformUser`. Staff are few and the list changes with the code. There are no custom staff roles.
2. **Suspension is a status, not a deletion.** `tenants.status = suspended` stops the scheduler (its functions already select active tenants). The subscription's `suspended` status refuses writes, as read-only does, but with its own code. The status before suspension is kept in `subscriptions.status_before_suspension`.
3. **Notifications to the tenant** use the Notifier (ADR 009) with three mandatory event types: `core.platform.tenant_suspended`, `core.platform.tenant_reactivated` and `core.platform.trial_extended`. Support access uses `core.support_access.requested`, `core.support_access.started` and `core.support_access.ended`.
4. **Deals live in the customer's own tenant** (`billing_deals`, RLS). The subscription engine reads them in that context. Staff write them by entering the tenant's context, as the trial extension does. The operator's account carries a summary (`deal`) for the console list. A deal's lines are `{type: module|bundle, key, amount, extra_outlet_amount?, minimum_amount?}` in the country's billing currency, in minor units a month.
5. **The support principal is a tenant user, not a role.** Its permissions come from its grant (`SupportAccess::permits`), which `ScopeResolver` asks first. Every policy and list filter therefore works unchanged: tenant scope, field rules (none), limit rules (none, so no discounts or refunds above zero). It is hidden from user lists, counts and approver resolution.
6. **The entry code carries the tenant id** (`<tenant id>.<secret>`). The exchange enters that tenant and looks the code's hash up under RLS. A wrong tenant finds nothing, so no security-definer lookup is needed.
7. **Pack releases are global** (`country_pack_releases`, no tenant id). Only the platform role writes them; the runtime role reads published ones. Pack content is reference data, the same for every tenant.
8. **Reports are per currency.** KES and USD are never summed together.
9. **Account events.** `operator_account_events` (an operator tenant table) records each status change of a customer's account (`trial_started`, `converted`, `churned`, `reactivated`, `suspended`), written by `Accounts::sync`. Churn means an account going from a paying status (`active`, `past_due`, `read_only`) to `cancelled` or `suspended`.

## Data model

| Table | Kind | Purpose |
| --- | --- | --- |
| `platform_users.role` | global | check constraint widened to the six roles; `billing` rows become `finance` |
| `platform_support_requests` | global (platform role only) | the console's mirror of each support request: tenant id, staff, mode, status, times |
| `country_pack_releases` | global (platform writes; runtime reads published) | pack releases: country, kind, version, status, content, hash, diff, validation, effective date, who loaded, approved and published |
| `support_access_grants` | tenant (RLS) | the consent record: staff id and name, reason, mode, hours, include sensitive, status, decided by and at, expires, revoked, entry code hash |
| `users.support_grant_id` | tenant | marks a support principal |
| `billing_deals` | tenant (RLS) | Enterprise deals of the tenant |
| `subscriptions.status_before_suspension` | tenant | restored on reactivation |
| `operator_accounts` + `slug`, `bundle`, `modules`, `deal` | operator | search and the deal summary |
| `operator_account_events` | operator (RLS) | status changes for reports |

## API

Staff (`auth:platform`, full token, `/api/v1/platform`):

- `GET me` (abilities are the permissions); `GET staff`, `POST staff`, `PATCH staff/{id}`, `POST staff/{id}/deactivate`, `POST staff/{id}/reactivate`.
- `GET tenants?search=&owner_email=&country=&plan=&status=&module=`, `GET tenants/{id}`, `POST tenants/{id}/suspend`, `POST tenants/{id}/reactivate`, `POST tenants/{id}/trial` (now takes `reason`).
- `GET tenants/{id}/deals`, `POST tenants/{id}/deals`, `POST tenants/{id}/deals/{deal}/approve`, `.../reject`, `.../end`.
- `GET support/requests`, `POST tenants/{id}/support-requests`, `POST support/requests/{id}/cancel`, `POST support/requests/{id}/session`, `POST support/requests/{id}/end`.
- `GET packs`, `GET packs/current?country=&kind=`, `POST packs` (load), `GET packs/{id}`, `POST packs/{id}/approve`, `POST packs/{id}/reject`, `POST packs/{id}/publish`.
- `GET reports/summary?from=&to=`, `GET reports/{report}.csv?from=&to=` (`mrr`, `revenue`, `trials`, `churn`, `usage`).

Tenant (`auth:sanctum`, `tenant`):

- `GET support-access` (requests and history), `GET support-access/active`, `POST support-access/{grant}/approve`, `POST support-access/{grant}/deny`, `POST support-access/{grant}/revoke`.
- `POST auth/support-session` (public, throttled): an entry code for a token.
- `POST support-session/end`: the principal ends its own session.

## Screens

- Console: Tenants (search filters, detail with plan, modules, usage, health, billing and invoices, actions with a reason dialog, deals, support requests), Pricing (prices by country, coupons, deals awaiting approval), Support (my requests, open a session), Country packs (releases, load, diff, validation, approve, publish), Reports (date range, figures, CSV), Staff (owner only).
- Tenant: Settings → Security → Support access (pending requests with approve and deny, active sessions with revoke, history). A "Support access active" bar in the shell for users with `core.support_access.view`. A "Support session" bar for the staff member in a support session, with the end time and "End session".

## Acceptance criteria

- Every console endpoint answers each staff role as the table says, and 403 otherwise. Tenant tokens get 401.
- Support access: refused without consent; ends at expiry; revocation ends it at once; a read-only session's writes are refused; sensitive data is refused unless granted; every request appears in the tenant's audit; a grant never opens another tenant.
- Packs: validation errors block approval; the two-person rule holds; effective dates choose the release; a payroll run uses the published release.
- Deals: a quote and a renewal invoice use the deal's price; an Enterprise deal makes Enterprise priceable for that tenant only.
- Reports match seeded data.
- New tenant tables pass the isolation suite, and the new security-definer function passes the lockdown test.

## Status (phase 8, stage 8A, branch `feat/super-admin`)

All requirements above are built and tested. Decisions taken while building:

- **Support principals hold no role.** Field rules hide nothing from them. Limit rules give them nothing, so no discounts or refunds above zero. Workflow stages and custom forms that name roles stay out of reach, because support never approves. Configuration (themes, layouts) resolves for them tenant-wide.
- **What a suspended tenant can still write (owner decision, 2026-10-17).** Its billing, its own accounts, the data export and support-access decisions; approvals (LIM-04's approvals rule holds); and payroll: calculating, submitting, approving, posting, cancelling and correcting runs, and filing statutory returns (PAY-28 holds). Everything else stops, the tills included. Overdue accounts are handled by read-only mode, not by suspension. `EnforceSubscription::allowedWhileSuspended` reuses the read-only classes for approvals and payroll.
- **Revenue by plan** comes from the bundle line of each paid invoice, or `custom` when there is none. **MRR by module** comes from each paying account's latest period invoice, net of tax and spread over its months.
- **Usage totals** are taken on the latest metered day on or before the range end.
- **Pack releases.** A tax or holidays release dated later is written into the pack tables by `country-packs:apply-releases` on its date. The other kinds are read where they are used, from their date (ADR 022).
