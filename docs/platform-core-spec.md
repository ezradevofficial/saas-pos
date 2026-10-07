# Platform Core: Requirements Specification

2026-10-07 · @ezra kathurima

The platform core is the always-on layer every module depends on; this spec defines what it must do so the build team can design, build and test it before any business module starts. It expands sections 4, 6 and 8 of the concept note.

## 1. Purpose, scope and conventions

**In scope:** everything shared by all modules: tenancy, identity, roles and permissions, master data, multi-currency, the process and workflow engine, custom fields and forms, branding and layouts, notifications, audit, import/export, onboarding, subscriptions and billing, country packs, the developer platform and the super-admin console.

**Out of scope:** business logic of individual modules (POS, inventory, accounting, HR, payroll and so on). Each module gets its own spec and uses the services defined here.

**Conventions**

- Each requirement has an ID such as `TEN-01` (area code + number) so stories, tests and code can reference it.
- "Must" = required at launch. "Should" = expected at launch, can slip to the first update if time runs out. "Later" = after launch.
- "Tenant" = one customer account. "Admin" = a tenant user with the Owner or Admin role. "Platform staff" = our own team using the super-admin console.
- All user-facing text exists in English and French.

**Personas**

| Persona                 | Who they are                                 | What they need from the core                                |
|-------------------------|----------------------------------------------|-------------------------------------------------------------|
| Owner                   | Business owner who signs up and pays         | Fast setup, control over who sees what, clear billing       |
| Admin                   | Office or IT person configuring the system   | Roles, workflows, fields, branding, imports without code    |
| Branch manager          | Runs one outlet or branch                    | Approve requests, see only their branch, manage their staff |
| Staff user              | Cashier, storekeeper, accountant, HR officer | Simple screens, only the actions their job needs            |
| Approver                | Anyone who approves requests                 | One inbox, approve from phone, delegate when away           |
| Employee (self-service) | Any staff member                             | Leave, payslips, requests from a phone                      |
| Developer / partner     | Customer IT or third-party developer         | API keys, webhooks, sandbox, documentation                  |
| Platform staff          | Our support, sales and finance teams         | Manage tenants, plans, trials, support access, revenue      |

## 2. Tenancy and organisation structure

One tenant holds a hierarchy of **Group → Companies → Branches → Locations (outlets, warehouses, stores) → Devices**, and no tenant can ever see another tenant's data.

| ID     | Requirement                                      | Acceptance criteria                                                                                                                                                                                | Priority |
|--------|--------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| TEN-01 | Every record belongs to exactly one tenant       | Tenant ID on every tenant table; PostgreSQL row-level security enforces isolation; automated tests prove a user of tenant A gets nothing from tenant B through UI, API, exports, search or reports | Must     |
| TEN-02 | A tenant has one group and one or more companies | Admin can add, rename and archive companies; each company has its own legal name, tax ID (KRA PIN / DRC NIF), country pack, base currency, fiscal year and address                                 | Must     |
| TEN-03 | Companies can be in different countries          | A Kenyan and a DRC company can coexist in one tenant, each applying its own country pack                                                                                                           | Must     |
| TEN-04 | Companies have branches; branches have locations | Unlimited depth is not needed: fixed levels Company → Branch → Location; each location has a type (outlet, warehouse, store, office)                                                               | Must     |
| TEN-05 | Devices are registered to a location             | POS devices pair to one location with a one-time code; admin can see, rename, suspend and unpair devices                                                                                           | Must     |
| TEN-06 | Archive instead of delete                        | Companies, branches and locations with transactions can only be archived; archived items are hidden from new transactions but kept in reports                                                      | Must     |
| TEN-07 | Group-level consolidated view                    | Users with group scope see dashboards and reports across companies, converted to a chosen reporting currency                                                                                       | Should   |
| TEN-08 | Shared or separate master data per company       | Admin chooses per data type (items, customers, suppliers, employees) whether it is shared across the group or kept per company                                                                     | Should   |
| TEN-09 | Inter-company transactions                       | Transfers and sales between companies of one tenant create matching documents in both companies                                                                                                    | Later    |

**Business rules**

- A tenant must always have at least one company, one branch and one location.
- Deleting a tenant (account closure) keeps data read-only for 90 days, then permanently deletes it after a final export is offered. The retention period is to be confirmed with counsel.

## 3. Identity, authentication and sessions

People sign in once with email or phone, can belong to several companies of one tenant, and POS staff use a fast PIN on paired devices.

