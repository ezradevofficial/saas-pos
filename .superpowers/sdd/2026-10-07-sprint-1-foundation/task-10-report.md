# Task 10 report: Users, invitations, roles API, owner safety, access review

**Status:** DONE
**Branch:** `feat/s1-t10-users-roles`
**Commit:** `4056ef1 feat(core): users, invitations, roles and access review with owner safety (AUTH-05, AUTH-13, RBAC-10, RBAC-12)`

## TDD
- RED: the 5 new test files plus 2 new DeviceApiTest cases failed (41 failures, 1 error: routes missing, `OwnerGuard::protect` undefined, resume under an archived location returned 200).
- GREEN: `composer migrate:fresh && php artisan test tests/Feature/Core tests/Feature/TranslationParityTest.php`: **290 passed, 2053 assertions** (the baseline before this task was 244). Pint passes on every changed file.

## What was built
- **Migration** `2026_10_08_000600_create_invitations_table.php`:
  - `invitations` table as the brief specifies (email is citext, `token_hash` is unique, composite FK `invited_by` → users of the same tenant, RLS on).
  - `auth_tenant_for_invitation(text)` SECURITY DEFINER function, with execute granted to the runtime role.
  - The roles name index is now partial (`where archived_at is null`), so a name only needs to be unique among a tenant's active roles.
- **Identity**:
  - `Invitation` model.
  - `InvitationNotification`: mail, or an SMS link for phone invitations. The link is `config('app.frontend_url')/invitations/{token}`; `app.frontend_url` was added to config.
  - `Invitations` service: invite, revoke, open, accept.
  - `UserPolicy`; `User::assignments()` and `User::hasVerifiedContact()`.
  - Controllers: `UserController`, `InvitationController`, `AcceptInvitationController`.
  - Requests and resources: `UserAdminResource` and `InvitationResource`.
- **Rbac**:
  - `Grants`: checks the scope and role exist, the actor reaches and covers the scope, the actor holds every permission of the role, and only an owner grants an owner role.
  - `RoleManager`: create, update, copy, archive, assign and unassign.
  - `ScopeNames`: batches scope names and filters assignment queries to what the actor can see.
  - `RolePolicy`.
  - Controllers: `RoleController`, `AssignmentController`, `PermissionCatalogueController`, `AccessReviewController`.
  - Requests and resources: Role, Assignment, AccessReview.
  - `Role::setPermissions()`, `Role::permissionNames()`, and an override of `findByParam` that ignores archived roles.
  - `RoleAssignment::creator()`.
- **Routes**: every route in the brief. The new authenticated routes sit in the full-access group, so the route-audit test still passes. The public invitation routes are throttled by `auth-ip`, and the token must match `[A-Za-z0-9]{40}`.
- **Translations** (en and fr): `rbac.errors.cannot_grant`, `rbac.errors.already_assigned`, `rbac.catalogue.*`, `auth.invitation.*`, `auth.users.contact_unverified`, `auth.notifications.invitation.*`.

## Carried items
1. **RBAC-12.** `Role::setPermissions` records `rbac.role.permissions_update` with sorted name lists before and after, and only when the list changes. It is used when a role is created, edited or copied (`RoleTemplates::copy`), and also by `RoleTemplates::refresh`. `provision` still syncs without this entry; role creation is already audited.
2. **Assignment scope checks.** `Grants::scope` returns 404 for an unknown id or the wrong level, and 422 `parent_archived` for an archived scope or role. If the actor does not reach the scope for `core.role.assign`, the answer is 404. If the actor reaches it but does not cover it, or lacks the role's permissions, the answer is 403 `cannot_grant`.
3. **OwnerGuard locking.** The guard takes `pg_advisory_xact_lock(hashtext('owners:'||tenant_id))` before it counts owners. Outside a transaction it throws a LogicException. `protect($target, $change)` runs the check and the change in one transaction. There are also `assertAssignmentRemovable` and `assertRoleArchivable`.
   - Test: two `protect()` calls in one transaction. The second fails with `last_owner`, and the test checks the advisory lock is held (via `pg_locks`).
4. **Invitation token.** Accepting an invitation issues the token through `Authenticate::issueToken`.
5. **Two-factor downgrade.** Tokens are downgraded in two cases: when an edit turns `requires_two_factor` from false to true (every holder without two-factor), and when such a role is assigned through the API. Both have tests.
6. **Device resume.** `DevicePairing::resume` calls `lockActive(Location)` and returns 422 `parent_archived` if the location is archived. `Archiver::hasActiveChildren` now counts suspended devices. New tests cover resume to pending, archiving a location with a suspended device, and resume under an archived location.

