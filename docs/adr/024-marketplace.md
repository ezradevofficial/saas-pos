# ADR 024: The marketplace (developer apps, OAuth installs, review, paid apps, connectors)

Status: Accepted (Phase 8, stage 8B: MKT-01..MKT-04, ADM-05, API-02 OAuth; with TEN-01, RBAC-04, RBAC-05, RBAC-12, AUD-01, HOOK-01, SUB-03, EXP-02)

## Context

The spec asks for a marketplace where developers register and submit apps with a description, permissions and pricing, reviewed by platform staff before listing (MKT-01, ADM-05); tenants install with one click after seeing and approving the requested permissions, and uninstall at any time (MKT-02); paid apps are billed through our subscription system, 80 % to the developer (MKT-03); first-party connectors are available at launch (MKT-04). API-02 adds OAuth 2.0 for marketplace apps next to API keys.

The public API (ADR 020) already has service principals: a key is a `users` row with status `service`, one role assignment and scopes, so every policy, `ScopeResolver`, field rule, rate limit and audit entry works for it unchanged. Webhooks (ADR 021) left an `api_key` principal type for later. The owner approved `laravel/passport` for OAuth (Phase 8 plan).

## Decision

### Developers

- **A developer account belongs to a tenant.** Any tenant (a sandbox from the developer portal, API-05, or a live one) registers once with a public name, a contact email and the marketplace terms, which state the 80/20 share (`PUT developer`, `core.developer.manage`, Owner and Admin). The platform's own developer (`first_party`, no tenant) publishes the connectors.
- **Only a live tenant lists apps** (Phase 8 review H2). A sandbox may register and build drafts, but submitting a version answers 403 `developer_sandbox`, and staff approval refuses a sandbox's version (409 `developer_sandbox`): an idle sandbox is closed after 30 days (ADR 020), and a closed developer tenant stops every app of it for every customer. Apps a sandbox listed before this rule keep serving, because `sandbox:expire` never closes a sandbox whose developer has an app listed or installed.
- **Apps and versions.** An app has a unique slug, an OAuth client (one per app) and a webhook signing secret. What a tenant consents to is a version: name, tagline, description, category, logo, website, support email, privacy URL, redirect URIs, scopes, webhook URL and events, and pricing. Texts are stored once, in the language the developer wrote them (names and texts are not per-language). A draft is edited, submitted, then approved or rejected by staff; a submitted version may be withdrawn to a draft; one draft or submitted version at a time. A reviewed version never changes (trigger); a change is a new version copied from the latest.
- **Secrets.** The client secret (48 characters) and the app's webhook secret (`whsec_`, Standard Webhooks) are shown once. Both live in `marketplace_app_secrets` (below), never in a table a runtime role reads. Both can be rotated; a rotated webhook secret gives each install a new derived key by a job in its own tenant, the previous key signing for 24 hours, or not at all when the developer ends it at once (`POST developer/apps/{id}/webhook-secret` with `immediate: true`, for a secret that leaked).
- **Per-install signing keys.** No install signs with the app's secret. Each install's subscription signs with `HMAC-SHA256(app webhook key, 'install:' || installation id)`, sent as `whsec_` + base64. The developer derives the same key from the app's secret and the `installation_id` in the signed body (docs/webhooks.md). A tenant that reads its own install's key learns nothing that signs another install's deliveries.
- **Redirect URIs** are HTTPS, or HTTP to a loopback address for native and local apps (RFC 8252), never with a fragment or credentials. The webhook URL follows the webhook rules (HTTPS, public address, ADR 021).

### Global, developer-owned tables under row-level security

`marketplace_developers`, `marketplace_apps`, `marketplace_app_versions` and `oauth_clients` have no `tenant_id`: an app is published to every tenant. They are listed in `GlobalTables::MARKETPLACE` and forced under row-level security keyed on `developer_tenant_id`:

| Role | Reads | Writes |
| --- | --- | --- |
| `app` (tenant runtime) | its own developer rows; its own apps, and apps that have been listed; approved versions, and the developer of a listed app; its own clients and the clients of listed apps (the token endpoint runs before a tenant is known) | inserts its own rows only: a developer row for the session tenant, an app only under the session tenant's developer, a version only of its own app (the trigger also checks the version's developer tenant is the app's), a client only for its own app; updates its own drafts and submissions, archives its own apps, renames its own clients; column grants keep `listed_version_id`, review fields, statuses and a client's redirect URIs and `revoked` flag out of reach |
| `app_platform` (staff) | everything | only review columns: version status (submitted to approved or rejected), the listed version, suspension, the client's redirect URIs and `revoked` flag |

