# Phase 3: workflow engine, approvals, automation, notifications

Requirements: WF-01 to WF-11, APR-01 to APR-09, AUTO-01 to AUTO-07,
NOT-01 to NOT-06, the builder in spec 6.4. Screens:
`design/screens/BoApprovals.dc.html` (inbox, "why this route", delegation)
and `design/screens/BoWorkflow.dc.html` (canvas, draft v4 while v3 is live,
test with a sample, publish).

One engine serves every module: process flows (the order of stages),
approvals on stages, and automation rules. Modules register document
types; the engine never reads a module's tables directly, it goes through
the type's registered accessor (architecture rule 1).

## Ground rules for every task

- CLAUDE.md applies in full: tenant tables with `tenant_id`, forced RLS and
  an isolation test; UUID v7; money as minor units plus currency; audit
  every create, change, approval, reassignment and publish (AUD-01);
  Form Requests and policies on every endpoint; permissions
  `core.<resource>.<action>`; every string in `api/lang/{en,fr}` and
  `web/src/locales/{en,fr}.json`.
- Lists use the Phase 2.5 list framework (`ListDefinition`, `ListView`,
  `useServerList`); pickers use the ds `Select`.
- When role templates change, run the Identity and Rbac tests too.
- External channels (email, push, SMS, WhatsApp) sit behind adapters with
  a fake driver for tests and local use. Email uses Laravel Mail (log
  driver locally). Real push/SMS/WhatsApp drivers wait for owner
  credentials.
- Public holidays come from country-pack data (CP-01). Ship the table and
  loader; dates the team cannot confirm are flagged, never invented.
- New dependency: `@xyflow/react` (React Flow, named in spec 6.4) for the
  builder canvas.

## Gaps flagged to the owner

- "Requester's line manager" (APR-02) needs a reporting line, which HR
  owns. The approver resolver registry lets HR add it; until then the
  type is offered only when a module registers it.
- Prepaid message credits for SMS/WhatsApp (NOT-01) belong to billing
  (Phase 6). Phase 3 ships the channel adapters and delivery tracking.
- Approve by email (APR-08, Should) ships with signed single-use links.

## Owner decisions

- 2026-10-08, NOT-03: a tenant's notification text is one text per event
  type and channel, written once in the organisation's language and sent as
  is to every recipient (no per-language versions). Built-in defaults
  (`api/lang/{en,fr}/notifications.php`) stay translated and go out in the
  recipient's language when the tenant has not customised that event and
  channel.

## Tasks

| # | Task | Risk | After |
|---|------|------|-------|
| 1 | Notifications core: service, channels, templates, preferences, digest, delivery tracking (NOT-01..06) | risky (tenancy, outbound) | – |
| 2 | Workflow core: document type registry, versioned flow definitions, runtime (stages, conditions, branching, parallel, return/cancel, history, stage permissions, time limits), default flows (WF-01..11, APR-09) | risky | – |
| 3 | Approvals: steps, approver resolvers, actions, no self-approval, delegation, reassignment, escalation with business hours and holidays, approve by email (APR-01..09) | risky | 2 (+1 for sending) |
| 4 | Automation rules: triggers, conditions, actions, test mode, run log, retries, loop protection, templates (AUTO-01..07) | risky (webhooks, loops) | 1, 2 |
| 5 | First core document type: party credit limit change through a flow, applied on approval | risky (money, RBAC) | 3 |
| 6 | Web: bell, notifications inbox, preferences, template editor | normal | 1 |
| 7 | Web: approvals inbox, bulk approve, detail with history and route, delegation, reassignment | risky (permissions UI) | 3 |
| 8 | Web: workflow builder (React Flow canvas, palette, properties, validation, draft/publish/roll back, test with a sample, copy between companies, read-only on phone) | normal | 2 |
| 9 | Web: automation rules list and editor, test mode, run log | normal | 4 |
| 10 | Web: workflow status on documents, stage volume and bottleneck view (WF-10) | normal | 5 |
| 11 | Whole-phase review, Playwright walkthrough, phase report | – | all |

Tasks 1 and 2 run in parallel; then 3, 6 and 8; then 4, 5 and 7; then 9
and 10.
