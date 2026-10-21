# ADR 024: The marketplace (developer apps, OAuth installs, review, paid apps, connectors)

Status: Accepted (Phase 8, stage 8B: MKT-01..MKT-04, ADM-05, API-02 OAuth; with TEN-01, RBAC-04, RBAC-05, RBAC-12, AUD-01, HOOK-01, SUB-03, EXP-02)

## Context

The spec asks for a marketplace where developers register and submit apps with a description, permissions and pricing, reviewed by platform staff before listing (MKT-01, ADM-05); tenants install with one click after seeing and approving the requested permissions, and uninstall at any time (MKT-02); paid apps are billed through our subscription system, 80 % to the developer (MKT-03); first-party connectors are available at launch (MKT-04). API-02 adds OAuth 2.0 for marketplace apps next to API keys.

The public API (ADR 020) already has service principals: a key is a `users` row with status `service`, one role assignment and scopes, so every policy, `ScopeResolver`, field rule, rate limit and audit entry works for it unchanged. Webhooks (ADR 021) left an `api_key` principal type for later. The owner approved `laravel/passport` for OAuth (Phase 8 plan).

## Decision

### Developers

- **A developer account belongs to a tenant.** Any tenant (a sandbox from the developer portal, API-05, or a live one) registers once with a public name, a contact email and the marketplace terms, which state the 80/20 share (`PUT developer`, `core.developer.manage`, Owner and Admin). The platform's own developer (`first_party`, no tenant) publishes the connectors.
- **Apps and versions.** An app has a unique slug, an OAuth client (one per app) and a webhook signing secret. What a tenant consents to is a version: name, tagline, description, category, logo, website, support email, privacy URL, redirect URIs, scopes, webhook URL and events, and pricing. Texts are stored once, in the language the developer wrote them (names and texts are not per-language). A draft is edited, submitted, then approved or rejected by staff; a submitted version may be withdrawn to a draft; one draft or submitted version at a time. A reviewed version never changes (trigger); a change is a new version copied from the latest.
- **Secrets.** The client secret (48 characters) is shown once and stored hashed by Passport. The webhook signing secret (`whsec_`, Standard Webhooks) is shown once and stored encrypted, because every install's subscription signs with it. Both can be rotated; a rotated webhook secret reaches each installing tenant by a job in its own context, the previous one signing for 24 hours.
- **Redirect URIs** are HTTPS, or HTTP to a loopback address for native and local apps (RFC 8252), never with a fragment or credentials. The webhook URL follows the webhook rules (HTTPS, public address, ADR 021).

### Global, developer-owned tables under row-level security

`marketplace_developers`, `marketplace_apps`, `marketplace_app_versions` and `oauth_clients` have no `tenant_id`: an app is published to every tenant. They are listed in `GlobalTables::MARKETPLACE` and forced under row-level security keyed on `developer_tenant_id`:

| Role | Reads | Writes |
| --- | --- | --- |
| `app` (tenant runtime) | its own developer rows; apps that have been listed, approved versions, and the developer of a listed app; every client (the token endpoint runs before a tenant is known) | inserts its own rows only (`developer_tenant_id` = the session tenant, a draft, not listed); updates its own drafts and submissions, archives its own apps; column grants keep `listed_version_id`, review fields and statuses out of reach |
| `app_platform` (staff) | everything | only review columns: version status (submitted to approved or rejected), the listed version, suspension, the client's redirect URIs; and the first-party connectors' secrets (`marketplace:connector`) |

Nobody deletes (no `DELETE` or `TRUNCATE` grant). `RlsCoverageTest` checks the policies and grants.

Accepted risk: the runtime role can read every client's secret hash and every listed app's encrypted webhook secret, because the token endpoint and installs in other tenants need them. The application never serialises either (`$hidden`), the hash is bcrypt, and the webhook secret is encrypted with the application key.

### OAuth with Passport, adapted to row-level security

- **Only the token endpoint is Passport's**, `POST /api/v1/oauth/token` (public, rate-limited per IP). `Passport::ignoreRoutes()`: its Blade consent screen and client management are not served. The consent screen is the web app's `/oauth/authorize`, backed by `GET` and `POST /api/v1/oauth/authorize` (tenant user routes).
- **Grants.** Authorization code with PKCE and refresh token only. A `code_challenge` with method `S256` is required for every client, confidential or public (`plain` is refused). Clients carry only these two grants, and our client repository refuses any other grant type; client credentials, password, implicit and device grants are never usable.
- **Tokens live in the install's tenant.** `oauth_auth_codes`, `oauth_access_tokens` and `oauth_refresh_tokens` have `tenant_id` and forced RLS, with `user_id` a UUID (the install's principal). Our own repositories replace Passport's bridges:
  - every row is keyed by **sha256 of the code's or token's identifier**, never the identifier: access tokens are RS256 JWTs signed with the server's private key, and codes and refresh tokens are encrypted with the application key, so the tables alone never yield a usable token;
  - at the token endpoint and in the resource guard, `oauth_token_tenant(kind, id hash)` (security definer, returns the tenant id only, listed in ADR 002) names the tenant; the row is then read in that tenant's context, and the context is left at the end of the token request;
  - a token is issued only for an active install of a serving app, with a client that is not revoked, in an active tenant.
