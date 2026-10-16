# ADR 020: The public API (keys, scopes, limits, documentation, sandbox)

Status: Accepted (Phase 8, stage 8A: API-01, API-02 keys, API-03, API-04, API-05)

## Context

The spec asks for a public REST API (API-01) reached with per-tenant API keys (API-02; OAuth for marketplace apps follows in 8B), rate limits per key and per tenant with clear headers (API-03), an OpenAPI document and a developer portal in English and French (API-04), and a free sandbox (API-05). The Phase 8 plan sets the ground rule: **the public API is the same API**. `/api/v1` routes are reached with keys, and the same policies, scope resolver and field rules apply as for people. Nothing bypasses row-level security or permissions.

About 950 method signatures across policies, services and resources take an `App\Core\Identity\Models\User` (`ScopeResolver::can(User ...)`, `PartyPolicy::view(User ...)`, `FieldRules::for(User ...)`), and every business table records its author in a `created_by` column with a foreign key to `users`. Adapting all of them to a new principal type would touch every module.

## Decision

### Principals

- **A key is a service principal backed by a user row.** Creating a key creates a `users` row with status `service`, named after the key, with no email, phone or usable password. The key row (`api_keys`) points at it (`principal_id`). Because the principal *is* a `User`, every policy, `can()`, `ScopeResolver`, `FieldRules`, `LimitRules`, `created_by` column and audit entry works unchanged.
- **Status `service` keeps principals out of everything meant for people.** Every place that lists, counts or notifies people filters on `status = 'active'`: approver and stage holders, owner counting (RBAC-10), usage limits (users unit), alert recipients, delegation candidates. A principal is therefore never an approver, never an Owner for owner safety, never counted against the plan. `User::isActive()` answers true for a principal, so `ScopeResolver` lets it act. The users list, the user lookup and `users/{user}` route binding leave principals out, so they are managed only through `api-keys`. The `users_email_or_phone_check` constraint allows both to be null only for status `service`, and sign-in never finds a principal (no login, and `Authenticate` refuses status `service`). A person's token whose user turned into a principal is refused (`authenticateAccessTokensUsing`).
- **One role assignment, scoped like a person's.** The key holds exactly one row in `role_assignments` (tenant, company, branch or location; RBAC-04). It is granted through `Grants`, the same anti-escalation check as assigning a role to a person: the creator must hold `core.role.assign` covering the scope and every permission of the role there. Owner roles cannot be given to a key.
- **Who manages keys.** `core.api_key.manage` at tenant scope (Owner template). Keys never manage keys.

### Secrets

- Format: `sk_live_<id>_<secret>` for live tenants, `sk_test_<id>_<secret>` for sandbox tenants. `<id>` is 16 lowercase letters and digits, stored in `api_keys.public_id` (unique); `<secret>` is 40 random letters and digits. The key is shown once, in the create or rotate response. Only `sha256(secret)` is stored, as Sanctum does for tokens (the secret has 238 bits of entropy, so a slow hash adds nothing).
- **Lookup before the tenant is known** follows ADR 002: the security-definer function `api_key_tenant(p_public_id text, p_sandbox boolean)` returns only the tenant id of a key whose tenant's sandbox flag matches the prefix. The guard then enters that tenant and reads the key under row-level security, compares hashes with `hash_equals`, and refuses a revoked or expired key, a tenant that is not active, or a principal that is not status `service`.
- **Rotation with overlap.** Rotating keeps the id, stores the new secret's hash and moves the old hash to `previous_secret_hash` with `previous_expires_at` (0 to 168 hours, default 24). Both secrets work until then. Revoking clears both at once.
- Every create, update, rotate and revoke is audited on the key (`core.api_key.*`, AUD-01, RBAC-12), and the principal's assignment through `RoleManager` as for people.

### Authentication and which routes accept keys

- The `sanctum` guard driver is replaced by a subclass (`ApiKeyAwareGuard`) that handles a bearer token starting with `sk_` and leaves every other token to Sanctum. All existing `auth:sanctum` groups, in core and modules, therefore know keys without edits.
- **Deny by default.** A key is accepted only on routes marked with the `public-api:<module.resource>` middleware. On any other route (sign-in, two-factor, sessions, `me`, onboarding, billing and payment, platform, device and attendance-device routes, key management) the guard answers 403 `api_key_route_refused` before the controller runs. Device and platform routes are refused twice: they also require a device or a platform token.
- `AuditContext` records `api_key_id` in the metadata of every audit entry written during a key request, and the entry's `user_id` is the principal. "Who did this" reads as the key's name everywhere a creator is shown.

### Scopes

- A key has a list of scopes `module.resource.read` or `module.resource.write`, from the catalogue of resources that public routes declare (`GET api-keys/scopes`). `write` includes `read`.
- Scopes limit the key twice:
  1. **Per route.** `public-api:core.item` requires `core.item.read` for GET and HEAD, `core.item.write` otherwise (403 `api_scope_missing`).
  2. **Per permission check.** `ScopeResolver` drops, for a principal, any permission `module.resource.action` whose resource scope the key lacks: `view*`, `export` and `print` actions need `read`, every other action `write`. This covers checks made deeper in the request (lists filtered by `visibleIds`, custom fields, related records), so a key never exceeds either its role or its scopes.

