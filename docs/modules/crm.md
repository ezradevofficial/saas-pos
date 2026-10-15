# CRM and sales module spec (phase 7, stage 7D)

Source: concept note section 5 (CRM and sales row: leads, opportunities,
quotes, customer 360 view, follow-ups, sales targets; integrates with POS
and Loyalty) and 8.4 (sold per company); platform core spec (MD-01, MD-03,
RBAC-04 to RBAC-08, WF-01 to WF-11, APR-01 to APR-07, CF-01 to CF-06,
NUM-01, TPL-01, TPL-04, NOT-01 to NOT-04, AUD-01, IMP-01 to IMP-03,
EXP-01, LAY-01, LAY-04, CUR-04); ADR 003 (money); the module specs of POS,
Loyalty and Accounting (`docs/modules/pos.md`, `loyalty.md`,
`accounting.md`). When this file and the core spec disagree, the core spec
wins.

## Scope

CRM is a module (`api/modules/CRM`, permissions `crm.*`, catalogue key
`crm` from phase 6), sold per company and switched on per tenant
(RBAC-08). It works with only the core installed: leads, opportunities,
quotes, follow-ups and targets are managed in the back office from core
master data (parties, items, units, price lists, tax codes, currencies and
rates, users, companies and branches). It reads no other module's tables.
When other modules are active it reaches them through events and small
read contracts that those modules own:

- **Accounting**: an accepted quote becomes a draft customer invoice
  (`QuoteConverted`, handled in Accounting); the customer 360 view shows
  the AR balance and invoices (`Accounting\Contracts\CustomerReceivables`);
  invoice targets read `Accounting\Contracts\InvoiceTotals`.
- **POS**: the 360 view shows the customer's sales
  (`POS\Contracts\CustomerSales`); POS targets read
  `POS\Contracts\SalesTotals`.
- **Loyalty**: the 360 view shows points and credit
  (`Loyalty\Contracts\CustomerBalances`).
- **E-commerce and POS orders** (later): a quote may be converted to an
  order by the same event once those targets exist.

Six parts:

1. **Leads**: captured by hand, by a public web form, by import and by
   the API; source and channel; assignment by rules or round-robin;
   conversion to a customer party and an opportunity.
2. **Opportunities**: pipelines and stages as configuration, value in
   minor units with its currency, expected close date, probability, won
   and lost reasons.
3. **Quotes**: lines from core items and price lists, core tax codes,
   discounts within RBAC-06 limits, approval through the workflow engine
   above a threshold, PDF from the `sales.quote` template, email and
   WhatsApp share, acceptance and conversion.
4. **Customer 360 view**: a tab on the customer page.
5. **Follow-ups**: activities (call, meeting, email, task) with reminders
   and a calendar; emails sent through the core mailer and logged.