## Decisions beyond the rulings (please review)
- **Permissions of inactive modules** are ignored by the "holds every permission" check. They grant nothing to anyone, and without this nobody could grant a template containing, say, `pos.*` before POS is activated.
- **Escalation on role create/edit/copy.** Permissions added to a role must be held by the actor at tenant scope, or the request fails with 403 `cannot_grant`. Otherwise a role editor could copy their own role, add permissions and so escalate.
- **Managing an Owner needs an Owner.** Only an Owner can deactivate, reactivate or sign out everywhere an Owner (403), or remove an owner-role assignment (403 `cannot_grant`). This mirrors the rule for granting.
- **Assignment endpoints**: listing, creating or deleting needs the target user to be visible to the actor (`core.user.view`), else 404. Listings show only the assignments whose scope the actor sees, plus tenant-scope assignments only for tenant-wide holders.
- **Sign-out-everywhere** needs `core.user.edit` (a branch manager can sign out their staff). Deactivate and reactivate need `core.user.deactivate`.
- **Invitation responses**: 410 with `invitation_expired`, `invitation_revoked` or `invitation_accepted`. An unknown token is 404. A login already taken at accept time is 422 `unique` on email or phone.
- **Phone numbers** in local form are read in the request's optional `country`, else in the country of the tenant's first company.
- **Access review**:
  - Rows with archived roles are left out (they grant nothing); deactivated users stay in, and their status is shown.
  - CSV cells that start with `= + - @ \t \r` are prefixed with `'` to block formula injection. E.164 phone numbers are exempt.
  - The export is audited before streaming, with the row count. The streamed rows are read inside the tenant's own context.
- **Invitation list**: holders of `core.user.invite` see invitations that have at least one assignment in their scope (a jsonb `@>` check). Tenant-wide holders see all of them.

## Concerns
- There is no true two-connection concurrency test for `OwnerGuard`. The lock is proven by `pg_locks` and the sequential double removal.
- `PATCH roles/{id}` with `permissions` replaces the whole set, so permissions of inactive modules are dropped if the client leaves them out.

---

## Fix round 1

**Changes**
- **IMPORTANT 1.** Added `OwnerGuard::holdsOwnerRole(User)`, which finds an unarchived owner role at tenant scope whatever the user's status. `UserPolicy::mayManageOwner` now uses it for the target, so a non-owner cannot reactivate (or otherwise manage) a deactivated Owner.
- **IMPORTANT 2.** `Grants::assertHolds` no longer exempts inactive modules. It reads the role-permission links of the actor's roles covering the scope. This applies to `assertCanGrant` and to role create, edit and copy. `RoleManager::update` merges the role's existing inactive-module permissions into the submitted set, so a PATCH does not drop them; only permissions actually added are checked.
- **IMPORTANT 3.** `UserPolicy`: `view` still needs the actor to cover at least one of the target's assignment scopes. `update`, `deactivate`, `reactivate` and `signOutEverywhere` now need the actor to cover every one; tenant-wide holders cover everything.
- **MINOR 1.** Covered by the PATCH merge above.
- **MINOR 2.** `assertRoleArchivable` stays as defence in depth; a comment now says so.
- **MINOR 3.** Added `Grants::assertCanInvite`, which also requires `core.user.invite` to cover each assignment's scope (403 `cannot_grant`). It is used at invite time and again at accept time (a failure there gives `invitation_stale`).
- **MINOR 4.** Revoking an invitation needs `core.user.invite` to cover every one of its assignment scopes; otherwise 403.
- **MINOR 5.** `UserPolicy::update` also applies `mayManageOwner`.

**New tests** (all 6 failed against the round-0 code, checked by stashing the app changes):
- UserAdminTest:
  - `test_only_an_owner_reactivates_or_edits_an_owner_even_a_deactivated_one`
  - `test_changing_a_user_takes_covering_every_one_of_their_assignments`
- RoleApiTest:
  - `test_permissions_of_an_inactive_module_cannot_be_handed_out_by_someone_without_them` (covers both create and assign)
  - `test_editing_permissions_keeps_those_of_inactive_modules`
- InvitationTest:
  - `test_inviting_takes_the_invite_permission_at_each_assignment_scope`
  - `test_revoking_takes_covering_every_assignment_of_the_invitation`

**Command:** `cd api && composer migrate:fresh && php artisan test tests/Feature/Core tests/Feature/TranslationParityTest.php`, then `./vendor/bin/pint --test app/Core tests/Feature/Core`.

**Raw output tail**
```
{"tool":"phpunit","result":"passed","tests":296,"passed":296,"assertions":2083,"duration_ms":42274}
{"tool":"pint","result":"passed"}
```
