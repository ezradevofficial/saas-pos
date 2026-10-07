# ADR 006: Roles and permissions (Spatie, extended)

Status: Accepted (Sprint 1, RBAC-01 to RBAC-06, RBAC-08 to RBAC-10, RBAC-12; AUTH-03)

## Context

The spec asks for several things:

- a global permission catalogue named `module.resource.action` (RBAC-01)
- tenant-owned roles, with system templates that can be copied but not edited (RBAC-02, RBAC-03)
- **scoped** assignments: Manager at Branch A, Cashier at Branch B, with company and branch roles covering what lies beneath (RBAC-04)
- field and limit rules (RBAC-05, RBAC-06)
- module flags (RBAC-08)
- UI-driving permission lists (RBAC-09)
- owner safety (RBAC-10)
- an audit of every change (RBAC-12)

`spatie/laravel-permission` is the standard Laravel package for the catalogue, roles, role-permission links and the Gate integration. It has no notion of scope beyond "teams". Its cache is a single global key, so on a multi-tenant database it would serve one tenant's roles to another (review focus 4).

## Decision

### Use Spatie for

- the `permissions` table, the catalogue
- the `roles` table
- `role_has_permissions`
- the `PermissionRegistrar` cache
- `can()` / `@can` / middleware integration

Spatie 8 is configured with UUID keys and our own models: `App\Core\Rbac\Models\Permission` and `Role`.

### Do not use Spatie for

- direct `model_has_roles` / `model_has_permissions` assignments. Those tables are **not created**.
- the "teams" feature. Scope lives in our own layer.

### Our extensions

**Catalogue (RBAC-01).**

- Modules register their permissions in `PermissionRegistry`.
- `php artisan permissions:sync` upserts them into the global `permissions` table. It never deletes, and it warns about names that are no longer declared.
- `composer migrate:fresh` seeds the catalogue, `composer setup` syncs it, and every deploy runs the sync.
- The catalogue table is global (ADR 002) and **only the schema owner writes it**. `permissions:sync` (and the seeder that calls it) writes through the `pgsql_owner` connection. The runtime role `app` has `SELECT` only on `permissions` (and `migrations`): a deleted permission cascades to `role_has_permissions` of every tenant, so a runtime write would cross tenants. `PermissionCatalogueWritesTest` proves `app` is refused.

**Tenant roles under RLS (RBAC-02, RBAC-03).**

- `roles` and `role_has_permissions` carry `tenant_id` with forced row-level security.
- The links have a composite FK `(tenant_id, role_id)`, so a link can only point at a role of its own tenant.
- Role names are unique per tenant among unarchived roles. Archiving a role frees its name.
- System templates (`role-templates.php`, `RoleTemplates`) are seeded into each tenant on `TenantProvisioned`, named in the tenant's default locale.
- System roles refuse every edit (403 `system_role`). They can be copied.

**Scoped assignments (RBAC-04).**

- The `role_assignments` table has `(tenant_id, user_id, role_id, scope_type, scope_id)`. `scope_type` is one of `tenant`, `company`, `branch` or `location`.
- `ScopeResolver` answers "may this user do X here?" with **downward inheritance**:
  - a tenant assignment covers everything
  - a company covers itself, its branches and their locations
  - a branch covers itself and its locations
- `visibleIds()` expands a permission into company, branch and location id sets. Lists, search, reports and exports filter with them.
- **Archived scopes keep granting.** Archiving a company, branch or location does not change coverage, so archived records can still be viewed and restored (TEN-06). Hiding archived records is the list queries' job.
- These grant **nothing**:
  - archived roles
  - inactive users
  - permissions of inactive modules (RBAC-08)

**Gate integration (RBAC-09).** `Gate::before` answers only abilities named `module.resource.action`, and only through `ScopeResolver`, when the argument is:

- a `HasScope` model or a `Scope`, checked at that scope
- nothing, or a class name, meaning "anywhere"

Any other argument, for example a `User`, returns `null`, so that model's policy decides. It never falls back to "anywhere": the check fails closed. Abilities that are not permission names fall through to policies.

**Policies.** `UserPolicy` and `RolePolicy` handle the targets without a scope of their own:

- **Users.**
  - Seeing a user needs the permission at a scope covering **at least one** of the user's assignments.
  - **Destructive actions need the actor to cover every one of the target's assignment scopes.** These are edit, deactivate, reactivate and sign out everywhere. A branch manager cannot disable someone who also holds a role elsewhere.
  - Managing anyone holding the Owner role takes an Owner.