Nobody deletes (no `DELETE` or `TRUNCATE` grant). `RlsCoverageTest` checks the policies and grants, and `MarketplaceSecurityTest` probes them (a version inserted for another developer's app, a client's redirect URIs changed by its developer: both refused).

**Secrets are out of every runtime role's reach** (security review; this replaces the risk accepted in the first version of this ADR). `marketplace_app_secrets` (`app_id`, the client secret's sha256 digest, the app's 32-byte webhook key) has no grant at all to `app` or `app_platform`. `oauth_clients.secret` holds only the marker `external` for a confidential client. The table is reached only through security-definer functions, listed in ADR 002, none of which returns a stored secret:

- `oauth_client_secret_ok(client, sha256)`: the token endpoint's yes or no for a presented client secret (a 48-character random secret needs no slow hash);
- `app_install_webhook_key(install)`: one install's derived key, only for an active install of the session's tenant;
- `marketplace_store_app_secrets(app, sha256, key)`: written only from the app's developer tenant, or by the platform role for a first-party app (`marketplace:connector`);
- `marketplace_developer_active(developer)`: whether the developer may serve.

### OAuth with Passport, adapted to row-level security

- **Only the token endpoint is Passport's**, `POST /api/v1/oauth/token` (public). Rate-limited per client id and address (`marketplace.oauth.token_requests_per_minute`, 120), so one app refreshing for many tenants from one host is never held back by other clients at that address, with a looser ceiling per address across clients (`token_requests_per_minute_per_ip`, 600) (Phase 8 review M5). The client's window includes the address because a client id is public: anyone sending it from elsewhere never uses up the real app's window. A code or refresh token of a closed tenant is refused before it is redeemed (`invalid_grant`, `code: tenant_closed`, ADR 020). `Passport::ignoreRoutes()`: its Blade consent screen and client management are not served. The consent screen is the web app's `/oauth/authorize`, backed by `GET` and `POST /api/v1/oauth/authorize` (tenant user routes).
- **Grants.** Authorization code with PKCE and refresh token only. A `code_challenge` with method `S256` is required for every client, confidential or public (`plain` is refused). Clients carry only these two grants, and our client repository refuses any other grant type; client credentials, password, implicit and device grants are never usable.
- **Tokens live in the install's tenant.** `oauth_auth_codes`, `oauth_access_tokens` and `oauth_refresh_tokens` have `tenant_id` and forced RLS, with `user_id` a UUID (the install's principal). Our own repositories replace Passport's bridges:
  - every row is keyed by **sha256 of the code's or token's identifier**, never the identifier: access tokens are RS256 JWTs signed with the server's private key, and codes and refresh tokens are encrypted with the application key, so the tables alone never yield a usable token;
  - at the token endpoint and in the resource guard, `oauth_token_tenant(kind, id hash)` (security definer, returns the tenant id only, listed in ADR 002) names the tenant; the row is then read in that tenant's context, and the context is left at the end of the token request;
  - a token is issued only for an active install of a serving app, with a client that is not revoked, in an active tenant. An app serves only while it is listed, not suspended, and its developer is active: suspending a developer, or closing its tenant, stops every app of it at once (`marketplace_developer_active`).
- **Codes and refresh tokens are redeemed once, atomically.** Redemption is a conditional update (`revoked = false` to `true`, unexpired) whose row count decides: of two concurrent requests with the same code or refresh token, one wins and the other is refused, and the second use of a refresh token triggers the reuse rule below.
- **Refresh tokens rotate**: each is used once, revoking the access token it came with. A refresh token presented again after use revokes every code and token of that install (reuse detection, OAuth 2.0 security BCP).
- **Lifetimes**: access tokens 60 minutes, refresh tokens 30 days, codes 10 minutes (`config/marketplace.php`).
- **Keys**: `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`, or `php artisan passport:keys` files. Without them the OAuth endpoints answer 503 `oauth_not_configured` and no app token is accepted. The test suite makes a key pair per process; none is committed.
- **The token response** also names the install (`installation_id`).
- **A token never exceeds the consent**: it acts with the install's consented scopes, narrowed to the token's own scopes when the app asked for fewer (`scope` parameter).