- **Refresh tokens rotate**: each is used once, revoking the access token it came with. A refresh token presented again after use revokes every code and token of that install (reuse detection, OAuth 2.0 security BCP).
- **Lifetimes**: access tokens 60 minutes, refresh tokens 30 days, codes 10 minutes (`config/marketplace.php`).
- **Keys**: `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`, or `php artisan passport:keys` files. Without them the OAuth endpoints answer 503 `oauth_not_configured` and no app token is accepted. The test suite makes a key pair per process; none is committed.
- **The token response** also names the install (`installation_id`).
- **A token never exceeds the consent**: it acts with the install's consented scopes, narrowed to the token's own scopes when the app asked for fewer (`scope` parameter).

### An install is an API key in all but name

Approving creates, in one transaction (serialised per tenant and app):

- a service principal (a user with status `service`), named after the app;
- an `api_keys` row of kind `app` holding the consented scopes, with a random secret nobody keeps (`sk_` authentication refuses kind `app`); Settings, API keys never lists or binds these;
- one role assignment at the scope the installer chose (tenant, company, branch or location), granted with **the same rule as API keys** (`Grants::assertCanGrant`, 403 `cannot_grant`): the installer must hold `core.role.assign` covering the scope and every permission of the role there, and never an Owner role (422 `app_owner_role`). An app can therefore never get more than its installer can grant, nor more than its scopes allow;
- the install row (`app_installations`), and the webhook subscription below;
- audit `core.app.install` (and the assignment through `RoleManager`, RBAC-12).

The resource guard (`OAuthAuthenticator`, inside the Sanctum guard next to API keys) turns a valid JWT into the install's principal with its key as the request's key. From there everything is ADR 020's: deny by default outside `public-api` routes (403 `api_key_route_refused`), scopes per route and per permission, rate limits, usage, idempotency, and audit metadata naming the key, the install and the app.

**Permissions** (core, tenant scope; Owner and Admin templates): `core.marketplace.view` (the listing, an app's page, the consent screen, installed apps), `core.marketplace.install` (install, consent again, uninstall), `core.developer.manage` (the developer portal). Installing a paid app also needs `core.subscription.manage` (403 `app_payment_not_allowed`). Support sessions never hold `install` or `developer.manage` (RBAC-13d: they make credentials).

### Scope changes and consent

A newer approved version that asks for scopes or webhook events beyond what an install was given changes nothing for that install: its key keeps the consented scopes and its subscription the consented events. Installed apps shows "needs consent" with the new permissions in plain words; consenting (the OAuth flow again, or `POST apps/installed/{id}/consent`) records the new version, scopes, events and price (`core.app.consent`). A new price applies from the next renewal.

### Uninstall (MKT-02)

Every code and token is revoked, the key is revoked, the role assignment removed (through `RoleManager`), the app's webhook subscription switched off and archived (`disabled_reason = uninstalled`), and the install marked uninstalled, never deleted (TEN-06). Audited `core.app.uninstall`. The data the app created stays (no export of it is made).

### Webhooks for apps (HOOK-01)

The `api_key` principal type of ADR 021 is registered (`ApiKeyPrincipal`): it receives what the key's principal may see at its scope, without the fields its rules hide, and stops when the key is revoked, the install uninstalled or the app suspended. An app with a webhook URL and events gets one subscription per install, owned by the install's key, to `<webhook URL>?installation=<install id>`, signed with the app's secret, filtered to the company or branch its role is held at. It is not counted against the tenant's webhook cap. People cannot change its URL or events (`webhook_not_owner`); they may switch it off.

### Review (ADM-05)

Staff permissions: `platform.marketplace.view` (owner, support, finance, compliance, engineering), `platform.marketplace.review` (owner, compliance, engineering: a security review) and `platform.marketplace.payouts` (owner, finance). The console's queue lists submitted versions with what they add to the listed one (scopes, events, redirect URIs, price, whether installs need consent). Approving lists the version and sets the client's redirect URIs; rejecting needs a reason (10 to 500 characters). Suspending (with a reason) stops tokens at once, webhooks and new installs; reinstating restores them. Every decision is audited in the platform chain and in the developer tenant's chain; a suspension or reinstatement also in each installing tenant's chain, through `app_tenants_with_app_installed()` (security definer, tenant ids only) and one job per tenant.

### Paid apps (MKT-03)

- **Pricing**: free, or paid with a monthly price per currency (KES, USD, CDF, minor units) and an optional yearly one (twelve months otherwise).
- **Billed through the subscription engine** (ADR 016). Billing exposes `AddOnCharges`; the marketplace binds it. Each period invoice carries one `app` line per paid, active install, in the subscription's currency and cycle. Installed during a paid period, the rest of the period is invoiced at once as an `upgrade` invoice, pro-rated by day as a plan upgrade is. Installed during the trial, nothing is charged until the first invoice. A suspended app is not billed. Uninstalling stops the next renewal's line; the period paid is not refunded. Unpaid app lines are unpaid invoice lines: dunning treats them like the plan.
- **The developers' ledger** (`marketplace_payables`, an operator tenant table, `GlobalTables::OPERATOR`). When an operator invoice is paid (`OperatorInvoicePaid`, raised in the operator's context inside the paying transaction), each `app` line earns the app's developer 80 % (`marketplace.developer_share_percent`) of the line's amount net of discount and tax, half up, once per line (unique index). Append-only (no update or delete grant).
- **Payouts are manual** until a provider is chosen: staff see balances per developer and currency, a developer's ledger, and record a payout with its reference, never more than the balance (serialised per developer and currency). Audited in the operator's chain and the platform chain.
- **Sandboxes** install paid apps free (API-05: never billed).
- **Suspended tenants** follow the existing rules: billing keeps working; app tokens stop because the tenant is not active, like every other non-exempt access.