- **Roles** are tenant-wide. Managing them takes the permission at tenant scope.

**Anti-escalation (`Grants`, `RoleManager`).**

- To grant role R at scope S, the actor must meet all of these:
  - hold `core.role.assign` covering S
  - hold **every permission of R** at a scope covering S (otherwise 403 `cannot_grant`)
  - be an Owner, if R is an owner role
- Creating or editing a role can only add permissions the actor holds at tenant scope.
- **Permissions of inactive modules get no exemption.** They count when checking what the actor holds, so they cannot be handed out now and take effect on activation.
- `PATCH roles` merges the role's existing inactive-module permissions into the submitted set, because clients never see them. Consequence: such permissions cannot be removed through `PATCH` until the module is active. This is deliberate.
- Scopes and roles must exist in the tenant (404 otherwise, so ids out of scope are never confirmed). They must not be archived for a new grant (422 `parent_archived`).

**Owner safety (RBAC-10, `OwnerGuard`).**

- A tenant always keeps at least one active Owner: an active user holding an unarchived owner role at tenant scope.
- Every removal path checks it: unassign, role change, user deactivation and role archive.
- Each check runs inside the changing transaction, after `pg_advisory_xact_lock(hashtext('owners:' || tenant_id))`. Concurrent removals are therefore serialised.
- A deactivated Owner still counts as an Owner to manage.

**Two-factor by role (AUTH-03).**

- `roles.requires_two_factor` marks roles whose holders need a second factor.
- When a holder without 2FA signs in, they get an enrol-only token.
- When a role starts requiring 2FA, or a requiring role is assigned, `TwoFactor::downgradeTokens` limits every existing token of the affected users to enrolment.
- A user cannot disable 2FA while a role requires it (409).

**Field and limit rules (RBAC-05, RBAC-06).**

- `FieldRules` is the most permissive across the user's roles. A field is hidden only when every role hides it.
- `LimitRules` takes the highest value across roles at covering scopes. "No rule" means "not allowed".
- Policies, API resources and the UI read both.

**Per-tenant permission cache (review focus 4).**

- **Keyed per tenant.** `RbacServiceProvider` sets Spatie's cache key to `permission.cache.<tenant_id>`, or `permission.cache.none` without a tenant. The in-memory collection is dropped on every tenant change (`TenantContext::onChange`). A flush clears only the current tenant.
- **Transaction-safe** (`TenantPermissionRegistrar`):
  - A flush inside a transaction is repeated **after commit**, for the key current at the time of the change. A concurrent request may have rebuilt the cache from pre-commit data in the meantime.
  - **No cache is stored when it is built inside a transaction.** A cold cache is built in a throwaway array store and is neither kept nor written, because uncommitted roles must never reach the shared cache.
- **Catalogue growth.** When a permission declared in code is missing from the cached catalogue, `ScopeResolver` reloads the cache once. It remembers a name that is still missing, so the cache is not rebuilt on every check.
- **Spatie's `php artisan permission:cache-reset` only clears the `none` key.** It does not reach tenant keys. To flush tenants, run inside each tenant's context, or clear the cache store.

**Audit (RBAC-12).** Every change is recorded in the hash-chained audit log, including permission diffs on role edits. The changes covered are:

- role create, edit, copy and archive
- permission changes
- assignments and unassignments

## Consequences

- No tenant can see or be served another tenant's roles. The database enforces it with RLS on `roles` and their links, and the cache does with per-tenant keys. The tests prove it with a warm cache.
- **System roles need a refresh job when the catalogue grows.** Seeded system roles copy their templates' permissions at provisioning time. When a deploy adds new `core.*` permissions to a template, existing tenants' system roles do not get them until a per-tenant refresh job runs. That job should be tied to `permissions:sync`. Module activation already refreshes.
- Each `can()` costs about two or three queries (assignments plus the cached role links). A per-request memo can follow profiling.
- `ScopeResolver`'s missing-name memo lives as long as a worker. It fails closed: a permission synced later is not seen until the cache key changes or the worker restarts. Scope it per request if that becomes a problem.
- A small race remains: a request reads before commit and writes the cache after the flush. A versioned cache key would close it.
- Inside write transactions, a cold cache is rebuilt per `can()`. This is a performance cost.
- New modules must name permissions `module.resource.action` and register them. Otherwise `Gate::before` ignores them, and only a policy can grant them.