| ID      | Requirement                             | Acceptance criteria                                                                                                                                       | Priority |
|---------|-----------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| AUTH-01 | Sign-up and sign-in with email or phone | Password sign-in; phone numbers verified by SMS OTP, emails by link or code                                                                               | Must     |
| AUTH-02 | Password policy                         | Minimum 8 characters, checked against common-password lists; admin can raise the minimum; hashed with a modern algorithm (bcrypt/argon2)                  | Must     |
| AUTH-03 | Two-factor authentication               | TOTP authenticator app or SMS OTP; admin can make 2FA mandatory per role (e.g. Owner, Accountant, Payroll)                                                | Must     |
| AUTH-04 | Password reset                          | Self-service by email or SMS; links and codes expire in 30 minutes and are single-use                                                                     | Must     |
| AUTH-05 | Invite users                            | Admin invites by email or phone and assigns roles and scope; the invite expires after 7 days                                                              | Must     |
| AUTH-06 | POS PIN login                           | Staff sign in on a paired POS device with a 4–6 digit PIN or staff card; works offline using a securely stored hash; locked after 5 wrong attempts        | Must     |
| AUTH-07 | Fast user switching on POS              | Cashiers switch users without closing the shift; every sale records who made it                                                                           | Must     |
| AUTH-08 | Manager override                        | A manager authorises a restricted action (void, refund, price change, big discount) by entering their PIN on the cashier's device; both users recorded    | Must     |
| AUTH-09 | Session management                      | Back office sessions time out after inactivity (admin sets 15–480 minutes); users see and end their active sessions; admin can force sign-out of any user | Must     |
| AUTH-10 | Login security                          | Rate limiting, lockout after repeated failures, alerts on sign-in from a new device or country                                                            | Must     |
| AUTH-11 | Single sign-on for enterprises          | Sign in with Microsoft Entra ID or Google Workspace (OIDC/SAML)                                                                                           | Must     |
| AUTH-12 | Employee self-service accounts          | Employees without system roles get self-service access only (payslips, leave, requests), created from HR records                                          | Must     |
| AUTH-13 | Deactivate, not delete                  | Leavers are deactivated; their history stays attributed to them; deactivated users do not count toward plan limits                                        | Must     |

## 4. Roles, permissions and data scope (RBAC)

Access = **what** a user may do (role permissions) × **where** (scope: group, company, branch, location) × **which data** (field and record rules).

| ID      | Requirement                    | Acceptance criteria                                                                                                                                                                                                  | Priority |
|---------|--------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| RBAC-01 | Permission catalogue           | Each module registers its permissions as module.resource.action (e.g. `pos.sale.void`, `procurement.po.approve`); actions include view, create, edit, delete, approve, export, print, void, discount, override_price | Must     |
| RBAC-02 | Roles                          | A role is a named set of permissions; admins create, copy, edit and archive roles; system role templates cannot be edited, only copied                                                                               | Must     |
| RBAC-03 | Role templates                 | Ship templates: Owner, Admin, Branch Manager, Cashier, Waiter, Storekeeper, Accountant, HR Officer, Payroll Officer, Procurement Officer, Approver, Employee self-service, Read-only Auditor                         | Must     |
| RBAC-04 | Scoped assignments             | A user can hold different roles in different scopes, e.g. Manager at Branch A and Cashier at Branch B; data outside a user's scopes is invisible everywhere (lists, search, reports, API, exports)                   | Must     |
| RBAC-05 | Field-level rules              | Admin hides or makes read-only specific fields per role (e.g. cost price for cashiers, salary for non-payroll staff)                                                                                                 | Must     |
| RBAC-06 | Limit rules                    | Numeric limits per role: max discount %, max refund amount, max approval amount, credit limit override                                                                                                               | Must     |
| RBAC-07 | Record ownership rules         | Option per document type: users see only records they created or are assigned to (e.g. sales reps see their own customers)                                                                                           | Should   |
| RBAC-08 | Only subscribed modules        | Permissions and menus of unsubscribed modules are hidden and rejected by the API                                                                                                                                     | Must     |
| RBAC-09 | One check everywhere           | The same permission check runs in API middleware/policies and drives what the UI shows; the UI never is the only guard                                                                                               | Must     |
| RBAC-10 | Owner safety                   | At least one active Owner must exist; an Owner cannot remove their own last Owner role                                                                                                                               | Must     |
| RBAC-11 | Segregation of duties warnings | Warn when one role can both create and approve the same document type, or create suppliers and pay them                                                                                                              | Should   |
| RBAC-12 | Access review                  | Report of who has which roles and scopes, exportable; changes to roles and assignments are audited                                                                                                                   | Must     |
| RBAC-13 | Support access with consent    | Platform staff can view a tenant only after the tenant grants time-limited access; every action during access is logged and visible to the tenant                                                                    | Must     |

