# Phase 8: developer platform, super-admin, hardening

Roadmap exit criteria: a public API with keys and OpenAPI; webhooks; a marketplace; a super-admin console; SSO; observability; a staging deploy on Linode.

Requirements: API-01..05, HOOK-01, MKT-01..04, ADM-01..07, RBAC-13, AUTH-11, NFR-01..11.

## Ground rules

- **CLAUDE.md in full.** This includes tenancy, RLS and isolation tests for every new table, policies and Form Requests, en/fr, styling with tokens only, and auditing.
- **Approved dependencies (owner, 2026-10-16).** Add nothing else without asking.
  - `laravel/passport` for OAuth 2.0 (marketplace apps).
  - `laravel/socialite` with the Microsoft and Google providers, and `onelogin/php-saml` for SSO.
  - `dedoc/scramble` to generate the OpenAPI document.
  - Sentry SDKs for Laravel, React and React Native. They are disabled until the owner provides DSNs.
- **The public API is the same API.**
  - `/api/v1` routes are reached with API keys or OAuth tokens. The same policies, scope and field rules apply as for users.
  - A key acts as a named service principal: it holds a tenant role assignment and is limited further by scopes.
  - Nothing bypasses RLS or permissions.
- **Platform staff never see tenant data without consent** (RBAC-13).
  - The tenant grants time-limited access.
  - Every action in that window is logged in both audit chains and is visible to the tenant.
- **Secrets.** Keys, client secrets and webhook signing secrets are shown once. Only their hashes are stored, or encrypted values where a secret must be reused (webhook signing).
- **Process.**
  - Each task gets its own branch and a security review (all of Phase 8 is security-relevant).
  - Each task with UI gets a Playwright check, with the agent seeding its own dev user.
  - Merges are grouped per stage, and CI is checked at stage checkpoints, not after every push.
  - Agents use their own test databases and uniquely named scratch scripts.

## Stages

| Stage | Tasks | Requirements |
|---|---|---|
| 8A | Public API: keys and service principals, scopes, rate limits, OpenAPI via Scramble, developer portal (en/fr), sandbox tenant | API-01..05 |
| 8A | Webhooks: subscriptions to domain events, signed payloads, retries with back-off, delivery log, replay | HOOK-01 |
| 8A | Super-admin console: tenant management, plans and deals, support access with consent, country pack management, revenue and usage reports, staff roles | ADM-01..04, ADM-06, ADM-07, RBAC-13 |
| 8B | Marketplace: developer accounts, app submission and review, OAuth install flow (Passport) with permission consent, uninstall, paid apps through billing (80/20 split), first-party connectors | MKT-01..04, ADM-05, API-02 (OAuth) |
| 8B | Enterprise SSO: Microsoft Entra ID and Google Workspace by OIDC and SAML, per-tenant configuration, just-in-time linking to invited users, enforced SSO option | AUTH-11 |
| 8C | Hardening: Sentry and structured logs, metrics and alerts; health and status page; performance budgets; dependency and secret scanning in CI; backups and restore runbook; data-subject requests and consent records (KE DPA); accessibility pass; browser and device support | NFR-01..11 |
| 8C | Staging deploy on Linode, using the existing deploy workflow plus provisioning docs; runs once the owner provides credentials | NFR-01, NFR-07 |
| 8D | Whole-phase review, Playwright walkthrough, report, roadmap tick | all |

## Notes

- **Webhooks.**
  - Events come from the existing domain events, mapped to a public catalogue with versioned payloads.
  - Payloads carry ids and the minimum fields, and respect the subscriber key's scopes. No hidden fields leak.
  - Signed with HMAC-SHA256 and a timestamp, to protect against replay.
  - Delivered on a dedicated queue, with retries and a dead-letter state.
- **Sandbox (API-05).** A self-serve developer sandbox tenant with fake payment and fiscal drivers and seeded sample data. It is reset on request, isolated like any tenant, and never billed.
- **Marketplace billing (MKT-03).**
  - Billed through the Phase 6 subscription engine, which adds app line items.
  - The developer's 80% is recorded as a payable ledger in the operator tenant. Payouts are manual until a provider is chosen.
- **First-party connectors (MKT-04)** are packaged as marketplace apps that use the public API and webhooks:
  - couriers (a generic interface);
  - SMS and WhatsApp (the existing notifier drivers);
  - Power BI and Excel (OData-style read endpoints or scheduled exports);
  - biometric devices (the existing HR device API).

  Real provider credentials remain owner inputs.
- **Hardening is mostly verification and tooling.** Each NFR gets an automated check where feasible, or a documented runbook where not, and the report states which is which.