### Versioning

- One version in the path, `/api/v1`. Changes inside v1 are additive: new endpoints, new optional parameters, new response fields. Clients must ignore unknown fields.
- A breaking change ships as a new operation (or v2), and the old one is deprecated first.

### Deprecation and sunset

- `config/api.php` `deprecations` lists operations as `"METHOD uri"` with `deprecated_at`, `sunset` (at least 6 months later) and a changelog anchor. The `public-api` middleware adds `Deprecation: @<unix time>` (RFC 9745), `Sunset: <HTTP date>` (RFC 8594) and `Link: <changelog>; rel="deprecation"`. The OpenAPI document marks them `deprecated: true`.
- `php artisan api:docs --check` (CI) fails when the generated document drops an operation of the committed baseline (`resources/openapi/v1.json`) that has no deprecation entry, or when the baseline is stale.

### Rate limits

- Two fixed one-minute windows per request, counted in the cache (Redis in production): per key, and per tenant across its keys. The tenant limit is the plan's (`catalogue_bundles.api_requests_per_minute`, null for the default), falling back to `api.rate_limits.tenant_per_minute`; the key limit is `api.rate_limits.key_per_minute`.
- Every key response carries `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` (seconds) for the tighter of the two windows. A refused request answers 429 `rate_limited` with `Retry-After`, in the error shape below, in English or French.
- People's requests keep their existing per-route limiters.

### Errors

- Every API error keeps the shape the web app already uses: `{message, code, errors?}`, with a translated `message` (Accept-Language, then the tenant's language) and a stable machine `code`.
- For key requests the same body is served as `application/problem+json` (RFC 9457) with `type` (`/developers/errors#<code>`), `title`, `status` and `detail` added. `code` stays the field to branch on.

### Pagination and filtering

- Lists take `page` and `per_page` (1 to 200, default 50), `search`, `sort=key|-key` from a per-list whitelist (422 otherwise; ties broken by id) and their own documented filters (ADR 008).
- Responses are `{data, links: {first, last, prev, next}, meta: {current_page, last_page, per_page, total, ...}}`. Field rules apply to sorts and filters as well as fields (RBAC-05).

### Idempotency

- A key's POST may send `Idempotency-Key` (1 to 255 of `A-Z a-z 0-9 - _ . :`). The `public-api` middleware stores the first response for 24 hours per key (`api_idempotency_keys`, under row-level security), with a hash of the method, path and body:
  - the same key and body again replays the stored status and body, with `Idempotent-Replayed: true`;
  - the same key with another body answers 422 `idempotency_mismatch`;
  - the same key while the first request still runs answers 409 `idempotency_in_progress`;
  - a 5xx is not stored, so the client may retry.
- Modules with their own idempotency (POS sale UUIDs, e-commerce orders, loyalty charges) keep it; the header adds a uniform layer for every key POST.

### Usage

- `api_key_usage_days` counts requests per key and UTC day (one upsert per request). The back office shows requests per day; `last_used_at` is updated at most once a minute.

### Documentation (API-04)

- `dedoc/scramble` generates OpenAPI 3.1 from routes, Form Requests and API Resources, for routes with the `public-api` middleware only. Its own UI routes are switched off.
- Operation summaries and descriptions come from `lang/{en,fr}/api_reference.php`, keyed by operation id (`<controller>.<method>`); the document carries English and an `x-i18n.fr` extension. `api:docs --check` fails when a public route has no entry, and the translation parity test keeps French in step.
- The committed document is served at `GET /api/v1/developers/openapi.json` (public, rate-limited). The web app's `/developers` portal (public pages) renders guides, examples, the changelog and the reference from it with design-system components; no dependency.

### Sandbox (API-05)

- `tenants.is_sandbox`, set only at sign-up (`sandbox: true`). A sandbox tenant:
  - gets every module switched on and the sample data loaded (Phase 6 `SampleData`);
  - has no subscription, trial or invoice, is skipped by usage metering, and `UsageLimits` never refuses it;
  - uses the fake payment and fiscal drivers whatever a method or company is configured with, even in production, since no money or tax document is real there (`Sandbox::active()` in `PaymentProviderRegistry` and `FiscalDrivers`);
  - can be reset by its Owner (`POST sandbox/reset`): every item, category, price list and party is archived and a fresh sample loaded;
  - is isolated by row-level security like every tenant.
- Sandbox keys start `sk_test_` and live keys `sk_live_`; the prefix is part of the lookup, so a test key never reaches a live tenant and a live key never reaches a sandbox.

## Consequences

- Policies and services need no changes to serve keys, and a key's writes are attributed through the same `created_by` and audit columns as a person's.
- Code that selects people must keep filtering on `status = 'active'` (or `User::STATUS_ACTIVE`); a query on all users of a tenant also returns principals. The isolation suite and the new tests cover the known lists.
- Adding a public route means the `public-api:<resource>` middleware, en/fr entries in `api_reference.php`, and regenerating the baseline (`php artisan api:docs --write`). Removing one needs a deprecation entry first.
- The idempotency and usage tables grow with traffic; idempotency rows of a tenant are pruned lazily (expired rows deleted on a 1 in 100 lottery per key request).
- OAuth for marketplace apps (8B) adds another principal through the same mechanism: an install's principal user, with the consented scopes.