## 5. Master data and multi-currency

### 5.1 Shared master data

The core owns the records every module uses, so a customer or item exists once no matter how many modules are active.

| ID    | Requirement                         | Acceptance criteria                                                                                                                                                                   | Priority |
|-------|-------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| MD-01 | Parties                             | One party record with roles (customer, supplier, employee link, contact); fields for name, tax ID, phones, emails, addresses, currency, payment terms, credit limit, price list, tags | Must     |
| MD-02 | Items catalogue (core part)         | Item code, name (EN/FR), category, type (stock, service, non-stock, kit), units of measure, barcodes, tax category, images; modules add their own fields (e.g. inventory costing)     | Must     |
| MD-03 | Taxes                               | Tax codes per country pack (VAT rates, exemptions, zero-rated, withholding, excise), tax-inclusive or exclusive pricing per price list                                                | Must     |
| MD-04 | Payment methods                     | Configurable list linked to payment providers and accounts: cash per currency, each mobile money wallet, card, credit, voucher, points                                                | Must     |
| MD-05 | Departments, cost centres, projects | Shared dimensions any module can tag transactions with                                                                                                                                | Must     |
| MD-06 | Duplicate detection                 | Warn on likely duplicates (same phone, tax ID, barcode or similar name); allow merging with audit trail                                                                               | Should   |
| MD-07 | Change history                      | Every master data change is versioned and visible on the record                                                                                                                       | Must     |

### 5.2 Multi-currency

| ID     | Requirement                       | Acceptance criteria                                                                                                                                                                                                                                                                                                                      | Priority |
|--------|-----------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| CUR-01 | Currencies                        | All ISO 4217 currencies available; tenant activates the ones it uses; each has decimals and cash rounding (e.g. CDF to nearest 50 or 100, set by admin)                                                                                                                                                                                  | Must     |
| CUR-02 | Base and reporting currencies     | Each company has one base currency and up to 3 reporting currencies; base currency is locked after the first posted transaction                                                                                                                                                                                                          | Must     |
| CUR-03 | Exchange rates                    | Official reference rates loaded daily from a feed (Central Bank of Kenya for KES, Banque Centrale du Congo for CDF); each company sets its own shop rate, which defaults to the reference rate and can be overridden by authorised roles; buy, sell and mid rates; effective date and time; full history; only authorised roles can edit | Must     |
| CUR-04 | Rate on every transaction         | Each document stores currency, rate used and base-currency amounts; later rate changes never alter posted documents                                                                                                                                                                                                                      | Must     |
| CUR-05 | Dual-price display                | Prices shown in two currencies at the same time (e.g. USD and CDF) on POS, receipts and invoices                                                                                                                                                                                                                                         | Must     |
| CUR-06 | Mixed-currency payment and change | One sale paid in several currencies and methods; change returned in a chosen currency using the shop rate and rounding rules                                                                                                                                                                                                             | Must     |
| CUR-07 | Rate tolerance and alerts         | Warn when a manual rate differs from the previous day's by more than a set %                                                                                                                                                                                                                                                             | Should   |
| CUR-08 | Revaluation support               | Core supplies rates and conversion services; accounting uses them for realised/unrealised FX gains and losses                                                                                                                                                                                                                            | Must     |
| CUR-09 | Offline rates                     | POS devices cache the latest rates and record which rate each offline sale used                                                                                                                                                                                                                                                          | Must     |

## 6. Process and workflow engine

One engine runs process flows (order of stages), approvals and automation rules for every document type in every module, all configured by admins in a visual builder.

### 6.1 Process flows