### An install is an API key in all but name

Approving creates, in one transaction (serialised per tenant and app):

- a service principal (a user with status `service`), named after the app;
- an `api_keys` row of kind `app` holding the consented scopes, with a random secret nobody keeps (`sk_` authentication refuses kind `app`); Settings, API keys never lists or binds these;
- one role assignment at the scope the installer chose (tenant, company, branch or location), granted with **the same rule as API keys** (`Grants::assertCanGrant`, 403 `cannot_grant`): the installer must hold `core.role.assign` covering the scope and every permission of the role there, and never an Owner role (422 `app_owner_role`). An app can therefore never get more than its installer can grant, nor more than its scopes allow. Moving an install to another role or place on consent also needs the right to remove the old assignment (`Grants::assertCanRevoke`), as for a person;
- the role pickers (API keys, invitations, the consent screen and installed apps) offer what the API says may be granted at the chosen place, `GET roles?grantable_at=<tenant|any|type:id>` (`&grantable_for=invite` for invitations), computed by `Grants` itself. A permission of an inactive module that the installer's roles hold counts, so an Owner with only the core active can pick Admin;
- the install row (`app_installations`), and the webhook subscription below;
- audit `core.app.install` (and the assignment through `RoleManager`, RBAC-12).

The resource guard (`OAuthAuthenticator`, inside the Sanctum guard next to API keys) turns a valid JWT into the install's principal with its key as the request's key. Its failures are limited per token and address, after the token fails to resolve, and an expired but validly signed token is not counted (ADR 020, rate limits). From there everything is ADR 020's: deny by default outside `public-api` routes (403 `api_key_route_refused`), scopes per route and per permission, rate limits, usage, idempotency, and audit metadata naming the key, the install and the app.

