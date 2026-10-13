# Phase 7: business modules

Roadmap exit criteria: each module built from its own spec in `docs/modules/`, which is written first from concept note section 5, in concept-note order:

1. Inventory
2. Loyalty
3. Accounting
4. Procurement
5. Stores
6. HR
7. Payroll
8. CRM
9. E-commerce
10. Fixed assets
11. Manufacturing

Payroll needs country-pack data; never invent rates.

## Ground rules

- **CLAUDE.md in full.** In particular:
  - Each module lives in `api/modules/<Module>` with its own routes, migrations, models, policies, events, listeners and tests. The POS module is the pattern to follow.
  - A module works with only the core installed. It never reads or writes another module's tables.
  - Integrations go through domain events and core services, and switch on only when both modules are subscribed (RBAC-08).
  - Every tenant table has RLS and an isolation test.
  - Money is bigint minor units plus currency; rates are `numeric(18,8)`.
  - Never hard-delete; audit everything.
  - Use Form Requests and policies scoped by company, branch and location.
  - en/fr for every string. Styling uses tokens only.
- **Spec first.** Each module task starts by writing `docs/modules/<module>.md` in the format of `docs/modules/pos.md`:
  - source, scope, numbered requirements (`INV-01`, `LOY-01`, `ACC-01`, …) with priority;
  - data model, events raised and consumed, permissions, screens, integrations, open questions.

  The spec sits under the core spec. Where they disagree, the core spec wins, and the conflict is flagged.
- **Each module registers through the existing core seams, as POS does:**
  - the module registry;
  - the permission catalogue and role templates;
  - catalogue keys from Phase 6, to sell the module;
  - document types and default flows (WF), number formats (NUM), document templates (TPL);
  - custom field entities (CF), list views and form layouts (LAY), dashboard widgets;
  - the import framework (IMP), export (EXP-01/02), sync entities when the till needs them, and the usage counters (employees from HR).
- **Country rules are data.** Charts of accounts, statutory payroll tables, holidays and tax treatments are effective-dated country-pack rows. Missing values are seeded as "needs confirmation" and block the dependent action. They are never guessed.
- **Process.**
  - Build modules in parallel where they don't depend on each other.
  - Each module gets its own branch and a reviewer, because every module handles money, stock or personal data.
  - Each UI gets Playwright checks.
  - Write tests as you go, run only the touched tests, and run the shared fragile suites before each merge.
  - Run `pint --test` over the whole API before each push.
  - Merge locale JSON by parsing.
  - Sum the RoleTemplates counts across branches.
  - Agents use their own test database, not the shared `app_test`.
  - Check CI on main after each merge.

## Stages

| Stage | Modules and tasks | Depends on |
|---|---|---|
| 7A | Inventory; Loyalty, promotions and credit; Accounting (core ledger) | – |
| 7B | Procurement; Stores; integrations: POS → Inventory (stock out), POS → Accounting (sales journals), Inventory → Accounting (stock valuation) | 7A |
| 7C | HR; Payroll (KE, CD) | 7A (Accounting for payroll journals) |
| 7D | CRM; E-commerce and delivery; Fixed assets; Manufacturing | 7A, 7B |
| 7E | Whole-phase review, Playwright walkthrough, report, roadmap tick | all |

Each stage ends with a stage review, a short walkthrough and a stage note in the phase report. That way problems surface before the next stage builds on them.

## Module notes

- **Inventory:**
  - variants, units and conversions (building on core items and units), batches and expiry, serial numbers;
  - warehouses as core locations, with transfers, stock counts, adjustments with approval and reorder levels;
  - costing (FIFO and weighted average) with cost layers, and barcode labels.
  - The stock ledger is append-only. It consumes `SaleCompleted`, `SaleVoided` and `SaleRefunded` when POS is active, and `GoodsReceived` from Procurement.
- **Loyalty:**
  - points earn and redeem, with points as a POS tender and expiry;
  - a promotions engine evaluated on the till offline;
  - customer credit accounts with limits, statements and ageing;
  - gift cards and vouchers.
  - Promotions and points rules sync to the till.
- **Accounting:**
  - charts of accounts from country templates; journals and the general ledger;
  - AR and AP subledgers;
  - bank and mobile-money reconciliation;
  - multi-currency with FX gains and losses;
  - budgets, cost centres (core dimensions), period close and financial statements;
  - group consolidation.
  - Posting rules map other modules' events to journals. They are configuration, not code.
- **Procurement:**
  - requisitions → RFQs and tenders (supplier portal, sealed bids, timed opening, scored evaluation, award and regret letters) → quotes and comparison → POs → GRNs → supplier invoices;
  - landed costs;
  - supplier performance.
- **Stores:** requisitions from departments, issues, returns, store ledgers and consumption reports.
- **HR:**
  - employees, contracts, departments and positions;
  - leave;
  - shifts and rosters with swaps, overtime, and night and holiday rules;
  - attendance by app or PIN, with a biometric interface;
  - documents, onboarding and offboarding, disciplinary, appraisals;
  - self-service.
- **Payroll:**
  - KE and CD statutory deductions, read from country-pack tables (PAYE bands, NSSF, SHIF, the housing levy; CNSS, IPR, INPP, ONEM). These are seeded only from the confirmed data the owner provides, and anything missing is flagged.
  - Allowances, deductions, overtime from HR, loans and advances;
  - multi-currency pay;
  - payslips, bank and mobile-money files, statutory returns.
- **CRM:** leads, opportunities, quotes, a customer 360 view, follow-ups, sales targets.
- **E-commerce:**
  - an online store synced with inventory;
  - WhatsApp ordering;
  - delivery orders, rider assignment and tracking, cash on delivery.
- **Fixed assets:** register, categories, depreciation methods, transfers, disposals, maintenance.
- **Manufacturing:**
  - bills of materials and recipes (restaurants included);
  - production orders, consumption, wastage, yield and costing.