| ID    | Requirement                             | Acceptance criteria                                                                                                                                           | Priority |
|-------|-----------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| WF-01 | Document types register with the engine | Each module registers its document types (e.g. purchase requisition, PO, leave request, stock transfer) with their fields, default stages and allowed actions | Must     |
| WF-02 | Default flows                           | Each document type ships a default flow per country pack; admin copies and edits it; the original can always be restored                                      | Must     |
| WF-03 | Edit stages                             | Add, remove, rename, reorder stages; mark mandatory or optional; add custom stages                                                                            | Must     |
| WF-04 | Entry and exit conditions               | Conditions on any field (incl. custom fields) that must be true to enter or leave a stage, e.g. three-way match before payment; blocked moves show the reason | Must     |
| WF-05 | Branching                               | Route to different stages by conditions (amount, currency, branch, department, category, customer group, custom field) with AND/OR                            | Must     |
| WF-06 | Parallel stages                         | Split into parallel stages and join when all (or any) are complete                                                                                            | Must     |
| WF-07 | Auto-create next document               | A stage can create the next document with mapped fields (e.g. approved requisition → draft PO)                                                                | Must     |
| WF-08 | Stage permissions                       | Which roles may move documents into or out of each stage                                                                                                      | Must     |
| WF-09 | Stage time limits                       | Due time per stage, with reminders and escalation as in 6.2                                                                                                   | Must     |
| WF-10 | Status and history                      | Each document shows its current stage, holder, time in stage and full history; dashboards show volumes and bottlenecks per stage                              | Must     |
| WF-11 | Return and cancel                       | Send back to an earlier stage with a reason; cancel with a reason; rules for what happens to auto-created documents                                           | Must     |

### 6.2 Approvals, escalation and delegation