**Permissions** (core, tenant scope; Owner and Admin templates): `core.marketplace.view` (the listing, an app's page, the consent screen, installed apps), `core.marketplace.install` (install, consent again, uninstall), `core.developer.manage` (the developer portal). Installing a paid app also needs `core.subscription.manage` (403 `app_payment_not_allowed`). Support sessions never hold `install`, `developer.manage` or `core.api_key.manage` (RBAC-13d: they make credentials; the last also resets a sandbox).

### Scope changes and consent

A newer approved version that asks for scopes or webhook events beyond what an install was given changes nothing for that install: its key keeps the consented scopes and its subscription the consented events. Installed apps shows "needs consent" with the new permissions in plain words; consenting (the OAuth flow again, or `POST apps/installed/{id}/consent`) records the new version, scopes, events and price (`core.app.consent`). A new price applies from the next renewal.

### Uninstall (MKT-02)

Every code and token is revoked, the key is revoked, the role assignment removed (through `RoleManager`), the app's webhook subscription switched off and archived (`disabled_reason = uninstalled`), and the install marked uninstalled, never deleted (TEN-06). Audited `core.app.uninstall`. The data the app created stays (no export of it is made).

### Webhooks for apps (HOOK-01)

The `api_key` principal type of ADR 021 is registered (`ApiKeyPrincipal`): it receives what the key's principal may see at its scope, without the fields its rules hide, and stops when the key is revoked, the install uninstalled or the app suspended. An app with a webhook URL and events gets one subscription per install, owned by the install's key, to `<webhook URL>?installation=<install id>`, signed with the install's own key (above), filtered to the company or branch its role is held at. It is not counted against the tenant's webhook cap.

- **The signed body names the install.** Every delivery's envelope carries `installation_id` and `tenant_id` next to `id`, `type` and `occurred_at`, inside the signed body, so a receiver tells installs apart from signed data, never from the URL's query string alone.
- **The subscription follows the install, not people.** Its URL, events, filter and description, its key (rotate-secret) and its archive are refused to people with 403 `webhook_app_owned`, and it cannot be taken over. People may only switch it off (`PATCH webhooks/{id}` with `active: false` and nothing else). Consent and uninstall change it as the install.

### Review (ADM-05)

Staff permissions: `platform.marketplace.view` (owner, support, finance, compliance, engineering), `platform.marketplace.review` (owner, compliance, engineering: a security review) and `platform.marketplace.payouts` (owner, finance). The console's queue lists submitted versions with what they add to the listed one (scopes, events, redirect URIs, price, whether installs need consent). Approving lists the version and sets the client's redirect URIs; the reviewer's request carries the version's `submitted_at` as they saw it, and the version is locked and compared: a version withdrawn, edited and submitted again meanwhile answers 409 `version_changed` and is not approved. Rejecting needs a reason (10 to 500 characters). Suspending (with a reason) stops tokens at once, webhooks and new installs; reinstating restores them. Every decision is audited in the platform chain and in the developer tenant's chain; a suspension or reinstatement also in each installing tenant's chain, through `app_tenants_with_app_installed()` (security definer, tenant ids only, answering only the platform role and the app's developer tenant, so no customer can list who else installed an app) and one job per tenant.

### Paid apps (MKT-03)

- **Pricing**: free, or paid with a monthly price per currency (KES, USD, CDF, minor units) and an optional yearly one (twelve months otherwise).
- **Billed through the subscription engine** (ADR 016). Billing exposes `AddOnCharges`; the marketplace binds it. Each period invoice carries one `app` line per paid, active install, in the subscription's currency and cycle. Installed during a paid period, the rest of the period is invoiced at once as an `upgrade` invoice, pro-rated by day as a plan upgrade is. Installed during the trial, nothing is charged until the first invoice. A suspended app is not billed. Uninstalling stops the next renewal's line; the period paid is not refunded. Unpaid app lines are unpaid invoice lines: dunning treats them like the plan.
- **The developers' ledger** (`marketplace_payables`, an operator tenant table, `GlobalTables::OPERATOR`). When an operator invoice is paid (`OperatorInvoicePaid`, raised in the operator's context inside the paying transaction), each `app` line earns the app's developer 80 % (`marketplace.developer_share_percent`) of the line's amount net of tax, half up, once per line (unique index). App lines never take a share of the plan's discounts (the coupon, the annual discount), so this is the app's full price net of tax (decision 9). Append-only (no update or delete grant).
- **Payouts are manual** until a provider is chosen: staff see balances per developer and currency, a developer's ledger, and record a payout with its reference, never more than the balance (serialised per developer and currency). Audited in the operator's chain and the platform chain.
- **Sandboxes** install paid apps free (API-05: never billed).
- **Suspended tenants** follow the existing rules: billing keeps working; app tokens stop because the tenant is not active, like every other non-exempt access. Webhooks to installed apps stop too (Phase 8 review M2, ADR 021): nothing is fanned out while the tenant is suspended, and deliveries already made wait, then resume when it is active again.

### First-party connectors (MKT-04)

Four listed, free apps of the platform's own developer, seeded by migration with fixed ids (`FirstParty`). Each is meant to run as a connector service that is an OAuth client of the public API like any app. All four are available. Until their public routes existed, the courier and biometric connectors were marked not available (Phase 8 review L6): not listed or shown, not installable, refused by `marketplace:connector`. The flag stays for any later connector, and `ConnectorsTest` fails when an available connector asks for a scope that no public route serves (a write route for a write scope; a read scope may instead be what a webhook event it receives requires), and when a connector marked not available has every scope served, so the flag is lifted once the routes exist. `GET marketplace/connectors` no longer names `public_api` as missing.

| Connector | Scopes | Events | Provider side |
| --- | --- | --- | --- |
| Courier | locations, online orders (read), deliveries (write); version 2 dropped customers (read), which reads every customer's contacts (security review M4): a courier gets the customer of an order handed to it from the courier routes. Version 1 stays as reviewed (a reviewed version never changes); migration `2026_12_26_000200` lists version 2, and no tenant had installed version 1 | `order.paid.v1`, `order.status_changed.v1` (`order.ready_for_delivery.v1` can be added in a later version) | the generic `CourierDriver` interface (quote, book, track, cancel; idempotent on our reference); `none` until the owner picks a courier, `fake` in local and tests |
| SMS and WhatsApp | customers, online orders, sales (read) | `sale.completed.v1`, `order.status_changed.v1` | the notifier's SMS and WhatsApp drivers (`notifications.drivers`) |
| Power BI and Excel | companies, branches, locations, items, item categories, customers, stock, sales (read) | none | **CSV feeds**: the existing public list endpoints with `?format=csv`, authorised with the connector's token or an API key; Power BI and Excel schedule the refresh. Chosen over new OData endpoints because the list endpoints already apply scopes, field rules (RBAC-05), sorting and the export audit |
| Biometric attendance | employees, devices (read), attendance (write) | none | the HR biometric device API (HR-24) on the public API |

**The courier's routes** (E-commerce, `routes/courier.php`, loaded by `CourierApiServiceProvider`; `public-api:ecommerce.order` and `public-api:ecommerce.delivery`):

- `GET ecommerce/courier/orders` (`status` = `open`, `ready`, `handed` or `all`) and `GET ecommerce/courier/orders/{id}`: delivery orders waiting for a courier (accepted, being picked or ready, with no rider) and those handed to the caller, at the locations where it holds `ecommerce.order.view`. The customer's name, phone, address and notes, and the tracking, are given only once the delivery is handed to the caller; the `ecom_order` field rules (RBAC-05) hide what they name. A rider's position or trail is never part of an answer.
- `POST ecommerce/courier/deliveries/{id}/booking` (`ecommerce.delivery.assign`): hands an unassigned delivery of a bookable order to the courier, with its tracking reference and HTTPS link (`ecom_deliveries.courier_reference`, `tracking_reference`, `tracking_url`, `booked_at`). The courier is a rider of the company whose user is the caller's principal, made on its first booking there (`Deliveries::assign` wants a person as rider); staff stop a courier at a company by archiving that rider (403 `courier_stopped`). Idempotent by the client's `reference`: the same reference and tracking answer the same, the same reference with other tracking is 422 `reference_reused`, a new reference updates the tracking of a delivery the courier holds.
- `POST ecommerce/courier/deliveries/{id}/status` (`ecommerce.delivery.deliver`): `picked_up`, `delivered` (with the customer's delivery code) or `failed` (with a reason), as one update of `RiderUpdates`, the rider app's service, whose id is derived from the principal and the client's `reference`: sent again it answers the same, the same reference with other details is 422 `payload_mismatch`. Location updates are not taken.
- Security review fixes: only service principals (API keys, apps) book and report (403 `courier_keys_only`; people use the staff and rider routes, so nobody makes themselves a rider through booking). A courier stopped at a company (its rider archived or switched off) gets 403 `courier_stopped` on every courier route there, nothing is handed to it (no customer contact), and the deliveries it holds that are not picked up go back to waiting with their tracking cleared, audited (`ecommerce.rider.courier_stopped` names them and the picked-up ones staff must confirm or return). `RiderUpdates` refuses an archived or switched-off rider, person or courier (`rider_stopped`). An order is shown only while its delivery waits for a courier or is handed to the caller (404 otherwise, as in the list). Tracking is cleared whenever the rider changes or the delivery ends before pick-up. Two first bookings at once make one rider (a savepoint, the loser reads the winner's row). The list reads a fixed number of queries whatever its length.
- Every change is audited as the principal through the delivery, rider and order models, with the key and the install in the entry's metadata. `order.ready_for_delivery.v1` (HOOK-01) tells a courier an order is ready and its delivery waits.

**The biometric connector's routes** (HR, `public-api:hr.employee`, `hr.device`, `hr.attendance`):

- `GET hr/connector/employees`: the active roster at the places where the caller holds `hr.employee.view`: id, display name and enrolment reference only, never a sensitive group (HR-09); the `hr_employee` field rules hide the reference, or the name when they hide the first or last name.
- `GET hr/connector/devices`: attendance devices where the caller holds `hr.device.view` (a new permission, so the connector's `hr.device.read` scope reads them; read-only roles get it through `hr.*.view`). Tokens are never shown.
- `POST hr/connector/devices/{id}/punches` (`hr.attendance.manage` at the device's location): the device's punches through `PunchUploads::fromDevice`, the device's own path (idempotent by the device's event id, the 7-day offline window, matched by enrolment reference to an active employee of the device's company). Only an active device takes punches (409 `device_not_active`).

Each group also has its own rate limits per caller and route (`ecommerce.courier.*_per_minute`, `hr.connector.*_per_minute`, 120 by default) besides the public API's per key and tenant. `CourierApiTest` and `AttendanceConnectorTest` cover scopes, other tenants, keys and app tokens, field rules, idempotency and the limits.

A connector's OAuth client stays revoked, with no redirect URI, until the owner sets up its service: `php artisan marketplace:connector <courier|messaging|bi|biometric>` reads `CONNECTOR_<KEY>_REDIRECT_URI` (and webhook URL), prints the client secret and webhook secret once, and audits in the platform chain; `--rotate` issues new secrets, `--disable` switches it off (its tokens stop). Both are also audited in the chain of each tenant that installed the connector (`core.app.connector_rotated`, `core.app.connector_disabled`), through `app_tenants_with_app_installed()` and one job per tenant, as a suspension is (Phase 8 review L7). `GET marketplace/connectors` names what is missing for each (`connector_service`, `courier_provider`, `message_provider`) and never invents a value.

### Export (EXP-02)

The new tenant tables are exported. `app_installations.api_key_id` (a row id) and `oauth_refresh_tokens.access_token_id` (a hash of a JWT identifier) are allowed; no secret or bearer value is stored in them.

## Decisions (accepted by the owner 2026-10-22)

All nine were accepted by the owner on 2026-10-22, decision 9 with a change: a plan coupon is never spread across app lines.

1. **An app acts as an existing role the installer picks** (like API keys), limited by the app's scopes, instead of a role generated from the scopes. Safer (the `cannot_grant` rule is unchanged) but the installer has to choose a role.
2. **The developer's 80 % is computed on the line net of discount and tax**, and earned when the invoice is paid, not when it is issued. Since decision 9's change, an app line carries no discount, so this is the app's full price net of tax.
3. **Paid apps are free during the platform trial**, as plan changes are; no refund for the rest of a period when an app is uninstalled; a new price applies from the next renewal.
4. **Developers are tenants**: a developer account is a tenant's (sandbox or live), not a separate identity. Payout details (bank, mobile money) are not stored; staff contact the developer by its email and record the payout reference.
5. **Paid apps in a sandbox are free**; sandboxes are never billed (API-05).
6. **The Power BI and Excel connector uses CSV feeds** of the existing list endpoints rather than new OData endpoints.
7. **Staff review roles**: compliance and engineering review apps; finance records payouts; support only views.
8. **Per-install webhook keys are derived, not delivered**: the developer computes each install's key from the app's webhook secret and the signed `installation_id` (`HMAC-SHA256`, docs/webhooks.md). This replaces the earlier accepted risk (app secrets readable by tenant sessions), which is removed.
9. **Plan coupons never reduce the developer's 80 %** (changed by the owner, 2026-10-22). A plan-level coupon applies to the plan lines only (bundle, module, unit and overage lines) and is never spread across marketplace app lines, so an app line is billed at the developer's price and the developer's share is computed on the app's full price, net of tax. The annual discount is the plan's too and is spread the same way. `Invoicing::withTax` gives app lines no share of the invoice's negative lines; only an invoice with no other positive line (an app's pro-rated install charge with a rounding line) spreads over its app lines, so the lines still add up to the total. Built as first proposed, the coupon was spread across every line, app lines included, which lowered the developer's 80 %. Test: `PaidAppsTest::test_a_plan_coupon_never_lowers_the_app_line_or_the_developers_share`.

## Owner inputs still needed

- `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` on every API server (or `php artisan passport:keys`), kept out of the repository.
- For each connector service: its redirect URI and webhook URL (`CONNECTOR_*`), then `marketplace:connector` on the API host, and the printed secrets in the service's configuration.
- A courier provider and its credentials (a `CourierDriver`), and the SMS and WhatsApp providers (`NOTIFICATIONS_SMS_DRIVER`, `NOTIFICATIONS_WHATSAPP_DRIVER`).
- A payout provider, if payouts should stop being manual.
- The marketplace terms text the developer accepts.

## Consequences

- Apps reuse ADR 020 end to end: one principal mechanism, one set of tests for scopes, rate limits and refusal of non-public routes.
- Passport is used for its authorization server only; upgrading it needs our repositories and the `oauth_*` migration kept in step with its schema.
- Staff review writes commit on the platform connection; tests make listed apps on the owner connection and remove them (`BuildsMarketplace`).
- Each app request costs a few more queries than a key (the install, the app, the client) on top of the JWT check.
