# ADR 010: Versioned configuration

Status: Accepted (Phase 5, task 1: LAY-06, LAY-07)

## Context

Themes, dashboards, navigation, form layouts, list views, POS layouts and document templates are all customer data. They are JSON documents rendered by the same components (CLAUDE.md rule 4). LAY-06 asks for each one to have a draft, a preview, publishing, roll back and copying between companies and branches. LAY-07 asks that platform updates never break a customer's layout.

The workflow engine already versions its flows (`FlowDefinitions`, `workflow_versions`, APR-09). Six more designers need the same thing, so it is now one generic store in `App\Core\Configuration`.

## Decision

### Tables

| Table | Holds |
| --- | --- |
| `config_documents` | `kind`, `key`, `scope_type` (tenant, company, branch, location, role, user), `scope_id` (null for the tenant), `name` (typed once, not translated) |
| `config_versions` | `document_id`, `version`, `payload jsonb`, `status` (draft, published, archived), `source` (draft, copy, rollback), `source_version_id`, created, published and discarded by and at |

- Both tables have `tenant_id`, UUID v7 keys, forced row-level security and the `tenant_isolation` policy (TEN-01).
- There is one document per tenant, kind, key and scope (a unique index).
- Partial unique indexes allow at most one draft and one published version per document.
- A trigger, `config_versions_immutable`, keeps every published or archived payload unchanged. It also stops a version from going back to draft and stops any delete.
- A discarded draft is archived with `discarded_at` set. It is never offered for roll back.
- `scope_id` points at different tables, so it has no foreign key. The API checks that it is a row of the tenant (`ValidatesConfigScope`).

### Kinds

Modules register a `ConfigKind` with `ConfigKinds`. A kind has:

- a validator: a `PayloadSchema` array or a closure returning problems;
- the scope types it allows;
- its view, edit and publish permissions (`core.config.*` unless it brings its own);
- an optional merger and default payload;
- an optional list of allowed keys;
- its module. A kind of an inactive module is not found (RBAC-08).

Drafts may have problems. Publishing and roll back refuse them with `config_invalid` and a `problems` list.

### Operations and audit

`ConfigVersions` provides these operations:

- open: create the document when needed, then save its draft;
- save the draft;
- publish;
- roll back to any earlier published version (this publishes a copy as a new version);
- copy to another company, branch or location as that place's draft;
- discard the draft;
- history.

Each operation locks the document row. Each is audited as `core.config.*` (AUD-01).

### Who may do what (`ConfigPolicy`)

| Document | See | Edit, publish |
| --- | --- | --- |
| Company, branch or location | the kind's permission at that place or above it | the same |
| Tenant | any of the kind's permissions anywhere | the permission at tenant scope |
| Role | at tenant scope | at tenant scope |
| User (a personal copy) | its user, if they hold any of the kind's permissions; anyone else at tenant scope | the same |

Owner and Admin hold `core.config.*` through their templates. Branch Manager, Accountant, HR Officer, Payroll Officer and Procurement Officer get `core.config.view`. The Read-only Auditor gets it through `*.view`.

### Resolution rule

`GET config/{kind}/resolved?key=&company=&branch=&location=` returns the configuration that applies to the signed-in user. No configuration permission is needed for this, so a cashier can read the POS layout of their till.

The place is completed from its most specific level. It must be of the tenant and covered by one of the user's role assignments (RBAC-04), otherwise the request gets 422.

The most specific published version wins along this chain, limited to the scope types the kind allows:

**user → role(s) → location → branch → company → tenant**

Drafts never apply.

Which roles are tried, and in what order:

- The roles considered are the user's active roles whose assignment covers the place. With no place, all of the user's roles count.
- Roles are tried by the assignment's scope, narrowest first: location, then branch, then company, then tenant.
- Two roles at the same level are tried oldest assignment first.

For example, "Cashier at this outlet" beats "Accountant for the company". Two roles held at the same outlet are decided by which was assigned first.

When nothing is published along the chain, the kind's default payload is returned with `source: null`.

### Upgrade safety (LAY-07)

`CatalogueMerge::entries()` and `CatalogueMerge::grouped()` (for sections and groups) merge a stored layout with the platform's current catalogue:

- An entry the layout names that no longer exists is skipped. A repeated id is skipped too.
- An entry the layout names keeps its order and settings, laid over the catalogue's defaults.
- A new catalogue entry is placed after the entry its `after` hint names, or at the end. With the kind's `hidden` rule, or the entry's own `when_new`, it is added with `hidden: true`.

Each kind's merger runs when a payload is resolved. Stored versions stay exactly as they were published.

### Why workflows keep their own tables

Flow versions are pinned by running documents (`document_workflows.version_id`, APR-09). They carry workflow-specific columns, defaults per country and an engine that reads graphs, not layouts.

Moving them would mean migrating live documents for no gain. Workflows keep `workflow_definitions` and `workflow_versions`. The configuration store copies their design (one draft, one published, the immutability trigger, discard by archiving) in a generic form.

## Consequences

- Each designer in phase 5 registers a kind and uses `useConfigDocument` with the `VersionBar` on the web. None of them adds tables.
- The isolation suite fills `{kind}` with a registered test kind. It allows `POST config/{kind}` as a tenant write under a global parameter, and sends tenant B's ids in the save and copy bodies.
- A kind with its own permissions must register them in its module's permission catalogue.