6. **Sales targets** per user, team or branch per period, with actuals.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| CRM-01 | **Settings per company.** The quote approval threshold (minor units of the company's base currency; none: no quote needs approval), quote validity in days (30 by default), the default team leads are dealt to when no rule matches (none: left unassigned), and whether a lost reason is required (yes by default). Audited. | Must |
| CRM-02 | **Pipelines and stages as configuration.** A company has one or more pipelines (one default); a pipeline has ordered open stages, each with a name and a default probability (0 to 100). Won and lost are outcomes, not stages. Stages are renamed, reordered, added and archived (an archived stage keeps its opportunities and takes no new ones); a stage holding open opportunities cannot be archived (`stage_in_use`). A company's first use seeds a default pipeline (Qualification 10, Needs analysis 25, Proposal 50, Negotiation 75), named in the tenant's default language and stored once. | Must |
| CRM-03 | **Options.** Lead sources, won reasons and lost reasons are per-company lists (name, archived), seeded with defaults in the tenant's default language. | Must |
| CRM-04 | **Teams.** A team belongs to a company (and optionally one branch), has a leader and members (users). A user may be in several teams. Teams scope visibility (CRM-08), deal leads round-robin (CRM-11) and carry targets (CRM-29). | Must |
| CRM-05 | **Leads.** A person and optional organisation, email and phone, source, channel (`manual`, `form`, `import`, `api`), estimated value (minor units and currency, optional), notes, owner and team, status (`new`, `contacted`, `qualified`, `converted`, `disqualified`), custom fields (CF-01). At a branch of a company. Never deleted: archived (TEN-06). Audited (AUD-01). | Must |
| CRM-06 | **Lead capture by web form.** A lead form (company, branch, source, optional team, the fields it shows and requires) has a public link with a random 40-character token (stored as its sha256). `POST /api/v1/crm/forms/{token}` creates a `form` lead, rate-limited per IP and per form, answers only `{received: true}`, and refuses silently when a hidden honeypot field is filled. The tenant of a token is found by a security-definer function (ADR 002). A rotated token stops the old one. A form of a tenant without the module, or archived, answers 404. | Must |
| CRM-07 | **Lead capture by import and API.** The `crm_leads` importer (IMP-01..IMP-03) with company, branch, name, organisation, email, phone, source (by name), owner (by email), estimated value and currency, notes and custom fields; undo archives what it created. `POST crm/leads` is the API (people's tokens; channel `api` unless the client says `manual`). | Must |
| CRM-08 | **Record scope (RBAC-04, RBAC-07).** Leads, opportunities, quotes and activities live at a branch and have an owner and an optional team. A user holding the resource's view permission at the branch (or above) sees the records they own; holding `crm.scope.team` there, also those of their teams (as member or leader); holding `crm.scope.branch` there, every record at the branch, unassigned ones included. Out of scope is not found (404), in lists, search, exports, the 360 view, the calendar, targets and dashboards. Changes need the resource's permission and the same visibility. | Must |
| CRM-09 | **Field rules (RBAC-05).** `crm_lead` and `crm_opportunity` field rules hide or lock fields per role (e.g. `estimated_value_minor`, `value_minor`), applied to resources, lists, exports and writes (GuardsFieldRules). | Should |
| CRM-10 | **Owner assignment.** A new lead belongs to its creator unless an owner is given; giving or changing an owner needs `crm.lead.assign` at the branch. An owner must be an active user who may view leads at the branch (`owner_not_eligible`). | Must |
| CRM-11 | **Assignment rules and round-robin.** Leads that arrive without an owner (form, import, API) are dealt by the company's rules, in order: a rule matches on branch, source and channel (any when empty) and gives the lead to a user or to a team; a team deals round-robin among its eligible members (active, may view leads at the branch), the turn kept on the team under a row lock so two leads at once never go to the same person by mistake. No rule matched: the settings' default team; else unassigned. The outcome is audited on the lead. | Must |
| CRM-12 | **Lead conversion.** A qualified (or new, contacted) lead converts to a customer: an existing customer party the user reaches (MD-01; one without the customer role is refused, `party_not_customer`), or a new one created through the core party API's own rules (`core.party.create`, sharing mode, duplicate checks). Optionally an opportunity is created with the lead's owner, team, branch and estimated value in the chosen pipeline (its first stage by default). The lead becomes `converted` with links to both, once (`lead_converted`); `LeadConverted` is raised. | Must |
| CRM-13 | **Lead kanban and list.** Leads by status (new, contacted, qualified) as columns; moving a card changes the status. A list with search, filters (status, owner, branch, source), sort, saved views (LAY-04) and export (EXP-01). | Must |
| CRM-14 | **Opportunities.** A name, customer (core party with the customer role), pipeline and stage, value in minor units and an ISO 4217 currency (CUR-01, an active one), expected close date, probability (the stage's by default, overridable 0 to 100), owner, team, branch, source lead, notes, custom fields (CF-01). Status `open`, `won`, `lost`. Archived, never deleted. Audited. | Must |
| CRM-15 | **Stage moves and closing.** Moving to another open stage of the same pipeline sets the stage's probability unless one is given. Winning records the won reason (optional), the close date and the rate and base value at that date (CUR-04; `rate_unavailable` refuses); losing needs a lost reason when the settings say so (`reason_required`) and sets probability to 0. A closed opportunity can be reopened (`crm.opportunity.close`). `OpportunityWon` and `OpportunityLost` are raised. | Must |
| CRM-16 | **Opportunity kanban and pipeline value.** Columns per stage of a pipeline with count, value and weighted value (value × probability) per currency; dragging a card moves its stage. A list with search, filters (status, owner, pipeline, branch, party), sort, saved views and export. | Must |
| CRM-17 | **Quotes.** A quote for a customer, optionally from an opportunity, at a branch: currency (the party's, else the company's base), price list (the party's in that currency, else the company's default in that currency), tax inclusive per the price list, quote date, valid until (date + validity days), terms, notes, lines, owner, team. Numbered at submission with `crm.quote` (`QT-{BRANCH}-{YYYY}-{00001}`, yearly, NUM-01). Audited; cancelled, never deleted. | Must |
| CRM-18 | **Quote lines and maths (ADR 003).** A line names an item and unit (or a description), a quantity (decimal string, up to 6 places), a unit price and a discount percent (0 to 100, up to 2 places). For an item the price comes from the quote's price list (PriceResolver: quantity breaks, unit factors); a typed price other than the list price needs `crm.quote.price` (`price_override_not_allowed`); a line without a list price needs a typed price (`price_required`). gross = round_half_up(qty × unit price); discount = round_half_up(gross × percent ÷ 100); amount = gross − discount; tax on the amount from the core tax calculator, inclusive or exclusive (MD-03). Totals: subtotal = Σ gross, discount = Σ discount, tax = Σ tax, total = Σ line totals. All in minor units of the quote currency; never floats. | Must |
| CRM-19 | **Tax from core, never invented.** An item line's tax code is the company's code for the item's tax category (ItemTaxStatus); a description line takes a tax code of the company (required). An item whose category has no code (`tax_category_missing`, `tax_code_missing`) is refused on the line. A code without a confirmed rate on the quote date is kept in a draft without tax and shown as "Rate needed"; submitting refuses it (`tax_rate_missing`, naming the code). | Must |
| CRM-20 | **Discount limits (RBAC-06).** A line discount needs `crm.quote.discount` and must be within the user's `max_discount_percent` at the quote's branch (`discount_over_limit`, naming the limit). No limit rule means no discount; the Owner has no limit. Checked on every save by the person saving. | Must |
| CRM-21 | **Quote approval (WF-01, APR-01..APR-07).** Submitting freezes the base amounts at the company's rate on the quote date (CUR-04), numbers the quote and starts the `crm.quote` flow. The default flow (WF-02) asks a Branch Manager at the branch (escalated to the Admins after two business days) when the base total is above the company's threshold (`needs_approval`), else approves at once. Tenants may route on the totals, the largest discount, the customer, the branch and custom fields. The creator never approves their own quote (APR-07). Approved → `approved`; rejected → `rejected` (editable again as a new draft by copying). `QuoteApproved` is raised. | Must |
| CRM-22 | **Quote output (TPL-01, TPL-04).** The PDF prints from the `sales.quote` template (CRM is its live data source): lines with discount and tax rate, totals, valid until, terms. Approved quotes can be emailed with the PDF (core DocumentEmails: queued, rate-limited, audited) and shared by a public link for WhatsApp (core DocumentShares: 7 days, revocable, opens audited). Sending marks the quote `sent` and logs an email activity. | Must |
| CRM-23 | **Acceptance.** A sent (or approved) quote is accepted or declined (with a reason) as the customer answered; an expired one (valid until passed) cannot be accepted (`quote_expired`). Accepting can win its opportunity in the same step. `QuoteAccepted` is raised. | Must |
| CRM-24 | **Conversion (integration).** An accepted quote converts once to `invoice` when Accounting is active (`QuoteConverted`; Accounting's listener writes a draft customer invoice with the quote's lines in the same transaction, for a user holding `accounting.invoice.create` at the company; Accounting off: `target_inactive`). `pos_order` and `ecommerce_order` are reserved targets answered `target_unavailable` until those modules build them. The quote keeps what it became (`converted_to`, `converted_ref`). | Must |
| CRM-25 | **Customer 360 view.** A tab on the customer page (the web party-tab registry) and `GET crm/customers/{party}/overview`: the customer's CRM activities (recent and upcoming), open and closed opportunities, quotes and converted leads, within the user's CRM scope (CRM-08); with POS active and `pos.sale.view`, sales count, totals per currency and recent sales; with Loyalty active and its view permissions, points and credit balances; with Accounting active and `accounting.invoice.view`, open receivables per currency and recent invoices. Each part is read only through the owning module's read contract and is absent (null) otherwise. | Must |
| CRM-26 | **Activities.** Call, meeting, email or task: subject, notes, start (due) and optional end, optional reminder time, owner (assignee), links to a lead, opportunity, quote and/or customer, status `open`, `done` (with outcome), `cancelled`. At a branch; scope as CRM-08. Audited. | Must |
| CRM-27 | **Reminders (NOT-01, NOT-02).** `crm:remind` (every minute) sends `crm.activity.reminder` to the owner through the core notification service when the reminder time arrives, once per activity. Overdue open activities show on the calendar and the 360 view. | Must |
| CRM-28 | **Calendar.** Activities in a date range (month and week views), filtered by owner within scope. | Must |
| CRM-29 | **Email logging (NOT-02).** `POST crm/emails` sends a plain email to a customer or lead address through the core mailer (the tenant's verified sender when set, BR-06; queued, rate-limited) and logs it as a done `email` activity with the recipient and subject; an email sent elsewhere is logged as an email activity by hand. | Must |
| CRM-30 | **Sales targets.** A target is for a user, a team or a branch, for a period (start and end dates), a metric and an amount in the company's base currency: `won_value` (won opportunities' base value by close date: the owner; a team's members; the branch), `pos_sales` (POS sales less refunds, base currency, by cashier or branch, through `POS\Contracts\SalesTotals`, only with POS active) and `invoiced` (posted customer invoices less credit notes, base currency, by creator or branch, through `Accounting\Contracts\InvoiceTotals`, only with Accounting active). Actuals and percent achieved are computed when read, in the company's time zone. Managed with `crm.target.manage`; a user sees their own, their teams' (`crm.scope.team`) and their branch's (`crm.scope.branch`). | Must |
| CRM-31 | **Dashboard widgets (LAY-01).** `crm.pipeline_value` (KPI and chart: open value and weighted value by stage, in one currency) and `crm.targets` (list: the reader's current targets with actual and percent), within scope. | Must |
| CRM-32 | **Lists and exports (EXP-01, LAY-04).** Leads, opportunities, quotes and activities lists with search, filters, sort, saved views and CSV, Excel and PDF export within scope. | Must |
| CRM-33 | **Custom fields (CF-01, CF-03).** Leads and opportunities carry custom fields (entities `crm_lead`, `crm_opportunity`, offered only while CRM is active), in forms, lists, imports, workflow conditions and the quote's opportunity. | Must |
| CRM-34 | **Events.** `LeadConverted`, `OpportunityWon`, `OpportunityLost`, `QuoteApproved`, `QuoteAccepted` (after commit) and `QuoteConverted` (in the converting transaction, so the target's document and the quote commit or roll back together). | Must |
| CRM-35 | **Isolation and audit.** Every CRM table has `tenant_id` and row-level security and is covered by the isolation suite; every create, change, assignment, conversion, approval, closing, discount and send is audited (AUD-01). | Must |

## Decisions

1. **Scope permissions, not a sales-rep template.** RBAC-07's "own records"
   is the default: a view permission shows a user what they own;
   `crm.scope.team` and `crm.scope.branch` widen it. RBAC-03's template
   list has no sales rep; tenants make one (copy a template, keep
   `crm.lead.*`, `crm.opportunity.*`, `crm.quote.view|create|send`,
   `crm.activity.*`). Open question 1 asks whether to ship one.
2. **Won and lost are outcomes**, not stages, so every pipeline closes the
   same way and targets read one status.
3. **Discounts over the limit are refused**, not routed for approval: the
   RBAC-06 limit is per person, the approval threshold is per quote total.
   The flow may also route on `max_discount_percent` (configuration).
4. **Quote to invoice is a draft in Accounting.** The accountant posts it
   (numbering, journal, fiscal submission stay in Accounting, ACC-09,
   ACC-19). Lines map one to one; a discounted line is written as quantity
   1 at its discounted amount with the quantity, price and discount in its
   description, because Accounting's lines have no discount column: the
   amount and its tax are then exactly the quote's. Tax is computed again
   at the invoice's issue date (a rate change in between is the law's).
   The revenue account is the company's posting rule `crm.quote_invoice`
   (field `revenue`), seeded from the country chart (KE 4100, CD 701);
   without it the conversion is refused (`setting_missing`).
5. **`sales.quote` belongs to CRM.** The core template type's module
   becomes `crm` (offered only while CRM is active; `live` data).
6. **Read contracts, no shared tables.** The 360 view and targets call
   small classes in POS, Loyalty and Accounting that check their own
   module and permissions and answer null when the reader may not see
   them.
7. **Targets are computed when read**, not stored as facts: the actuals
   are always the ledgers' current truth (a void or credit note shows at
   once), at the cost of a query per target.
8. **Lead forms answer the same to everything** (`{received: true}`, or
   404 for an unknown token), so a form never confirms what exists.

## Data model

Every table has `tenant_id` (RLS), UUID v7 keys, `created_at` and
`updated_at`. Money is `bigint` minor units with a currency; rates
`numeric(18,8)` in an FX snapshot.

| Table | Columns |
| --- | --- |
| `crm_settings` | company (unique), quote approval threshold `quote_approval_minor`, quote validity days, default team, lost reason required |
| `crm_pipelines` | company, name, is default, archived |
| `crm_stages` | pipeline, name, position, probability, archived |
| `crm_options` | company, kind (`source`, `won_reason`, `lost_reason`), name, position, archived |
| `crm_teams` | company, branch (nullable), name, leader, round-robin turn, archived |
| `crm_team_members` | team, user (unique pair) |
| `crm_assignment_rules` | company, name, position, branch, source ids, channels, assignee user or team, active, archived |
| `crm_lead_forms` | company, branch, name, source, team, fields (`{shown, required}`), token hash (unique), token rotated at, submissions, last submitted at, archived |
| `crm_leads` | company, branch, owner, team, status, name, organisation, title, email, phone, source, channel, form, estimated value minor and currency, notes, disqualify reason, party, opportunity, converted at and by, `custom`, archived, created by |
| `crm_opportunities` | company, branch, owner, team, party, lead, pipeline, stage, name, value minor, currency, expected close on, probability, status, reason, close note, closed on, closed by, `fx`, base currency, base value minor, notes, `custom`, archived, created by |
| `crm_quotes` | number, company, branch, owner, team, party, opportunity, status, currency, price list, tax inclusive, quote date, valid until, terms, notes, subtotal, discount, tax and total minor, base currency, `fx`, base subtotal, discount, tax and total minor, max discount percent, needs approval, submitted, decided (by, at), sent at, accepted or declined (at, by, reason), converted (to, ref, at, by), cancelled (at, by, reason), requested by |
| `crm_quote_lines` | quote, line no, item, description, unit, qty, list price minor, unit price minor, discount percent, gross, discount, net, tax, total minor, tax code, tax rate |
| `crm_activities` | company, branch, owner, kind, subject, notes, starts at, ends at, remind at, reminded at, status, done at, outcome, lead, opportunity, quote, party, `email` (`{to, subject, sent}`), created by |
| `crm_targets` | company, branch, subject type (`user`, `team`, `branch`), subject id, metric, period start and end, currency, amount minor, archived, created by |

## Events

| Event | When | Payload |
| --- | --- | --- |
| `LeadConverted` | after commit | tenant, lead, company, branch, party, opportunity, user |
| `OpportunityWon` / `OpportunityLost` | after commit | tenant, opportunity, company, branch, party, owner, currency, value minor, base value minor (won), closed on |
| `QuoteApproved` | after commit | tenant, quote, company, branch, number |
| `QuoteAccepted` | after commit | tenant, quote, company, branch, party, currency, total minor |
| `QuoteConverted` | in the converting transaction | tenant, quote id and number, target, company, branch, party, currency, tax inclusive, quote date, user, lines `[{line_no, item_id, description, uom_id, qty, unit_price_minor, discount_percent, gross_minor, discount_minor, amount_minor, tax_code_id, tax_minor, total_minor}]`, totals; a handler sets `result` (`{type, id, label}`) |

Consumed: `WorkflowCompleted`, `WorkflowCancelled` (the quote's flow).

## Read contracts in other modules

| Contract | Module | Answers (null when the module is off or the reader lacks the permission) |
| --- | --- | --- |
| `Modules\POS\Contracts\CustomerSales::forCustomer(User, partyId, limit)` | POS | sales the reader sees with `pos.sale.view`: count, totals per currency, last sale, recent sales |
| `Modules\POS\Contracts\SalesTotals::net(companyId, from, to, cashierIds?, branchId?)` | POS | base-currency total of completed, not voided sales less applied refunds, in the period (company time zone) |
| `Modules\Loyalty\Contracts\CustomerBalances::forCustomer(User, partyId)` | Loyalty | points and credit accounts the reader sees (`loyalty.points.view`, `loyalty.credit.view`) |
| `Modules\Accounting\Contracts\CustomerReceivables::forCustomer(User, partyId, limit)` | Accounting | with `accounting.invoice.view`: open amounts per currency, overdue amounts, recent customer invoices |
| `Modules\Accounting\Contracts\InvoiceTotals::posted(companyId, from, to, createdBy?, branchId?)` | Accounting | base total of posted customer invoices less posted credit notes in the period |

## Permissions

| Permission | What it allows |
| --- | --- |
| `crm.lead.view`, `.create`, `.edit`, `.assign`, `.convert`, `.archive` | Read; capture; change (status, details); give to another owner or team; convert; disqualify, archive and restore |
| `crm.opportunity.view`, `.create`, `.edit`, `.close`, `.archive` | Read; create; change and move stages; win, lose and reopen; archive and restore |
| `crm.quote.view`, `.create`, `.discount`, `.price`, `.approve`, `.send`, `.convert`, `.cancel` | Read; draft, edit, submit, accept and decline; give line discounts (within `max_discount_percent`); change a list price; act on the quote flow (WF-08); PDF, email and share; convert; cancel |
| `crm.activity.view`, `.manage` | Read; create, change, complete and cancel activities, send and log emails |
| `crm.target.view`, `.manage` | Read targets; set them |
| `crm.settings.manage` | Settings, pipelines, stages, options, teams, assignment rules, lead forms |
| `crm.scope.team`, `crm.scope.branch` | See the records of one's teams; see every record at the branch (CRM-08) |

Role templates (RBAC-03): **Owner** everything; **Admin** `crm.*.view`,
`crm.settings.manage`, `crm.scope.branch`; **Branch Manager** `crm.*`
except `crm.settings.manage` (the default quote approver); **Accountant**
`crm.quote.view`, `crm.quote.convert`, `crm.scope.branch`; **Read-only
Auditor** `crm.*.view` and `crm.scope.branch`.

## API

Prefix `/api/v1`, people's tokens, every route behind `module:crm`
(403 `module_inactive`), except the public lead form. Lists take
`?search=`, `?status=` (or `all`), `?company=`, `?branch=`, `?owner=`,
`?party=`, `?from=`, `?to=`, `?sort=`, `?per_page=`, `?format=csv|xlsx|pdf`
as each documents; amounts are digit strings in minor units.

| Route | Permission | Requirement |
| --- | --- | --- |
| `GET/PUT crm/companies/{company}/settings` | any CRM view / `settings.manage` | CRM-01 |
| `GET/POST crm/pipelines`, `PATCH crm/pipelines/{id}`, `POST …/archive\|restore`, `POST crm/pipelines/{id}/stages`, `PATCH crm/stages/{id}`, `POST crm/stages/{id}/archive` | view: any CRM view; else `settings.manage` | CRM-02 |
| `GET/POST crm/options`, `PATCH crm/options/{id}`, `POST …/archive\|restore` | same | CRM-03 |
| `GET/POST crm/teams`, `PATCH crm/teams/{id}`, `POST …/archive\|restore` | same | CRM-04 |
| `GET/POST crm/assignment-rules`, `PATCH …/{id}`, `POST …/{id}/archive` | `settings.manage` | CRM-11 |
| `GET/POST crm/lead-forms`, `PATCH …/{id}`, `POST …/{id}/rotate\|archive` | `settings.manage` | CRM-06 |
| `POST crm/forms/{token}` | public | CRM-06 |
| `GET/POST crm/leads`, `GET/PATCH crm/leads/{id}`, `POST …/{id}/assign\|convert\|disqualify\|archive\|restore` | `lead.*` | CRM-05..CRM-13 |
| `GET/POST crm/opportunities`, `GET/PATCH …/{id}`, `POST …/{id}/win\|lose\|reopen\|archive\|restore`, `GET crm/pipeline-summary?pipeline=` | `opportunity.*` | CRM-14..CRM-16 |
| `POST crm/quotes/preview`, `GET/POST crm/quotes`, `GET/PATCH …/{id}`, `POST …/{id}/submit\|accept\|decline\|convert\|cancel\|send\|share`, `GET …/{id}/pdf`, `GET …/{id}/shares`, `POST …/{id}/shares/{share}/revoke` | `quote.*` | CRM-17..CRM-24 |
| `GET crm/customers/{party}/overview` | any CRM view | CRM-25 |
| `GET/POST crm/activities`, `PATCH …/{id}`, `POST …/{id}/complete\|cancel\|reopen`, `POST crm/emails` | `activity.*` | CRM-26..CRM-29 |
| `GET/POST crm/targets`, `PATCH …/{id}`, `POST …/{id}/archive` | `target.*` | CRM-30 |

## Screens (web)

Navigation group **Sales** (only while CRM is active): Leads (list and
kanban, form, detail with convert), Opportunities (kanban by stage of a
pipeline, list, detail with activities, quotes, win and lose), Quotes
(list, editor with live totals, detail with approval status, PDF, email,
WhatsApp share, accept, decline, convert), Calendar (month and week),
Targets, and Sales settings (settings, pipelines and stages, options,
teams, assignment rules, lead forms with the embed snippet). The customer
page gets a **Sales** tab (customer 360). Dashboard widgets: pipeline
value and my targets. Tokens only; en and fr.

## Integrations

- **Accounting**: `QuoteConverted` (target `invoice`) → a draft customer
  invoice (decision 4); `CustomerReceivables` and `InvoiceTotals`; the
  posting rule type `crm.quote_invoice` in the country charts.
- **POS**: `CustomerSales`, `SalesTotals`.
- **Loyalty**: `CustomerBalances`.
- **Core**: workflow (`crm.quote`), numbering (`crm.quote`), templates
  (`sales.quote`), shares and document emails, notifications
  (`crm.activity.reminder`), custom field entities, the `crm_leads`
  importer, list views, dashboard sources, the party-tab registry.

## Acceptance criteria

1. A rep sees only their own leads, opportunities, quotes and activities;
   another rep's is not found; a team leader with `crm.scope.team` sees the
   team's; a branch manager sees the branch's; nobody sees another
   branch's without a role there.
2. A quote's lines and totals are exact in minor units (KES with 2
   decimals, CDF with none), inclusive and exclusive, with discounts; an
   item whose tax rate is "Rate needed" saves as a draft and refuses to
   submit (`tax_rate_missing`).
3. A discount above the user's `max_discount_percent` is refused; without
   a rule no discount is allowed; the Owner is not limited.
4. A quote above the threshold waits for a Branch Manager; below it is
   approved at once; the creator cannot approve their own.
5. Converting an accepted quote with Accounting on writes one draft
   customer invoice whose total equals the quote's; with Accounting off it
   is refused and nothing changes.
6. Lead conversion creates or links a customer and an opportunity once;
   round-robin deals leads in turn among eligible team members.
7. Targets show actuals from won opportunities, POS sales and invoices.
8. The 360 view shows each module's part only while that module is
   active and the reader holds its permission.
9. Every CRM table has row-level security and isolation tests.

## Open questions

1. Ship a **Sales Rep** role template (RBAC-03 lists none)? Built without
   one (decision 1).
2. Should a discount over the limit be **routed to approval** instead of
   refused (decision 3)?
3. Should invoice actuals for a **user** follow the quote's owner instead
   of the invoice's creator? Accounting keeps no link back to the quote.

## Status (stage 7D, branch `feat/crm`)

Built: CRM-01 to CRM-08, CRM-10 to CRM-35 (API, web, tests). CRM-09
(field rules, Should) is wired for `crm_lead` and `crm_opportunity` on
resources, lists and writes (GuardsFieldRules) but has no test of its own.

### Decisions taken while building

9. **A converted lead's new customer follows the customers' sharing
   mode** (TEN-08): shared by default, the lead's company when customers
   are kept per company.
10. **Leads typed in by a person are theirs**; leads arriving by form,
    import or the API without an owner go through the rules (CRM-11).
11. **Owners of activities other than oneself** need `crm.scope.team` or
    `crm.scope.branch` at the branch (a lead or manager hands out work).
12. **A rejected quote edited becomes a draft again** and keeps its
    number when submitted again.
13. **Sending or sharing an approved quote marks it `sent`**; sending is
    logged as a done email activity.
14. **The public lead form's token hash is never exported** (EXP-02,
    `ExportColumns::DENIED`).

## Deferred

- Conversion to POS orders and e-commerce orders (their modules).
- Two-way email sync (IMAP/Gmail), call recording, SMS and WhatsApp
  conversations (only the quote's share link and logged emails now).
- Lead scoring, duplicate merge of leads, campaigns and marketing lists.
- Commission calculation from targets.
