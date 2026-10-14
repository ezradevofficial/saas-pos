# Accounting module spec (phase 7, stages 7A and 7B: the core ledger and its integrations)

Source: concept note sections 5 (Accounting row), 6.4 and 7.2, and 8.4
(Accounting Essentials and Pro); platform core spec (CUR-01 to CUR-09,
MD-01, MD-03 to MD-05, TEN-07, NUM-01, TPL-01 to TPL-05, WF-, APR-, IMP-,
EXP-01, RBAC-04 to RBAC-08, AUD-01, CP-01); ADR 003 (money), ADR 007
(country packs), ADR 014 (imports). When this file and the core spec
disagree, the core spec wins.

## Scope

Accounting is a module (`api/modules/Accounting`, permissions
`accounting.*`), sold and switched on per tenant (RBAC-08). It works with
only the core installed: it reads core master data (companies, parties,
tax codes and rates, payment methods, dimensions, currencies and rates)
and raises its own events. It never reads another module's tables. Other
modules reach the ledger only through their own events, which Accounting
listens to when both modules are active, and which the posting rules turn
into journals (ACC-07).

This stage (7A) builds the ledger, its subledgers and reports, and the
posting engine tested with a fake source event. The real listeners (POS
sales, Inventory valuation, Payroll, Procurement) come in stage 7B and
later; their payloads are fixed below.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| ACC-01 | **Chart of accounts per company from a country template.** A company's chart is created once from its country's template, which is country-pack data (`api/country-packs/{KE,CD}/accounts.json`): Kenya a generic IFRS-style chart, the DR Congo the OHADA SYSCOHADA (révisé) classes 1 to 8. Templates hold structure only (codes, types, parents, roles), never tax rates. An account has a code (unique per company), one name (stored once, in the tenant's language at creation, then the tenant's), a type (asset, liability, equity, income, expense), a subtype, a parent, whether it is postable (headers are not), an optional fixed currency, a control flag (receivable or payable control: only the subledgers post to it), a cash-flow class, a revaluation flag and, for intercompany accounts, the counterparty company. Accounts are archived, never deleted; an account with postings keeps its type. | Must |
| ACC-02 | **Accounting settings per company.** The control accounts (receivable, payable), the tax accounts (output tax on sales, input tax on purchases), realised and unrealised FX gain and loss, rounding, retained earnings and the suspense account, filled from the template's roles and editable. | Must |
| ACC-03 | **Journals and the general ledger.** A journal has a date, a period, a kind (manual, system, recurring, reversal, revaluation, closing, opening), a status and lines. Each line holds a debit or a credit in bigint minor units of its transaction currency, the rate used (`numeric(18,8)`) and the base-currency amount (CUR-04), an account and optional dimensions (ACC-21) and party. **Every posted journal balances in the company's base currency, enforced in the database** by a deferred constraint trigger. **Posted journals are immutable** (a trigger refuses any change to a posted journal's lines or amounts); corrections are reversals (ACC-05). Journals are numbered when posted (NUM-01, type `accounting.journal`). | Must |
| ACC-04 | **Manual journals with approval.** A manual journal is drafted, then submitted to its flow (WF document type `accounting.journal`, default flow: one approval by an Accountant at the company, escalating to the Owner after two business days). Approval posts it; rejection returns it to rejected. The requester never approves their own (APR-07). Manual journals may not post to control accounts. | Must |
| ACC-05 | **Reversals.** A posted journal is reversed once by a new journal with the sides swapped, at the same base amounts and rates, on a chosen date (default: the original's date), linked both ways and audited. | Must |
| ACC-06 | **Recurring journals.** A template of lines with a frequency (monthly, quarterly, yearly), a next date and an optional end. A daily command creates the due journals as drafts submitted to their flow, so each still passes approval. | Should |
| ACC-07 | **Posting rules and the posting service.** Posting rules are configuration (`acc_posting_rules`): per company and source event type, lines that map an amount field of the event to an account and a side. `PostingService::post()` takes a source document reference with amounts, currency, rate and dimensions and creates a posted system journal, **idempotently keyed by company, source type and source id** (the same source twice returns the first journal). A source that cannot post (no rule, a closed period, an unknown account, a missing rate) is kept as a posting exception, listed and retried, never lost. Per-line base amounts are rounded once; a remaining difference of at most one minor unit per line goes to the rounding account. | Must |
| ACC-08 | **Periods and close.** Monthly periods follow the company's fiscal year (`fiscal_year_start_month`) and are created on demand. Closing a period locks it: **the database refuses a posted journal dated in a closed period**. Periods close in order. Closing the last period of a fiscal year first posts the closing entry (income and expense balances to retained earnings). A closed period reopens with `accounting.period.reopen` and a reason, audited; reopening a year-end period reverses its closing entry, and only the latest closed period may reopen. | Must |
| ACC-09 | **Customer and supplier invoices and credit notes.** Created in Accounting on core parties (customer or supplier role, shared or of the company), with lines (description, optional item, account, quantity, unit price, tax code, dimensions), tax from the core tax codes and rates in force on the document date (MD-03): a code whose rate is "Rate needed" blocks posting (`tax_rate_missing`), as on the till; rates are never invented. Numbered at posting (NUM-01). Posting creates the journal (receivable or payable control, revenue or expense lines, tax lines). Posted documents are immutable; a credit note names the invoice it credits and never credits more than the invoice's total. | Must |
| ACC-10 | **Receipts and payments, allocation, realised FX.** A receipt from a customer or a payment to a supplier, into or out of a bank account, in that account's currency, posts to the control account with the party. It is allocated to open invoices and credit notes of the same party and currency, partly or fully; the rest stays on account and can be allocated later. When the rate at allocation differs from the invoice's, the realised FX gain or loss is posted. | Must |
| ACC-11 | **Ageing and statements.** Receivable and payable ageing at a date (current, 1–30, 31–60, 61–90, over 90 days past due) per party, in document and base currency. A party statement lists documents and receipts or payments with a running balance, and prints from the `accounting.statement` template. | Must |
| ACC-12 | **Bank and mobile money accounts.** A bank, mobile money or cash account is linked to one postable GL asset account and a currency, optionally to a core payment method. | Must |
| ACC-13 | **Statement import.** A bank statement is imported from CSV (column mapping: date, description, reference, amount or money in and out, date format) or from an M-Pesa statement export (receipt number, completion time, details, transaction status, paid in, withdrawn). Lines already imported for the account (same date, amount and reference) are skipped. | Must |
| ACC-14 | **Reconciliation.** A two-pane match: statement lines on one side, the account's unreconciled ledger lines on the other; one statement line matches one or more ledger lines of the same total. Suggestions match on amount, a date window and the reference. Matching rules (text in the description, direction) create and post a journal to a chosen account and match it (bank charges, M-Pesa fees). A match can be undone. The summary shows the statement balance, the ledger balance and the unmatched items. | Must |
| ACC-15 | **Multi-currency and FX.** Documents and journal lines may be in any active currency (Accounting Pro), at the core rate (CUR-03, `Converter`) or a rate entered on the document; the rate and base amounts are stored (CUR-04). Realised FX on allocation (ACC-10). **Unrealised FX at period end:** open receivables and payables in foreign currency and foreign-currency accounts flagged for revaluation are revalued at the closing rate (integer arithmetic, half up), posted as a revaluation journal dated the period's last day and reversed on the next period's first day. | Must |
| ACC-16 | **Budgets.** A budget per company and fiscal year, with monthly amounts per account and optional cost centre (MD-05) in base currency; budget against actual for a date range. | Should |
| ACC-17 | **Financial statements.** Trial balance, profit and loss, balance sheet, cash flow (indirect method), general ledger detail and account statements, by company, date range and dimension; amounts in base currency. Drill-down goes from a statement line to its GL lines, from a line to its journal, and from a journal to its source document. Reports and lists export (EXP-01). The trial balance always balances. | Must |
| ACC-18 | **Group consolidation (TEN-07).** Across companies of the tenant, translated to a reporting currency (balance sheet at the closing rate, profit and loss at each month's closing rate, the translation difference in equity), with intercompany accounts (accounts naming a counterparty company of the group) eliminated against each other and any difference shown. | Should |
| ACC-19 | **Fiscalisation and documents.** Posted customer invoices and credit notes are queued to the core fiscal queue (KRA eTIMS, DRC DGI) through the fiscal source `accounting`, when the company transmits. Invoices, credit notes and statements print from document templates (`accounting.invoice`, `accounting.credit_note`, `accounting.statement`); the fiscal block is locked on invoices and credit notes where the country pack requires it (TPL-03). | Must |
| ACC-20 | **Imports and exports.** The chart of accounts and opening balances come in through the import framework (IMP-01 to IMP-03). Accounts, journals, invoices and payments lists export (EXP-01). | Must |
| ACC-21 | **Dimensions.** Journal and document lines may carry a cost centre, department and project (MD-05) of the same company; reports filter by them. | Must |
| ACC-22 | **Tenancy, permissions, audit.** Every table carries `tenant_id` under forced row-level security with an isolation test; every endpoint checks `accounting.*` at the record's company (RBAC-04); every create, update, post, approval, reversal, close and reopen is audited (AUD-01). Money is bigint minor units plus currency; rates `numeric(18,8)`. | Must |
| ACC-23 | **Events.** `JournalPosted`, `InvoicePosted`, `PaymentRecorded`, `PeriodClosed`, dispatched after commit, for webhooks and other modules. | Should |
| ACC-24 | **Posting rules for many keys and default rules (7B).** A rule line on `prefix.*` posts every source field `prefix.{key}` (a payment method, an item category, a tax code, a sale line) to the account its map names for the key, else (lines `by: payment_method`) the bank, mobile money or cash account linked to that method (ACC-12) or the map's `type:{method type}`, else its default account; with none, the source is kept as an exception (`posting_account_missing`). A source type without a rule of its own may fall back to another's (`accounting.rule_fallbacks`: refunds and voids use the sale rule with its sides swapped, the daily summary the sale rule). The country chart template carries the default rules (account codes only); they are seeded with the chart, and `POST companies/{company}/accounting/posting-rules/defaults` adds the rules and template accounts a company is missing, never changing what it has. | Must |
| ACC-25 | **POS → Accounting: a sales journal per sale (7B).** Dr each payment method's account (`tender.{method}`), Cr change to cash (`change`), Cr revenue per item category before promotions (`revenue.{category}`), Dr promotion discounts net of tax (`promotion`, to contra-revenue by default, or an expense account by changing the rule), Cr tax payable per tax code (`tax.{code}`), Cr rounding (`rounding`). A refund posts what it gives back and a void the voided sale, both with the sale rule's sides swapped, dated the refund or void. | Must |
| ACC-26 | **Daily summary (7B).** A company setting (`sales_posting`: `per_sale`, default, or `daily_summary`). In summary mode, base-currency sales wait in `acc_source_queue` and `accounting:post-sales-summaries` (hourly) posts one `pos.sale_summary` journal per location, day (company time zone) and currency once the day has ended; a sale that arrives later forms another summary for its day. Sales in another currency, refunds and voids still post on their own. | Must |
| ACC-27 | **Inventory → Accounting (7B).** Every stock posting (`StockMoved`) changes the stock account by what it changed the valuation by (value less cost variance), its counterpart by the opposite of its value, and the cost variance to cost of sales: cost of sales per sale line (`inventory.cost_of_sales`), goods received against GRNI (`inventory.receipt`, for Procurement), opening stock (`inventory.opening`), adjustments and count variances to stock adjustment expense (`inventory.adjustment`, `inventory.count`), transfer losses (`inventory.transfer_loss`). Transfers within a company post nothing else. The stock account equals the valuation report. | Must |
| ACC-28 | **Loyalty → Accounting (7B).** One journal per Loyalty ledger entry: gift cards (sold: a liability, never revenue; redeemed: liability to the tender; expired: breakage income), points (when the programme gives a point a value: earned Dr loyalty cost / Cr points liability; redeemed into the tender; expired to breakage income; adjusted), customer credit (charged: Dr the store credit receivable control account; payments; adjustments). | Must |
| ACC-29 | **Backfill (7B).** `accounting:backfill --from=YYYY-MM-DD [--to=] [--tenant=]` posts what POS, Inventory and Loyalty recorded from an owner-chosen day, in each active tenant with Accounting (DueTenants, TenantContext): each module raises its events again (core `EventReplays`), and they post exactly as live ones, idempotently. | Must |
| ACC-30 | **Integration screens (7B).** The posting rules screen lists every source type with its own rule, its fallback or none, edits rules (maps kept) and adds the defaults; the exceptions list filters by status, retries, and opens the source record; journals drill down to the sale, the movements or the Loyalty record through each module's own route. | Must |

## Data model

All tables are prefixed `acc_`, carry `tenant_id` (RLS) and `company_id`,
and use UUID v7 keys. Amounts are `bigint` minor units next to a
`currency`; rates `numeric(18,8)`; base amounts in the company's base
currency (locked on the first posting, `BaseCurrencyLock`).

| Table | Holds |
| --- | --- |
| `acc_accounts` | The chart (ACC-01). Unique (company, code). |
| `acc_settings` | One row per company: template used, control, tax, FX, rounding, retained earnings and suspense accounts (ACC-02). |
| `acc_periods` | Monthly periods: fiscal year, number, start, end, status (open, closed), who and when closed or reopened, the closing journal (ACC-08). |
| `acc_journals` | Journal headers: number, date, period, kind, status (draft, pending, posted, rejected, cancelled), source type and id (unique per company), reversal links, memo, requester, poster (ACC-03). |
| `acc_journal_lines` | Lines: account, transaction currency debit and credit minor, rate, base debit and credit, dimensions, party, description. |
| `acc_recurring_journals` | Recurring templates (ACC-06). |
| `acc_posting_rules` | Event type to account mapping per company, versioned by archive (ACC-07). |
| `acc_posting_exceptions` | Sources that could not post, with the payload and the reason (ACC-07). |
| `acc_documents` | Customer and supplier invoices and credit notes (ACC-09), with FX snapshot, totals, base totals, the open balance, the journal and the credited invoice. |
| `acc_document_lines` | Their lines with tax code, rate as applied and amounts. |
| `acc_payments` | Receipts and payments (ACC-10). |
| `acc_allocations` | Payment or credit note to invoice allocations, with the realised FX amount and journal. |
| `acc_bank_accounts` | Bank, mobile money and cash accounts (ACC-12). |
| `acc_bank_statements`, `acc_bank_statement_lines` | Imported statements (ACC-13). |
| `acc_bank_matches` | Statement line to ledger line matches (ACC-14); posted lines stay untouched. |
| `acc_bank_rules` | Matching rules (ACC-14). |
| `acc_budgets`, `acc_budget_lines` | Budgets (ACC-16). |
| `acc_revaluations` | Revaluation runs with the rates used and their journals (ACC-15). |
| `acc_source_queue` | Stage 7B: POS sales waiting for their location's daily summary: source, location, local day, currency, the source as it would post alone, and the summary and journal once posted (ACC-26). |

Stage 7B adds `acc_settings.sales_posting` (`per_sale`, `daily_summary`) and
`acc_journals.source_link` (jsonb: the record in its own module, `{kind,
id}`, for the drill-down).

Database rules (migration): the balance trigger
`acc_journal_balanced` (deferred constraint trigger on lines and headers:
a posted journal has at least two lines, each line is one-sided, and
Σ base debit = Σ base credit > 0); the immutability trigger
`acc_journal_immutable` (a posted journal's lines cannot be inserted,
changed or deleted, its amounts, date, company and lines are frozen; only
the reversal link may be set); the period trigger `acc_journal_period_open`
(a journal cannot become posted with a date in a closed period of its
company).

## Posting: the source contract (ACC-07)

`Modules\Accounting\Posting\PostingService::post(SourceDocument $source)`:

```
SourceDocument {
  company_id:  uuid                        the company whose ledger it goes to
  source_type: string                      e.g. pos.sale, inventory.valuation, payroll.run
  source_id:   uuid                        the record's id in its module
  date:        ISO 8601 instant            posting date: its day in the company's time zone
  currency:    ISO 4217                    the transaction currency of every amount
  amounts:     {field: minor units}        signed integers (bigint as digit strings)
  fx?:         {rate, base, quote}         the rate the source used (CUR-04); else the core rate on the date
  dimensions?: {branch_id?, cost_centre_id?, department_id?, project_id?, party_id?}
  reference?:  string                      the printed number (receipt, GRN), shown on the journal
  memo?:       string
}
```

A rule line is `{field, side: debit|credit, account_id, party: bool}`. A
field the source does not carry is zero and adds no line; a negative
amount posts on the other side. The same company, source type and id
posts once: a repeat returns the first journal (`created: false`).

**Event payloads the integrations need (stage 7B and later).** Each
module raises its own event after commit; Accounting listens only when
both modules are active and builds a `SourceDocument` from the event and
the module's public read model, never its tables.

| Source type | Raised by | Amount fields | Dimensions |
| --- | --- | --- | --- |
| `pos.sale` | POS `SaleCompleted` | `total`, `net`, `tax`, `discount`, `rounding`, `tender.cash`, `tender.mobile_money`, `tender.card`, `tender.credit`, `tender.other`, `tax.{TAX_CODE}` | branch, party (customer) |
| `pos.refund` | POS `SaleRefunded` | as `pos.sale`, positive amounts refunded | branch, party |
| `pos.void` | POS `SaleVoided` | as `pos.sale` of the voided sale | branch, party |
| `pos.shift_variance` | POS `ShiftClosed` | `variance.{CURRENCY}` | branch |
| `inventory.receipt` | Inventory `GoodsReceived` valuation | `stock_value`, `grni` | branch, party (supplier) |
| `inventory.issue` | Inventory sale or issue costing | `cost_of_sales`, `stock_value` | branch, cost centre |
| `inventory.adjustment` | Inventory adjustment approved | `gain`, `loss`, `stock_value` | branch, cost centre |
| `procurement.supplier_invoice` | Procurement invoice matched | `total`, `net`, `tax`, `grni` | party, cost centre, project |
| `payroll.run` | Payroll `PayrollApproved` | `gross`, `net_pay`, `paye`, `employer.{CODE}`, `employee.{CODE}`, `deduction.{CODE}` | department, cost centre |
| `loyalty.points` | Loyalty points earned or redeemed | `liability`, `expense` | branch, party |

### Stage 7B: the integrations (ACC-24 to ACC-30)

Accounting listens to other modules' events with one queued listener
(`PostModuleEvent`, after commit, in the event's tenant, retried), and
acts only while Accounting **and** the raising module are active
(RBAC-08); with Accounting off nothing is posted and nothing is kept.
Every source goes through `PostingService` and the company's rules,
idempotent per (company, source type, source id); a source that cannot
post is a posting exception, listed and retried, never lost. Journals are
never posted into closed periods: the closed-period refusal (ACC-08) keeps
the source as an exception (`period_closed`); once the period reopens, a
retry posts it on its own date.

**Event payloads (extended in their raising modules, generic):**

- POS `SaleSnapshot::sale`: status, `voided_at`, `totals.paid_minor` and
  `rounding_minor`, `change {currency, amount_minor, in_sale_minor}` (paid −
  change = total + rounding exactly), `base {currency, total_minor,
  tax_minor, fx}`, each payment's `fx`, and per line `tax_code_id`,
  `tax_rate`, `tax_inclusive`, `net_minor`, `tax_minor` and `discount
  {total_minor, sources: {source: minor}, manual_minor}` (adjustments by
  source, capped at the discount); customer and location as before.
  `SaleSnapshot::refund` carries the same per-line fields (each refund
  line's share of its sale line), `rounding_minor`, base, location and
  customer. `SaleVoided` carries `voidedAt`.
- Inventory `StockMoved` adds `occurredAt` and `branchId`, and per movement
  `source_line_id` (the sale line) and `branch_id`; `StockAdjusted` adds
  `occurredAt` and `branchId`.
- Loyalty raises one event per ledger entry (`GiftCardSold`,
  `GiftCardRedeemed`, `GiftCardExpired`, `PointsEarned`, `PointsRedeemed`,
  `PointsExpired`, `PointsAdjusted`, `CreditCharged`,
  `CreditPaymentReceived`, `CreditAdjusted`), signed in the event's
  direction, with the channel (`pos`, `back_office`, `issued`, `system`),
  the sale's branch and rate, and for points their money value
  (`points × point_value_minor`) or null.

**Source types and fields:**

| Source type | Fields | Default rule (KE codes) |
| --- | --- | --- |
| `pos.sale` | `tender.{payment method}`, `change`, `rounding`, `revenue.{category}` (`none`), `promotion`, `tax.{tax code}` (`none`) | Dr tender by method (linked bank account, else cash 1110, mobile money 1130, card and transfer 1120, gift card, points and credit to loyalty tenders clearing 1230); Cr change 1110; Cr rounding 7200; Cr revenue 4100; Dr promotion 4300 (contra-revenue); Cr tax 2200 |
| `pos.refund`, `pos.void` | as `pos.sale`, for what is given back or voided | the `pos.sale` rule, sides swapped |
| `pos.sale_summary` | the sum of a location's day | the `pos.sale` rule |
| `inventory.cost_of_sales` | `stock.{sale line}`, `cost_of_sales.{sale line}`, `cost_variance` | stock 1300, cost of goods sold 5100 (all debit, signed) |
| `inventory.receipt` | `stock`, `grni`, `cost_variance` | 1300, GRNI 2130, 5100 |
| `inventory.opening` | `stock`, `opening`, `cost_variance` | 1300, suspense 1900, 5100 |
| `inventory.adjustment`, `inventory.count` | `stock`, `adjustment`, `cost_variance` | 1300, stock adjustments and losses 5300, 5100 |
| `inventory.transfer_loss` | `stock`, `loss`, `cost_variance` | 1300, 5300, 5100 |
| `loyalty.gift_card_sold` | `amount`, and `pos`, `direct` or `issued` | Cr gift card liability 2310; Dr sales 4100 (sold on a POS sale: reclassified from revenue), cash 1110 (back office), loyalty costs 6910 (given) |
| `loyalty.gift_card_redeemed` | `amount` | Dr 2310, Cr 1230 |
| `loyalty.gift_card_expired` | `amount` | Dr 2310, Cr breakage income 4910 |
| `loyalty.points_earned`, `loyalty.points_adjusted` | `amount` (the points' value) | Dr loyalty costs 6910, Cr points liability 2320 |
| `loyalty.points_redeemed` | `amount` | Dr 2320, Cr 1230 |
| `loyalty.points_expired` | `amount` | Dr 2320, Cr 4910 |
| `loyalty.credit_charged` | `amount`, and `pos` or `direct` | Dr customer store credit receivable 1220 (party), Cr 1230 (POS) or sales 4100 (back office) |
| `loyalty.credit_payment_received` | `amount` | Dr 1110, Cr 1220 (party) |
| `loyalty.credit_adjusted` | `amount` | Dr 1220 (party), Cr other expenses 6900 |

The CD (SYSCOHADA) template maps the same roles to 571, 551, 521, 4711,
658, 701, 7019, 443, 311, 603, 408, 471, 6038, 4191, 4198, 758, 627 and
4118. Loyalty tender clearing nets to zero when both POS and Loyalty post
(the POS tender debits it, the Loyalty entry credits it); a balance left
there is a tender Loyalty did not post (module off, unknown card), for
review.

## Permissions

| Permission | What it allows |
| --- | --- |
| `accounting.account.view`, `accounting.account.manage` | Read; create the chart from the template, add, edit, archive accounts and the settings |
| `accounting.posting_rule.view`, `accounting.posting_rule.manage` | Read; edit posting rules and retry posting exceptions |
| `accounting.journal.view` | Read journals, the GL and posting exceptions |
| `accounting.journal.create` | Draft, edit, submit and cancel manual and recurring journals |
| `accounting.journal.approve` | Act on a journal's flow (approve, reject) |
| `accounting.journal.reverse` | Reverse a posted journal |
| `accounting.invoice.view`, `accounting.invoice.create`, `accounting.invoice.post` | Read; draft and edit; post (number, journal, fiscal queue) customer and supplier documents |
| `accounting.payment.view`, `accounting.payment.create` | Read; record receipts and payments and allocate them |
| `accounting.bank.view`, `accounting.bank.manage`, `accounting.bank.reconcile` | Read; set up bank accounts and rules; import statements, match and unmatch |
| `accounting.budget.view`, `accounting.budget.manage` | Read; edit budgets |
| `accounting.period.view`, `accounting.period.close`, `accounting.period.reopen` | Read; close (and revalue); reopen a closed period |
| `accounting.report.view`, `accounting.report.consolidate` | Financial statements of a company; consolidated statements of the group |

All are checked at the record's company (RBAC-04): a company role reaches
that company's ledger; a tenant role every company's; consolidation needs
`accounting.report.consolidate` at every company in the group.

Role templates: **Accountant** holds
`accounting.*`; **Approver** holds `accounting.journal.approve` through
`*.approve` and `accounting.journal.view` (it reads what it approves); **Read-only Auditor** reads (`accounting.*.view`); the Owner
holds everything. Admin and Branch Manager hold none.

## Screens (web)

- Settings: chart of accounts (tree, create from template, add, edit,
  archive), accounting settings, posting rules (and exceptions).
- Journals: list, entry form (lines with debit and credit, currency and
  dimensions, balance check), detail with flow status, submit, approve or
  reject (through the approvals inbox), reverse.
- Customer and supplier invoices and credit notes: list, form, detail
  (post, print, credit), receipts and payments with allocation.
- Bank accounts and reconciliation: a two-pane matching screen with
  suggestions, rules and statement import.
- Budgets: a grid of accounts by month.
- Period close: periods of a fiscal year, close (with revaluation), reopen.
- Reports: trial balance, P&L, balance sheet, cash flow, GL detail,
  ageing, budget against actual, consolidation; drill-down to GL and to the
  journal and its source.

## Integrations

- **Core:** parties (MD-01), tax codes and rates (MD-03), payment methods
  (MD-04), dimensions (MD-05), currencies, rates and `Converter`,
  `BaseCurrencyLock` (CUR-), numbering (NUM-01), workflow and approvals
  (WF-, APR-), document templates (TPL-), fiscal queue, import framework
  (IMP-), list exports (EXP-01), audit (AUD-).
- **Other modules:** only through their events and posting rules
  (ACC-07). None is wired in this stage.

## Decisions (recorded for the owner)

1. **Module keys and tiers.** The registry module is `accounting`
   (permissions `accounting.*`). The Phase 6 catalogue sells
   `accounting_essentials` and `accounting_pro`, not `accounting` (the task
   said `accounting` existed; flagged). `ModuleRegistry::soldAs()` lets a
   registry module follow several catalogue keys: `accounting` follows
   either key; a second registry module, `accounting_pro`, follows Pro and
   gates what concept note 8.4 puts in Pro: foreign-currency documents and
   journal lines (`accounting_pro_required`), revaluation, budgets and
   consolidation (`module:accounting_pro`).
2. **Invoices live in Accounting** for businesses without a Sales or CRM
   module. Core gets three document types: `accounting.invoice`,
   `accounting.credit_note` (fiscal) and `accounting.statement`; the KE and
   CD packs list the first two under `documents.fiscal.required_on`.
3. **Tax on invoices.** Exclusive or inclusive per document, through
   `TaxCalculator::forLine` on the document date in the company's time
   zone; one tax code per line. Withholding tax (VAT withholding in Kenya)
   and excise on invoices are deferred: withholding needs confirmed
   country-pack rates and its own certificates.
4. **Allocation is same-currency.** A receipt allocates to documents of
   its own currency. Settling a USD invoice with a CDF receipt is deferred
   (it needs a cross-rate policy); until then, record the receipt in the
   invoice's currency.
5. **Revaluation** posts at the period's last day and reverses on the
   next period's first day (the usual auto-reversing method), so realised
   FX is always measured against the original rate. Arithmetic is integer:
   the new base amount is `round_half_up(open × rate)` through `Converter`,
   and the adjustment is new base − carried base.
6. **Consolidation translation.** Each company's balances are translated
   with that company's own rates (`ExchangeRates::stored`) from its base to
   the reporting currency: balance sheet accounts at the closing rate (the
   `to` date), profit and loss at the closing rate of each month's end
   (an average-rate proxy), the difference to a translation reserve line in
   equity. Intercompany accounts eliminate pairwise; the remaining
   difference is shown, never hidden.
7. **Periods** are created on demand when a document or journal falls in
   a fiscal year that has none; they close in order, and only the latest
   closed period reopens.
8. **Names are single-language** (owner decision): account names from the
   template are written once in the tenant's default language, from
   `lang/{en,fr}/accounting_charts.php`.
9. **SYSCOHADA** (CD): the template carries classes 1 to 8 with the main
   headings and the postable accounts a small business needs (e.g. 411
   Clients, 401 Fournisseurs, 443 État, TVA facturée, 445 État, TVA
   récupérable, 521 Banques, 571 Caisse, 601 Achats, 701 Ventes, 131
   Résultat net, 121 Report à nouveau). Tenants add the rest.
10. **Drafts are cancelled, not deleted** (TEN-06); posted records are
    never edited.
11. **Journal numbering** draws `accounting.journal` numbers at posting
    inside the posting transaction (`JV-{YYYY}-{00001}`, yearly); documents
    use `accounting.customer_invoice` (`INV-…`), `accounting.customer_credit`
    (`CN-…`), `accounting.supplier_invoice` (`BILL-…`),
    `accounting.supplier_credit` (`DN-…`), `accounting.receipt` (`RCT-…`)
    and `accounting.payment` (`PAY-…`).
12. **Statement lines** are deduplicated on (bank account, date, amount,
    reference); a line without a reference is deduplicated on its
    description.

13. **Rules made while building** (stage 7A):
    - A customer credit note must name the invoice it credits (the fiscal
      queue sends it as a refund of that sale); supplier credits may not.
      Posting a credit note settles its invoice as far as both are open;
      the rest of the credit is allocated from
      `POST accounting/credit-notes/{id}/allocate`.
    - Realised FX goes to the FX gain or loss account by sign (two
      accounts, as SYSCOHADA keeps 776 and 676 apart), not one net account.
    - One revaluation per period; reopening a period does not undo it
      (reverse its journal by hand if needed).
    - The opening balances import refuses control accounts: open invoices
      come in as documents, so subledgers and ledger agree. Each row posts
      against the suspense account.
    - Manual journals are approved through the `accounting.journal` flow
      (`approvals/{id}/approve`); an approved journal whose posting is
      refused keeps `post_error` and is posted again from its page.
    - Drafts print (no number yet); reports default to the current fiscal
      year to date in the company's time zone; P&L, cash flow and
      consolidation leave year-end closing entries out.
    - The isolation fixture builds every `acc_*` table in both tenants; the
      Accounting module sits in the fixture's subscription as
      `accounting_pro`.
14. **Database rules** are triggers in the migration
    (`acc_journal_balanced` deferred, made immediate by the poster inside
    its transaction; `acc_journal_immutable`; `acc_journal_period_open`,
    which shares the period row lock with a concurrent close).

## Decisions (stage 7B, recorded for the owner)

15. **Customer credit is a Loyalty subledger with its own control
    account.** Loyalty credit accounts post to "Customer store credit
    receivable" (KE 1220, CD 4118, `is_control`), not to the Accounting AR
    control (1200), which stays the main receivables ledger for invoices
    (ACC-09). The two never mix: invoices allocate only in Accounting,
    credit payments only in Loyalty. The owner can change this by pointing
    the `loyalty.credit_*` rules at another account; doing so mixes the
    two subledgers in one control account, so their ageing no longer
    reconciles to it.
16. **Gift cards are a liability, never revenue.** A card sold on a POS
    sale is first revenue in the sale journal (POS does not know it is a
    card) and is reclassified by `loyalty.gift_card_sold` (Dr sales, Cr
    gift card liability); the default reclassifies from the sales account
    4100. A tenant that maps the gift card item's category to another
    revenue account must point the `pos` line there too. Gift card items
    should carry an exempt tax code; a taxed gift card line would leave its
    tax in tax payable (rates are never invented, so this is the tenant's
    configuration).
17. **Points are a provision only when they are worth money.** With a
    point value, earning posts Dr loyalty costs / Cr points liability at
    `points × point_value_minor`, a redemption moves the liability to the
    tender, expiry goes to breakage income. With no value (zero), points
    are not money: nothing posts (not an exception). The liability is
    measured at the value in force when each entry is made.
18. **Promotions** post net of tax: a tax-inclusive line's promotion is
    its share of the line's net (`round_half_up(discount × net / total)`),
    so revenue (net + that share) − promotion = net exactly. Manual
    discounts stay netted in revenue. Contra-revenue (4300, CD 7019) is the
    default; an expense account works by editing the `promotion` line.
19. **Tenders post at their value in the sale currency** to the method's
    account, whatever currency was handed over; foreign notes in a drawer
    are reconciled by the shift count (`pos.shift_variance`, later).
20. **Voids and refunds reverse proportionally**: a void posts the voided
    sale's own amounts with the sale rule reversed, dated the void (its
    base amounts at the sale's rate, so the pair nets to zero); a refund
    posts its own share (lines, tax, promotion share, rounding) and the
    stock comes back at the cost it left at.
21. **Multi-currency** uses the snapshot's rate (CUR-04). Foreign-currency
    postings need Accounting Pro; with Essentials such a source is kept as
    an exception (`accounting_pro_required`) and posts on retry after an
    upgrade.
22. **Daily summaries** cover base-currency sales only, so every summary
    has one rate (identity); their id derives from the location, day,
    currency and the sales summed, so reruns post nothing twice.
23. **The inventory journal's source id** is the posting's first movement
    (smallest id), stable on replay and distinct for a transfer's dispatch
    and receipt; its link opens the document's movements
    (`/inventory/movements?source=`).
24. **Gift card expiry** is new in Loyalty (`loyalty:expire-gift-cards`,
    daily 02:40): what is left on a card past its expiry date is written
    off by an `expire` entry, once per card.
25. **Backfill** replays through each module's own `EventReplays`
    registration (core), never reading their tables; the start date is the
    owner's choice on the command line. Records in closed periods become
    exceptions.

## Status (stage 7B)

| ID | Status |
| --- | --- |
| ACC-24 to ACC-30 | Built and tested (IntegrationPostingTest, LedgerEventsTest, SaleSnapshotTest, PostingRules.test.jsx) |

## Status (stage 7A)

| ID | Status |
| --- | --- |
| ACC-01 to ACC-05, ACC-07 to ACC-15, ACC-17, ACC-19 to ACC-22 | Built and tested |
| ACC-06 | Built and tested (daily `accounting:recurring` at 05:00) |
| ACC-16, ACC-18 | Built and tested (Accounting Pro) |
| ACC-23 | Events raised (`JournalPosted`, `DocumentPosted`, `PaymentRecorded`, `PeriodClosed`); no webhook mapping yet |

## Acceptance criteria (stage 7A)

1. A journal whose base debits and credits differ cannot be posted, even
   by a raw SQL update (database test); a posted journal's lines cannot be
   changed or deleted.
2. Posting the same source twice creates one journal; a source without a
   rule becomes a posting exception and posts on retry once a rule exists.
3. A journal dated in a closed period is refused, through the API and in
   the database.
4. Revaluation of a USD receivable at a new rate posts the exact integer
   difference (half up) and reverses it on the next period's first day.
5. Consolidation of two companies with intercompany balances eliminates
   them and translates to the reporting currency.
6. The trial balance balances for any mix of random journals (property
   test).
7. Every table has row-level security and is covered by the tenant
   isolation suite; every endpoint refuses other tenants' ids and users
   without the permission.

## Deferred

- Cross-currency allocation (decision 4), withholding tax and excise on
  invoices (decision 3).
- Payment batches and bank payment files (Payroll and Procurement bring
  their own).
- Fixed-asset depreciation (Fixed Assets module, through posting rules).
- Multi-level approval thresholds per amount ship as flow edits, not
  defaults.
- Listeners for Procurement and Payroll (their stages; payloads above).
- Shift variances (`pos.shift_variance`) and manual cash movements.
- Editing a rule line's per-key map on the screen (maps from the defaults
  are kept when a rule is edited; the API takes them).
- Dashboard widgets for cash and receivables.

## Open questions

- Should Essentials allow a single foreign-currency bank account (common
  in Kenya for USD receipts)? Today any foreign-currency line needs Pro.
- The SYSCOHADA template's postable accounts beyond the minimum (decision
  9): does the owner have a reference chart from a DRC accountant?