| ID     | Requirement                 | Acceptance criteria                                                                                                                                                                           | Priority |
|--------|-----------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| APR-01 | Approval steps on any stage | Single approver, sequential chain or parallel group (any one / all / majority)                                                                                                                | Must     |
| APR-02 | Approver types              | Named user, role (within the document's scope), requester's line manager, department head, manager N levels up, cost-centre owner, custom field value                                         | Must     |
| APR-03 | Actions                     | Approve, reject, return for changes, comment, attach files, request more information                                                                                                          | Must     |
| APR-04 | Approvals inbox             | One inbox across modules on web and mobile; filters; bulk approve where allowed; shows document summary and history                                                                           | Must     |
| APR-05 | Escalation                  | After a set time: remind, then escalate to the next level or named user; option to auto-approve or auto-reject at the final timeout; business hours and public holidays per country respected | Must     |
| APR-06 | Delegation                  | Users delegate all or specific approval types for a date range; admins reassign pending items; the log shows "approved by X on behalf of Y"                                                   | Must     |
| APR-07 | No self-approval            | A user can never approve their own request; the step moves to the next eligible approver                                                                                                      | Must     |
| APR-08 | Approve by email            | Approve or reject from an email notification via a secure single-use link, with sign-in when 2FA is required                                                                                  | Should   |
| APR-09 | Versioning                  | Flow and approval definitions are versioned; documents in progress finish on the version they started with                                                                                    | Must     |

### 6.3 Automation rules

| ID      | Requirement     | Acceptance criteria                                                                                                                                                              | Priority |
|---------|-----------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| AUTO-01 | Triggers        | Record created/updated/deleted, stage entered or left, field changed, date-based (X days before/after a date field), threshold crossed, schedule (cron-like, set in plain terms) | Must     |
| AUTO-02 | Conditions      | Any field incl. custom fields, AND/OR groups, comparison with other fields or fixed values                                                                                       | Must     |
| AUTO-03 | Actions         | Create document, update field, change stage, assign user, send notification (in-app, email, SMS, WhatsApp when configured), set credit hold, call webhook                        | Must     |
| AUTO-04 | Test mode       | Run a rule against sample or real records without making changes; show what would happen                                                                                         | Must     |
| AUTO-05 | Run log         | Every run logged with trigger, outcome and errors; failed runs retried with alerts to admin                                                                                      | Must     |
| AUTO-06 | Loop protection | A rule cannot trigger itself in a loop; limits on runs per minute per tenant                                                                                                     | Must     |
| AUTO-07 | Rule templates  | Library of ready-made rules (reorder alert, overdue credit hold, contract expiry, birthday greeting)                                                                             | Should   |

### 6.4 Builder (UI)
- Visual canvas (React Flow or similar) with drag-and-drop stages, conditions, approvals and actions; zoom, mini-map, undo/redo.
- Validation before publishing: unreachable stages, missing approvers, conflicting conditions.
- Draft, publish and roll back; copy flows between companies.
- Works on tablet and desktop; read-only view on phone.

## 7. Custom fields, forms, numbering and document templates

Admins extend any record, build new forms and control how documents are numbered and printed, all without code.

| ID     | Requirement                           | Acceptance criteria                                                                                                                                                                                 | Priority |
|--------|---------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| CF-01  | Custom fields on any record           | Types: text, long text, number, currency amount, date, date-time, yes/no, dropdown, multi-select, file, lookup (to items, parties, employees, other records), formula (read-only, calculated)       | Must     |
| CF-02  | Field settings                        | Label (EN/FR), help text, default, required, unique, min/max, pattern, visible/editable by role, shown on POS or not                                                                                | Must     |
| CF-03  | Custom fields work everywhere         | Usable in list filters, reports, workflow conditions, automation, templates, API and imports/exports                                                                                                | Must     |
| CF-04  | Custom forms (custom document types)  | Admin builds new forms (e.g. "Vehicle request", "Petty cash request") with fields, attachments and a process flow; they get an inbox, list view and numbering like built-in documents               | Must     |
| CF-05  | Line items on custom forms            | A form can have a repeating table of lines (e.g. items requested) with totals                                                                                                                       | Should   |
| CF-06  | Storage                               | Custom field values stored as JSONB with indexes on fields used for filtering; schema changes never need a deploy                                                                                   | Must     |
| NUM-01 | Document numbering                    | Number formats per document type and company or branch, e.g. `PO-{BRANCH}-{YYYY}-{00001}`; reset yearly or never; no gaps where tax rules require it                                                | Must     |
| NUM-02 | Offline number ranges                 | POS devices receive pre-allocated number ranges so offline receipts never clash                                                                                                                     | Must     |
| TPL-01 | Template designer                     | Drag-and-drop templates for receipts (58/80 mm thermal), A4/A5 invoices, quotes, POs, delivery notes, payslips, statements and letters; fields, logo, tables, QR codes, barcodes, signatures, terms | Must     |
| TPL-02 | Bilingual templates                   | Each template can print in English, French or both side by side                                                                                                                                     | Must     |
| TPL-03 | Locked fiscal elements                | eTIMS / DGI data, tax lines and QR codes required by the country pack are locked and cannot be removed                                                                                              | Must     |
| TPL-04 | Output formats                        | Print (thermal and A4), PDF download, email and WhatsApp share                                                                                                                                      | Must     |
| TPL-05 | Template per branch or customer group | Different templates per branch, customer group or document condition                                                                                                                                | Should   |

## 8. Branding, themes and layout designers

Each tenant can make the platform look like its own system; availability per plan follows the concept note (basic branding in all plans, Brand Pack add-on for Starter, designers in Business and Enterprise).

| ID     | Requirement            | Acceptance criteria                                                                                                                                             | Priority |
|--------|------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| BR-01  | Theme tokens           | All web and mobile UI reads colours, fonts, spacing, radius and shadows from tokens; no hard-coded styling; a theme change applies everywhere without a release | Must     |
| BR-02  | Theme editor           | Logo (light and dark), favicon, primary/secondary/accent colours, font choice from a curated list, corner style, light/dark mode; live preview; presets         | Must     |
| BR-03  | Contrast check         | Theme cannot be published if text/background contrast fails WCAG AA                                                                                             | Must     |
| BR-04  | Branded login page     | Logo, background image, welcome text, on the tenant's subdomain or custom domain                                                                                | Must     |
| BR-05  | Custom domain          | Tenant adds a domain (e.g. `erp.company.co.ke`), verifies it by DNS record, and gets an automatic SSL certificate                                               | Must     |
| BR-06  | Branded communications | Emails sent from the tenant's verified domain (SPF/DKIM guidance) and SMS sender ID where the provider allows                                                   | Should   |
| BR-07  | Hide platform branding | "Powered by" removed for Brand Pack, Business and Enterprise                                                                                                    | Must     |
| BR-08  | Theme scope            | Theme per tenant, overridable per company or branch                                                                                                             | Should   |
| LAY-01 | Dashboard designer     | Drag, resize and configure widgets (KPI, chart, list, shortcut, approval count); dashboards per role, with user personal copies                                 | Must     |
| LAY-02 | Navigation editor      | Reorder, rename, group and hide menu items per role; set each role's home page                                                                                  | Must     |
| LAY-03 | Form layout designer   | Arrange fields into sections, tabs and columns; hide fields per role; set labels and help text                                                                  | Must     |
| LAY-04 | List view designer     | Choose columns, default filters and sorting; saved views shared with roles or kept personal                                                                     | Must     |
| LAY-05 | POS layout designer    | Product grid size and order, category tiles (colour, image), quick buttons, keypad position, customer display content; per location                             | Must     |
| LAY-06 | Versioning             | Layouts and themes saved as versioned JSON configuration: draft, preview, publish, roll back, copy between companies/branches                                   | Must     |
| LAY-07 | Upgrade safety         | New fields added by platform updates appear hidden or in a default position; customer layouts never break                                                       | Must     |
| LAY-08 | White-label mobile app | Separately branded app published under the customer's name in the app stores (Enterprise, quoted)                                                               | Later    |

## 9. Notifications, audit log, import and export

### 9.1 Notifications

| ID     | Requirement              | Acceptance criteria                                                                                                                                                                                         | Priority |
|--------|--------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| NOT-01 | Channels                 | In-app (bell + inbox), email, mobile push; SMS and WhatsApp through providers we select per country, paid with prepaid message credits customers buy in-app (cost plus a 20–30% margin); low-balance alerts | Must     |
| NOT-02 | One notification service | All modules send through the core service using templates and events; no module sends email or SMS directly                                                                                                 | Must     |
| NOT-03 | Editable templates       | Admin edits notification text per event and language with placeholders (e.g. {document_number}, {amount})                                                                                                   | Must     |
| NOT-04 | User preferences         | Users choose channels per notification type; admins can make some mandatory (e.g. approvals)                                                                                                                | Must     |
| NOT-05 | Digest                   | Option for a daily or weekly summary instead of individual messages                                                                                                                                         | Should   |
| NOT-06 | Delivery tracking        | Status per message (sent, delivered, failed) with retries                                                                                                                                                   | Must     |

### 9.2 Audit log

| ID     | Requirement       | Acceptance criteria                                                                                                                                                    | Priority |
|--------|-------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| AUD-01 | What is logged    | Every create, update, delete/archive, approval, stage change, sign-in, permission change, export, print of fiscal documents, void, refund, discount and price override | Must     |
| AUD-02 | Detail            | Who, when (server time + device time for offline), where (IP, device, location), what changed (before/after values), on whose behalf (delegation or support access)    | Must     |
| AUD-03 | Tamper resistance | Append-only storage; entries cannot be edited or deleted by any tenant user; hash chaining to detect changes                                                           | Must     |
| AUD-04 | Search and export | Filter by user, date, record, action, module; export to Excel/CSV for auditors                                                                                         | Must     |
| AUD-05 | Retention         | Kept at least as long as tax law requires in each country (confirm: Kenya and DRC record-keeping periods)                                                              | Must     |

### 9.3 Import and export

| ID     | Requirement      | Acceptance criteria                                                                                                                              | Priority |
|--------|------------------|--------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| IMP-01 | Excel/CSV import | Import items, parties, employees, opening stock, opening balances, prices; downloadable templates in EN/FR                                       | Must     |
| IMP-02 | Import wizard    | Column mapping, validation preview with row-level errors, dry run, then import; undo of the whole batch within 24 hours if nothing depends on it | Must     |
| IMP-03 | Large imports    | Run in the background with progress and a result file; at least 50,000 rows per file                                                             | Must     |
| EXP-01 | Export any list  | Export current view to Excel, CSV or PDF, respecting permissions and field rules                                                                 | Must     |
| EXP-02 | Full data export | Owner can request a complete export of their tenant data (machine-readable) at any time                                                          | Must     |

## 10. Self-onboarding, subscriptions, billing and usage limits

### 10.1 Self-onboarding

| ID     | Requirement                   | Acceptance criteria                                                                                                                                                                                                    | Priority |
|--------|-------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| ONB-01 | Sign-up                       | Email or phone + OTP; country and language chosen first; tenant and subdomain created in under 1 minute                                                                                                                | Must     |
| ONB-02 | Business type presets         | Choosing a business type (retail, supermarket, restaurant, bar, hotel, pharmacy, wholesale, van sales, salon, fuel station, non-POS company) pre-selects POS mode, role templates, default flows and suggested modules | Must     |
| ONB-03 | Module picker with live price | Customer selects modules and quantities (outlets, devices, employees); price updates live in KES or USD; bundles suggested when cheaper                                                                                | Must     |
| ONB-04 | Free trial                    | 14-day trial of chosen modules (platform staff can extend for larger setups), no payment details needed; trial status and days left always visible                                                                     | Must     |
| ONB-05 | Setup wizard                  | Steps: company details and tax ID, currencies and rates, branches and locations, taxes, payment methods, first items (manual or import), users and roles, devices; each step can be skipped and resumed                | Must     |
| ONB-06 | Sample data                   | Option to load sample data to explore, removable in one click                                                                                                                                                          | Should   |
| ONB-07 | Getting-started checklist     | In-app checklist and short videos until the first sale or first payroll is done                                                                                                                                        | Must     |
| ONB-08 | Assisted onboarding           | Field sales can create a tenant for a customer and hand over ownership                                                                                                                                                 | Must     |

### 10.2 Subscriptions and billing

| ID     | Requirement          | Acceptance criteria                                                                                                                                                                                     | Priority |
|--------|----------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| SUB-01 | Plan catalogue       | Platform staff define modules, bundles, units (per outlet, device, employee, company), prices per country and currency, add-ons and coupons without code                                                | Must     |
| SUB-02 | Self-service changes | Owner adds/removes modules, outlets, devices, employees any time; charges pro-rated; downgrades take effect at period end                                                                               | Must     |
| SUB-03 | Billing cycles       | Monthly or annual (2 months free); invoices generated automatically and compliant with eTIMS (Kenya) and DGI (DRC)                                                                                      | Must     |
| SUB-04 | Payment              | M-Pesa, Airtel, Orange, Afrimoney and card; saved payment method for auto-renewal where the provider allows; bank transfer for Enterprise                                                               | Must     |
| SUB-05 | Dunning              | Reminders 7 and 3 days before and on the due date; grace period (set by platform staff, default 7 days); then read-only mode; POS keeps selling for a further short window (default 3 days), then stops | Must     |
| SUB-06 | Reactivation         | Paying the outstanding invoice restores full access immediately                                                                                                                                         | Must     |
| SUB-07 | Module switch-off    | Unsubscribing a module hides it but keeps its data for 12 months; re-subscribing restores it                                                                                                            | Must     |

### 10.3 Usage limits (soft limits with a 7-day window)

| ID     | Requirement            | Acceptance criteria                                                                                                          | Priority |
|--------|------------------------|------------------------------------------------------------------------------------------------------------------------------|----------|
| LIM-01 | Metering               | Count active outlets, devices, users and employees per tenant daily                                                          | Must     |
| LIM-02 | Over-limit warning     | When exceeded: warn Owner in-app and by email, show extra cost, one-click upgrade                                            | Must     |
| LIM-03 | 7-day window           | Daily reminders for 7 days; after day 7 without upgrade, block adding more of the exceeded item; existing items keep working | Must     |
| LIM-04 | Never block operations | Sales, payroll runs and approvals are never blocked by usage limits                                                          | Must     |
| LIM-05 | Billing of overage     | Extra usage charged at the add-on rate on the next invoice                                                                   | Must     |

## 11. Country packs, localisation and developer platform

### 11.1 Country packs and localisation

| ID      | Requirement            | Acceptance criteria                                                                                                                                                                                                     | Priority |
|---------|------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| CP-01   | Country pack structure | A versioned bundle per country (Kenya, DRC) holding: tax codes and rates, e-invoicing adapter, payroll statutory tables, chart-of-accounts template, document templates, public holidays, default flows, number formats | Must     |
| CP-02   | Effective-dated rules  | Rates and bands carry start and end dates; legal changes are loaded as data by platform staff, without a code release                                                                                                   | Must     |
| CP-03   | Pack updates           | Platform staff publish pack updates; tenants see a change summary; changes apply from their effective date                                                                                                              | Must     |
| L10N-01 | Languages              | English and French only, for UI, emails, SMS, templates, help content; user picks language; documents can print in a different language from the UI                                                                     | Must     |
| L10N-02 | No hard-coded text     | All strings in translation files; missing translations fail the build                                                                                                                                                   | Must     |
| L10N-03 | Formats                | Date, number and currency formats per locale (e.g. 1,234.50 vs 1 234,50); time zones per company and branch                                                                                                             | Must     |

### 11.2 Developer platform (at launch)

| ID      | Requirement             | Acceptance criteria                                                                                                                                         | Priority |
|---------|-------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| API-01  | Public REST API         | Versioned (`/v1`), JSON, covers every module's main resources; same permission checks as the UI                                                             | Must     |
| API-02  | Authentication          | Per-tenant API keys with scopes, and OAuth 2.0 for marketplace apps; keys can be rotated and revoked                                                        | Must     |
| API-03  | Rate limits             | Per key and per tenant, with clear headers and errors                                                                                                       | Must     |
| API-04  | Documentation           | OpenAPI spec and a developer portal with guides, examples and changelog, in English and French                                                              | Must     |
| API-05  | Sandbox                 | Free sandbox tenant for developers with test payment and tax endpoints                                                                                      | Must     |
| HOOK-01 | Webhooks                | Subscribe to events (sale completed, stock low, invoice approved, payroll approved, any stage change); signed payloads; retries with back-off; delivery log | Must     |
| MKT-01  | Marketplace listing     | Developers register, submit apps with description, permissions and pricing; platform staff review for security before listing                               | Must     |
| MKT-02  | Install and permissions | Tenant installs with one click, sees and approves requested permissions, can uninstall at any time                                                          | Must     |
| MKT-03  | Paid apps               | Billing through our subscription system with 80% of revenue to the developer and 20% kept by us                                                             | Must     |
| MKT-04  | First-party connectors  | Couriers, SMS/WhatsApp, Power BI/Excel, biometric attendance devices available at launch                                                                    | Must     |

## 12. Super-admin console and non-functional requirements

### 12.1 Super-admin console (our team)

| ID     | Requirement               | Acceptance criteria                                                                                 | Priority |
|--------|---------------------------|-----------------------------------------------------------------------------------------------------|----------|
| ADM-01 | Tenant management         | Search tenants; see plan, modules, usage, health, billing status; suspend, reactivate, extend trial | Must     |
| ADM-02 | Plans and pricing         | Manage catalogue, prices per country, coupons, custom Enterprise deals                              | Must     |
| ADM-03 | Support access            | Request consent-based, time-limited access to a tenant (RBAC-13); all actions logged                | Must     |
| ADM-04 | Country pack management   | Load and publish tax, payroll and holiday updates with effective dates                              | Must     |
| ADM-05 | Marketplace review        | Review, approve, reject and suspend marketplace apps                                                | Must     |
| ADM-06 | Revenue and usage reports | MRR, churn, trials converted, revenue by country, module and plan                                   | Must     |
| ADM-07 | Staff roles               | Our own RBAC: support, sales, finance, compliance, engineering; 2FA mandatory                       | Must     |

### 12.2 Non-functional requirements

| ID     | Area                       | Requirement                                                                                                                                                        | Priority |
|--------|----------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| NFR-01 | Availability               | 99.5% monthly uptime for web and API at launch, excluding announced maintenance; status page; a 99.9% Enterprise option is added once the infrastructure is proven | Must     |
| NFR-02 | Performance                | 95% of back-office pages load in under 2 s on a 3G-class connection; API p95 under 500 ms for standard reads                                                       | Must     |
| NFR-03 | POS speed                  | Adding an item and completing a cash sale each take under 1 s on a low-end Android device, online or offline                                                       | Must     |
| NFR-04 | Offline                    | POS works fully offline for at least 7 days; sync resumes automatically; no lost or duplicate sales (verified by automated tests)                                  | Must     |
| NFR-05 | Scale                      | Designed for 5,000 tenants and 50,000 POS devices in year one without re-architecture                                                                              | Must     |
| NFR-06 | Security                   | HTTPS everywhere (TLS 1.2+), encryption at rest, secrets in a vault, OWASP Top 10 covered, annual penetration test, dependency scanning in CI                      | Must     |
| NFR-07 | Backups and recovery       | Daily full backups plus point-in-time recovery; restore tested monthly; RPO ≤ 15 min, RTO ≤ 4 h                                                                    | Must     |
| NFR-08 | Data protection            | Kenya Data Protection Act and DRC rules; consent records; data subject requests (access, correction, deletion) handled within legal deadlines                      | Must     |
| NFR-09 | Accessibility              | WCAG 2.1 AA for back office; large touch targets on POS                                                                                                            | Should   |
| NFR-10 | Observability              | Central logs, metrics, tracing and alerts; error tracking for web and mobile apps                                                                                  | Must     |
| NFR-11 | Browser and device support | Latest 2 versions of Chrome, Edge, Firefox, Safari; Android 8+ for POS app                                                                                         | Must     |
| NFR-12 | Testing                    | Automated unit, integration and end-to-end tests; tenant isolation and permission tests run on every build                                                         | Must     |

## 13. Open questions

All open questions are decided; the requirements above reflect these decisions.

- [x] Trial length: **14 days**; platform staff can extend for larger setups.
- [x] Dunning: **7-day grace period**, then read-only; POS keeps selling **3 more days**, then stops.
- [x] Data after account closure: **90 days** read-only with export offer, then permanent deletion; audit and tax records kept for each country's legal minimum.
- [x] Exchange rates: **official feed (CBK, BCC) as reference + shop-rate override** per company.
- [x] SMS and WhatsApp: **prepaid message credits** through our providers, at cost plus a 20–30% margin.
- [x] Marketplace revenue share: **20%** kept by us, 80% to the developer.
- [x] Enterprise SSO: **at launch** (AUTH-11 now Must).
- [x] Uptime: **99.5% for all plans** at launch; 99.9% Enterprise option later.
