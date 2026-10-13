# ADR 017: Self-onboarding

Status: Accepted (Phase 6, task 4: ONB-01..ONB-08, AUTH-01, TEN-01, AUD-01)

## Context

A customer must go from nothing to a trial tenant on their own, in their country and language, with sensible defaults for their kind of business, then set up at their own pace. Field sales must also create tenants for customers. Billing (subscriptions, invoices) is built in parallel and owns the trial's commercial side.

## Decision

**Sign-up (ONB-01).** `/welcome` asks for the country and language first; `/sign-up?country=&locale=` takes the account. `TenantProvisioner` (shared by self and assisted sign-up) creates the tenant, its subdomain, a company in the country's currency and time zone with its country pack, a main branch and outlet, named in the chosen language. The subdomain is the business name as a slug under the branding rules (BR-04: reserved names refused); a taken or reserved slug gets a 4-character suffix, each try in a savepoint. A test asserts the whole sign-up takes under a minute.

**One onboarding record per tenant.** `onboarding_states` (tenant table, RLS, audited as `core.onboarding.*`) is created at sign-up by a `TenantProvisioned` listener. It holds the stage (business, modules, setup, done), the business type and what it applied, the module selection, the wizard's step marks, the trial end date, the sample batch, the checklist's dismissal and the assisted handover.

**Business types are data (ONB-02).** `app/Core/Onboarding/presets.php` lists the 11 types. Each has a POS mode (stored in `tenants.settings.pos_mode` for the later POS verticals), the role templates it staffs, the document types whose default flow it adopts (published as version 1, WF-02), and the suggested modules and quantities. Names are translations. Every system role template is still seeded at sign-up (RBAC-03); the type's templates are offered first, and `RoleTemplates::ensure` seeds any it lacks.

**The trial is behind interfaces (ONB-04).** `TrialStarter::start(TrialSelection): TrialStatus` and `TrialStatusProvider::status(): TrialStatus`. The default `OnboardingTrial` activates the selected modules this installation has (others stay in the selection), switches off installed ones dropped during the trial, and records `trial_ends_at` (config `onboarding.trial_days`, 14). Billing binds its own implementations; the provider uses `bindIf`. The selection is priced through the catalogue first, so an unsellable one is a 422 and starts nothing. `GET onboarding/trial` answers every signed-in user, so the banner is always visible.

**The setup wizard reuses the app (ONB-05).** Steps embed the existing pages (currencies, organisation, taxes, payment methods) or link to them (items, invite); the company step opens the existing company dialog. A step is done when marked or when the data shows it (a tax ID, a second branch, a real item, a colleague, a paired till); skipped steps can be reopened. The import link appears only once the app has the import route.

**Sample data (ONB-06)** is created as one batch (categories, priced items, customers tagged `sample`) whose ids sit on the onboarding record; removal archives the whole batch or nothing. Core and modules register dependency checks over their own tables (`SampleDependents`). No sample sales: a sale comes from a paired till and is queued for KRA eTIMS or DRC DGI, and a sample must never reach the tax authority.

**The checklist is a registry (ONB-07).** Core registers company, tax ID, first item and paired till; POS registers the first sale, which ends the checklist. Help is text; video URLs are config data, empty for now.

**Assisted onboarding (ONB-08).** `POST platform/tenants` (staff `owner` or `support`) provisions the tenant inside `TenantContext::run`. Its first Owner is a setup account (`is_platform_staff`, an unusable password, a `.invalid` email) that Authenticate refuses, so staff never sign in to the tenant. It invites the customer as Owner; on acceptance (`InvitationAccepted`) the setup account loses its roles and is deactivated. Both steps are audited in the tenant chain and the platform chain (on the platform connection).

## Consequences

- Billing replaces two bindings and keeps the endpoints and screens.
- Trial extension by staff (ONB-04) is left to billing's subscription.
- Tenants created before this phase get an onboarding record on first use.