### First-party connectors (MKT-04)

Four listed, free apps of the platform's own developer, seeded by migration with fixed ids (`FirstParty`). Each is meant to run as a connector service that is an OAuth client of the public API like any app:

| Connector | Scopes | Events | Provider side |
| --- | --- | --- | --- |
| Courier | locations, customers (read), online orders (read), deliveries (write) | `order.paid.v1`, `order.status_changed.v1` | the generic `CourierDriver` interface (quote, book, track, cancel; idempotent on our reference); `none` until the owner picks a courier, `fake` in local and tests |
| SMS and WhatsApp | customers, online orders, sales (read) | `sale.completed.v1`, `order.status_changed.v1` | the notifier's SMS and WhatsApp drivers (`notifications.drivers`) |
| Power BI and Excel | companies, branches, locations, items, item categories, customers, stock, sales (read) | none | **CSV feeds**: the existing public list endpoints with `?format=csv`, authorised with the connector's token or an API key; Power BI and Excel schedule the refresh. Chosen over new OData endpoints because the list endpoints already apply scopes, field rules (RBAC-05), sorting and the export audit |
| Biometric attendance | employees, devices (read), attendance (write) | none | the HR biometric device API (HR-24) |

A connector's OAuth client stays revoked, with no redirect URI, until the owner sets up its service: `php artisan marketplace:connector <courier|messaging|bi|biometric>` reads `CONNECTOR_<KEY>_REDIRECT_URI` (and webhook URL), prints the client secret and webhook secret once, and audits in the platform chain; `--rotate` issues new secrets, `--disable` switches it off (its tokens stop). `GET marketplace/connectors` names what is missing for each (`connector_service`, `courier_provider`, `message_provider`) and never invents a value.

### Export (EXP-02)

The new tenant tables are exported. `app_installations.api_key_id` (a row id) and `oauth_refresh_tokens.access_token_id` (a hash of a JWT identifier) are allowed; no secret or bearer value is stored in them.

## Decisions to confirm (owner)

1. **An app acts as an existing role the installer picks** (like API keys), limited by the app's scopes, instead of a role generated from the scopes. Safer (the `cannot_grant` rule is unchanged) but the installer has to choose a role.
2. **The developer's 80 % is computed on the line net of discount and tax**, and earned when the invoice is paid, not when it is issued.
3. **Paid apps are free during the platform trial**, as plan changes are; no refund for the rest of a period when an app is uninstalled; a new price applies from the next renewal.
4. **Developers are tenants**: a developer account is a tenant's (sandbox or live), not a separate identity. Payout details (bank, mobile money) are not stored; staff contact the developer by its email and record the payout reference.
5. **Paid apps in a sandbox are free**; sandboxes are never billed (API-05).
6. **The Power BI and Excel connector uses CSV feeds** of the existing list endpoints rather than new OData endpoints.
7. **Staff review roles**: compliance and engineering review apps; finance records payouts; support only views.
8. **Encrypted app webhook secrets and client secret hashes are readable under RLS by every tenant session** (the token endpoint and cross-tenant installs need them; never serialised). The alternative, per-install secrets delivered to the app, needs a delivery channel we do not have.

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
