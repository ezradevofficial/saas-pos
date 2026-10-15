# Payroll module spec (phase 7, stage 7C)

Source: concept note sections 5 (Payroll row: Kenya and DRC statutory
deductions, allowances and deductions, overtime and shift allowances pulled
from HR attendance, loans and advances, multi-currency pay such as a USD
salary with CDF tax, payslips, bank and mobile money payment files,
statutory returns), 6.4 (salary defined in one currency and paid or taxed in
another), 7.3 (payroll compliance: PAYE with reliefs, NSSF, SHIF, the
Affordable Housing Levy, NITA, P9 and return files; IPR, CNSS, INPP, ONEM;
rates and bands are dated data) and 7.4 (data protection); platform core
spec (TEN-01, TEN-06, RBAC-01 to RBAC-08, CUR-01 to CUR-04, WF-01, WF-02,
APR-01, APR-02, APR-07, NUM-01, TPL-01, TPL-04, AUD-01, AUD-02, EXP-01,
EXP-02, LIM-04, CP-01, CP-02, HOOK-01); docs/modules/hr.md (owner decision
B, the read contracts and the events of HR-33); docs/modules/accounting.md
(ACC-07, ACC-24, the `payroll.run` source); ADR 002 (RLS), ADR 003 (money),
ADR 006 (RBAC), ADR 007 (country packs), ADR 008 (lists). When this file
and the core spec disagree, the core spec wins.

## Scope

Payroll is a module (`api/modules/Payroll`, permissions `payroll.*`,
catalogue key `payroll`), sold per employee and switched on per tenant
(RBAC-08). It works with only the core installed **plus HR's employee core**,
which Payroll brings with it (owner decision B: employees, contracts, pay
accounts, identifiers and positions open when `hr` or `payroll` is active).
It never reads HR's tables: it keeps its own copy of what it pays from,
fed by HR's events and HR's read contracts, and raises its own events, which
Accounting turns into journals when both modules are active.

- **Statutory rules are country-pack data, never code.** KE and CD
  statutory tables (bands, reliefs, contribution rates, tiers, caps, levies,
  rounding rules) are effective-dated rows in
  `api/country-packs/{KE,CD}/payroll.json`. **No figure is shipped**: every
  value is null and "rate needed" until the owner confirms it from an
  official source. A run for a company whose country has a required value
  missing is refused, naming what is missing (PAY-08). Tests inject their
  own fixture rates; they are never written to the packs.
- **A pure engine.** The statutory engine is a pure, deterministic function
  of the pack values in force and the employee's pay, in integer minor
  units, with each component's rounding rule taken from the pack.
- **Pay data is sensitive.** Amounts per employee are shown only to
  holders of the payroll permissions at the employee's place whose field
  rules (RBAC-05) do not hide them; payslips only to the employee and
  payroll roles; reads of pay data are audited; bank details and ID
  numbers come only through HR's audited read contracts and are never
  stored by Payroll.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| PAY-01 | **Stand-alone module.** Payroll registers through the core seams (module registry, permission catalogue, role templates, the document type `payroll.run` and its default flow, the number format of runs, the `payroll.payslip` data source, field rules resource `payroll`, lists and exports, event replays for the Accounting backfill). Every route is behind `module:payroll` (403 `module_inactive`). It works with Payroll alone (HR's employee core, decision B) and with HR (leave and attendance feed it, PAY-11, PAY-12). | Must |
| PAY-02 | **Settings per company.** Overtime, night, public-holiday and rest-day **multipliers** (decimal, e.g. `1.5`; null: that time is not paid extra), standard monthly hours and days (the hourly and daily rate of a monthly salary), and whether overtime and premiums are taxable and contributory. Company data, not statutory figures: a pack may later carry statutory minimums. | Must |
| PAY-03 | **Pay groups and periods.** Per company: code, name, frequency (`monthly`, `weekly`, `biweekly`), the anchor date of weekly and bi-weekly periods, and the pay day (day of the month, or the period's last day). Periods follow the frequency: calendar months; 7 or 14 days from the anchor. Archived, never deleted. | Must |
| PAY-04 | **Pay items.** Per company: code (unique), name, kind (`earning`, `deduction`), and flags: earnings `taxable` and `contributory` (in the bases of statutory contributions); deductions `pre_tax` (reduces taxable pay) and `insurance_premium` (the base of a relief, when the pack has one). The company decides its items' flags; nothing is assumed. | Must |
| PAY-05 | **Employee pay setup.** Payroll's copy of each employee (number, name, company, place, department, position, user link, hire and termination dates, status) with their pay setup: pay group, pay currency (default: the contract's), payment method (`bank`, `mobile_money`, `cash`), dependants and children (for pack components that use them), and a hold flag (held employees are left out of runs). The basic pay is the HR contract in force, never typed again (HR-07). | Must |
| PAY-06 | **Recurring and one-off items.** Per employee: an item, an amount in the pay currency, recurring (from, optional to) or one-off (the period it falls in). A one-off is paid once: the posted run that used it marks it. | Must |
| PAY-07 | **Statutory engine.** Components of the pack (methods `bands`, `rate`, `tiers`, `fixed`, `headcount_rate`, `relief_fixed`, `relief_rate`, `reduction_per_dependant`, `allowance_per_child`; see "Statutory engine") computed from the period in force on the pay date, on bases `gross`, `taxable_gross`, `contributory_gross`, `basic`, `taxable_pay`, `insurance_premiums`; employee and employer shares; caps, floors, ceilings and tiers; reliefs and reductions in a fixed order; each component rounded once by its pack rounding rule (`half_up`, `down`, `up` to a unit in minor units). Pure and deterministic, in integer minor units of the pack currency. | Must |
| PAY-08 | **Missing rates block the run.** Calculating a run checks every component of the company's country pack that applies to it (required, or conditional and used by an employee of the run): no period in force on the pay date, a period still `needs_confirmation`, or any null value refuses the run (422 `statutory_rates_missing`) with the list, worded in en/fr ("PAYE (KE): bands, rounding"). A country without a payroll pack is refused (`payroll_pack_missing`). Weekly and bi-weekly runs need the pack's rule for non-monthly periods. | Must |
| PAY-09 | **Gross pay.** Basic from the contract(s) in force: monthly pay pro-rated by calendar days employed in the period (hire, termination, a contract changing mid-period); daily and hourly pay from closed attendance days and hours (none: zero and a warning). Earnings and deductions from PAY-06, unpaid leave (PAY-11), overtime and premiums (PAY-12), loans (PAY-13). | Must |
| PAY-10 | **Multi-currency pay (CUR-04).** A contract in one currency may be paid in another, and statutory figures are in the pack currency (e.g. a USD salary, CDF tax). Conversions use the company's core rate (shop, else reference) in force at the end of the pay date in the company's time zone; each line stores the rates used (contract to pay, pay to statutory, pay to base) and base-currency amounts. A missing rate is a line error (`rate_missing`). | Must |
| PAY-11 | **Unpaid leave from HR.** `LeaveApproved` of an unpaid type records the days (with their fractions); `LeaveCancelled` removes them. A monthly-paid employee's unpaid days in the period are deducted at the daily rate (monthly pay ÷ standard monthly days). Daily and hourly pay already follow attendance. | Must |
| PAY-12 | **Overtime and premiums from HR.** `AttendancePeriodClosed` records each employee's minutes. A run takes the closed periods ending inside its period not yet paid: overtime hours × hourly rate × the overtime multiplier, and night, holiday and rest-day hours × hourly rate × (multiplier − 1) on top of the basic; daily and hourly pay from the period's present days and regular hours. A posted run marks the periods it paid. | Must |
| PAY-13 | **Loans and advances.** Per employee: kind (`loan`, `advance`), principal, currency (the pay currency), disbursed on, instalment, first deduction date. A ledger (append-only: disbursement, repayment, settlement, reversal) gives the balance. A run deducts min(instalment, balance) of each active loan; early settlement is either paid outside payroll (a settlement entry) or taken in full by the next run. A loan with a zero balance is settled. Interest is not computed. | Must |
| PAY-14 | **The run.** Number (`PR-{YYYY}-{00001}`), company, pay group, period, pay date, kind (`regular`, `correction`), status `draft` → `calculated` → `pending` → `approved` → `posted` (or `cancelled` before submission). One regular run per pay group and period. Calculating (again) replaces its lines. A line with an error or a negative net pay blocks submission. | Must |
| PAY-15 | **Review with variances.** Per employee and in total: gross and net against the last posted regular run of the pay group, the difference and percentage, and new and gone employees. | Must |
| PAY-16 | **Maker-checker approval (WF-01, APR-07).** Submitting starts the run's flow (`payroll.run`); the preparer (who calculated it last) and the submitter never approve it. Default flow (WF-02): one approval by an Owner at the company, escalating to the Approvers after two business days; an `approved` end is reachable only through an approval. Rejected: back to `calculated`. Approved: `PayrollApproved`. | Must |
| PAY-17 | **Lock and post.** Posting an approved run (`payroll.run.post`) locks it for good: loans repaid, one-offs and attendance marked paid, payslips issued, `PayrollPosted` raised. Posted runs and their lines are never changed (database trigger). | Must |
| PAY-18 | **Corrections (no editing posted runs).** An off-cycle correction run on a posted run's pay group and period, for chosen employees: `reverse` (minus what was posted) or `recalculate` (what the employee should have had, with today's inputs, minus what was posted). It is calculated, approved and posted as any run; its lines, payslips and journals carry the signed differences. | Must |
| PAY-19 | **Payslips.** One per employee and posted run, printed from the `payroll.payslip` document template (TPL-01) as a PDF: earnings, deductions, statutory lines, loans, net pay, employer contributions, rates used. Seen only by the employee (self-service, "My payslips") and holders of `payroll.payslip.view` at the employee's place; every view and download is audited. | Must |
| PAY-20 | **Payment files.** From a posted run: a generic CSV (all methods) and pluggable formats (`PaymentFormat`), among them an M-Pesa B2C bulk file (mobile money only, layout as pack data, flagged until confirmed); no provider API is called. Accounts come from HR's `PayAccounts` (the reader needs `hr.bank.view`, audited); files are built on demand and never stored. Employees without a fitting account are listed. `PaymentFileGenerated` is raised for future dispatchers. | Must |
| PAY-21 | **Statutory returns.** From posted runs of a month (or a year): the return types of the pack (KE: PAYE, NSSF, SHIF, housing levy, NITA, P9; CD: IPR, CNSS, INPP, ONEM), each a CSV per employee with the identifiers HR holds (`Identifiers`, needs `hr.identity.view`, audited) and the component amounts. A layout the pack does not confirm is a placeholder, flagged `needs_confirmation` in the record and the file name. Files are built on demand; only the return's record (period, totals, who, when) is kept. | Must |
| PAY-22 | **Accounting (when both are active).** `PayrollPosted` carries, per department and currency, `gross`, `net_pay`, `statutory.{code}`, `employer.{code}`, `deduction.{code}` and `loans`; Accounting posts one `payroll.run` journal per group (gross pay expense with the department as dimension, statutory and other liabilities, net pay payable, employer contributions as expense and liability). Default rules in the KE and CD chart templates. Balanced by construction; posting is idempotent. | Must |
| PAY-23 | **Data protection.** Field rules (RBAC-05, resource `payroll`): `amounts` hides per-employee amounts (pay setup, items, loans, run lines, payslips) and `totals` the run totals; hidden fields are left out of responses and exports, and read-only `amounts` refuses edits (`field_readonly`). Pay reads (run lines, payslips, employee pay) are audited (`payroll.pay.view`). Bank accounts and identifiers are read only through HR's contracts. | Must |
| PAY-24 | **Self-service "My payslips".** A signed-in user linked to an employee (HR-31) lists and downloads their own payslips of posted runs; no permission is needed. | Must |
| PAY-25 | **Lists and exports** (EXP-01): pay groups, items, employees' pay setup, loans, runs and run lines with search, filters and export, field rules applied. | Must |
| PAY-26 | **Audit** (AUD-01): settings, pay groups, items, pay setup, employee items, loans and their entries, runs (calculate, submit, approve, post, cancel, corrections), payslip views and downloads, payment files and returns. | Must |
| PAY-27 | **Scope** (RBAC-04): employee data at the employee's place (location, else branch, else company); pay groups, runs, returns and settings at their company. Out of scope is not found. | Must |
| PAY-28 | **Never blocked by limits or dunning** (LIM-04): payroll runs are not usage-limited, and calculating, submitting and posting a run stay allowed in read-only mode, as approvals do. | Must |

## Statutory engine

A component of `payroll.json`:

```
{code, kind: income_tax|contribution|levy|relief|allowance, method, base,
 applies_to?, when?: insurance_premiums|dependants|children, required,
 periods: [{effective_from, effective_to, needs_confirmation, rounding:
 {mode: half_up|down|up, unit_minor}, ...method values}]}
```

Every money figure is per **month**, in minor units of the pack currency,
as a digit string; rates are percentages as strings ("12.5"). A value that
is present and null is missing; a value absent does not apply. Methods:

| Method | Values | Result |
| --- | --- | --- |
| `bands` | `bands: [{up_to_minor, rate}]` (last `up_to_minor` null), optional `max_rate` | Progressive tax on the base; capped at `max_rate` % of the base when present |
| `rate` | `employee_rate`, `employer_rate` (either may be absent), optional `base_cap_minor`, `employee_min_minor`, `employee_max_minor`, `reduces_taxable` | Percent of the base (capped), employee share floored and capped |
| `tiers` | `tiers: [{up_to_minor, employee_rate, employer_rate}]`, `reduces_taxable` | Each slice of the base at its tier's rates |
| `fixed` | `employee_minor` and/or `employer_minor`, `reduces_taxable` when it has an employee share | A fixed amount per employee per month |
| `headcount_rate` | `brackets: [{up_to_employees, rate}]` (employer) | Percent of the base by the company's headcount on the period end |
| `relief_fixed` | `amount_minor` | Subtracted from `applies_to` |
| `relief_rate` | `rate`, `cap_minor` | Percent of its base, capped, subtracted from `applies_to` |
| `reduction_per_dependant` | `rate`, `max_dependants` | `applies_to` reduced by rate % per dependant (up to the max) |
| `allowance_per_child` | `amount_minor`, `taxable`, `contributory` | Paid to the employee per child; added to gross |

Order: earnings in the pack currency → allowances → contributions (on
`gross`, `taxable_gross`, `contributory_gross` or `basic`) → taxable pay
(`taxable_gross` − pre-tax deductions − employee contributions that
`reduces_taxable`) → income tax by bands → reductions per dependant → the
`max_rate` cap → fixed and rate reliefs → floor at zero → rounding. Each
component is computed exactly (decimals) and rounded once by its own rule.
A pack's `non_monthly` value says how monthly figures apply to weekly and
bi-weekly pay (`pro_rata`: × 12 ÷ periods per year); null blocks such runs.

The packs ship the KE components `paye` (bands), `personal_relief`
(relief_fixed), `insurance_relief` (relief_rate, when insurance premiums),
`nssf` (tiers), `shif` (rate), `ahl` (rate), `nita` (fixed, employer); and
the CD components `ipr` (bands with `max_rate`), `ipr_dependants`
(reduction_per_dependant, when dependants), `cnss` (rate, employee and
employer pension), `cnss_risks` and `cnss_family` (rate, employer), `inpp`
(headcount_rate), `onem` (rate, employer) and `family_allowance`
(allowance_per_child, when children). Every value is null; the packs'
`todo` lists each one.

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Money is `bigint` minor units with `currency char(3)`; rates `numeric(18,8)`.

| Table | Holds |
| --- | --- |
| `payroll_settings` | One row per company: multipliers (`numeric(8,4)`), standard monthly hours and days, premium flags |
| `payroll_pay_groups` | company, code, name, frequency, `anchor_date`, `pay_day`, `archived_at` |
| `payroll_items` | company, code, name, kind, `taxable`, `contributory`, `pre_tax`, `insurance_premium`, `archived_at` |
| `payroll_employees` | Payroll's copy of an HR employee (`employee_id`, unique) with number, name, company, branch, location, department, position, `user_id`, hire and termination dates, status, `synced_at`; and the pay setup: `pay_group_id`, `pay_currency`, `payment_method`, `dependants`, `children`, `on_hold` |
| `payroll_contracts` | Copy of HR contracts (`contract_id`, unique): employee, type, start, end, pay basis, `pay_minor`, currency, hours per week, status |
| `payroll_employee_items` | employee, item, `amount_minor`, currency, recurrence (`recurring`, `once`), `from_date`, `to_date`, `period_date`, `consumed_run_id`, `archived_at` |
| `payroll_leave_days` | employee, HR request id, date, fraction, `cancelled_at` |
| `payroll_attendance` | HR period id, employee, company, start and end date, minutes (worked, regular, overtime, night, holiday, rest day), days present, `paid_run_id` |
| `payroll_loans` | employee, kind, principal, currency, disbursed on, instalment, `starts_on`, `settle_next_run`, status, reference, note |
| `payroll_loan_entries` | Append-only: loan, kind (`disbursement`, `repayment`, `settlement`, `reversal`), signed `amount_minor`, run, date, who |
| `payroll_runs` | number, company, pay group, kind, `corrects_run_id`, `correction_mode`, period, pay date, status, `totals` jsonb, `missing` jsonb, who and when calculated, submitted, approved, posted, cancelled |
| `payroll_run_lines` | run, employee, snapshot (number, name, department, branch, location), pay and statutory currency, fx (jsonb), basic, gross, taxable pay, employee statutory, other deductions, loans, net, employer cost, base gross and net, `earnings`, `deductions`, `statutory`, `loans` and `inputs` jsonb, `errors`, `warnings` |
| `payroll_payslips` | run line, run, employee, number, issued at |
| `payroll_returns` | company, return type, period start and end, runs, totals, `needs_confirmation`, generated by and at |

Database rules: posted runs and their lines are immutable
(`payroll_run_locked` trigger); loan entries are append-only.

## Events

**Raised** (`Modules\Payroll\Events`, after commit, ids and values only):

| Event | When | Carries |
| --- | --- | --- |
| `PayrollApproved` | A run's flow approved it | tenant, run id and number, company, kind, period, pay date, totals per currency |
| `PayrollPosted` | A run was posted | tenant, run id and number, company, kind, period, pay date, `groups`: `[{key, department_id, branch_id, currency, fx (pay to base), amounts: {gross, net_pay, statutory.{code}, employer.{code}, deduction.{code}, loans}}]` (signed; a correction's are differences) |
| `PaymentFileGenerated` | A payment file was built | tenant, run id, format, employee count, totals |

**Consumed:**

| Event | From | Effect |
| --- | --- | --- |
| `EmployeeHired`, `EmployeeTerminated` | HR | Payroll's employee copy (with HR's `Employees` contract for names and links) |
| `ContractChanged` | HR | Payroll's contract copy |
| `LeaveApproved`, `LeaveCancelled` | HR | Unpaid leave days |
| `AttendancePeriodClosed` | HR | Minutes per employee and period |
| `WorkflowCompleted`, `WorkflowCancelled` | Core | Settle runs |

**HR read contracts used:** `Employees` (new, non-sensitive basics and the
user link, for the copy and self-service), `PayAccounts` (payment files)
and `Identifiers` (returns). Payroll refreshes its copy for a company
through `Employees` and `EmploymentContracts` before each calculation, so a
module switched on after HR's records exist pays from them.

## Permissions

| Permission | What it allows |
| --- | --- |
| `payroll.settings.view`, `manage` | Company settings, pay groups and pay items |
| `payroll.employee.view`, `manage` | Employees' pay setup and their items |
| `payroll.loan.view`, `manage` | Loans and advances |
| `payroll.run.view`, `prepare`, `approve`, `post` | Runs and lines; create, calculate, submit, cancel, corrections; act on the flow (WF-08); lock and post |
| `payroll.payslip.view` | Payslips of others |
| `payroll.payment.export` | Payment files (and `hr.bank.view`) |
| `payroll.return.view`, `generate` | Statutory returns (generating needs `hr.identity.view`) |

Role templates: **Owner** everything; **Payroll Officer** `payroll.*` (and
HR's employee core views it already holds); **Approver** `*.approve` and
`payroll.run.view`; **Read-only Auditor** `payroll.settings.view` only (pay
is not an auditor's to read). Admin, Accountant and HR Officer hold none.

## API

Prefix `/api/v1`, behind `module:payroll`. Money is `{amount_minor,
currency}`.

| Route | Permission |
| --- | --- |
| `GET/PUT payroll/settings/{company}` | `settings.view` / `manage` |
| `GET/POST payroll/pay-groups`, `PATCH payroll/pay-groups/{payroll_pay_group}`, `POST …/archive` | `settings.view` / `manage` |
| `GET/POST payroll/items`, `PATCH payroll/items/{payroll_item}`, `POST …/archive` | `settings.view` / `manage` |
| `GET payroll/employees`, `POST payroll/employees/sync`, `GET/PATCH payroll/employees/{payroll_employee}` | `employee.view` / `manage` |
| `GET/POST payroll/employees/{payroll_employee}/items`, `PATCH payroll/employee-items/{payroll_employee_item}`, `POST …/archive` | `employee.view` / `manage` |
| `GET/POST payroll/loans`, `GET payroll/loans/{payroll_loan}`, `POST …/settle`, `POST …/cancel` | `loan.view` / `manage` |
| `GET/POST payroll/runs`, `GET payroll/runs/{payroll_run}`, `GET …/lines`, `POST …/calculate`, `/submit`, `/cancel` | `run.view` / `prepare` |
| `POST payroll/runs/{payroll_run}/post` | `run.post` |
| `POST payroll/runs/{payroll_run}/corrections` | `run.prepare` |
| `GET payroll/runs/{payroll_run}/payment-file?format=` | `payment.export` |
| `GET payroll/payment-formats` | `run.view` |
| `GET payroll/payslips`, `GET payroll/payslips/{payroll_payslip}`, `GET …/pdf` | `payslip.view` |
| `GET payroll/returns/types`, `GET/POST payroll/returns`, `GET payroll/returns/{payroll_return}/file` | `return.view` / `generate` |
| `GET payroll/statutory/{company}` | `settings.view`: the pack in force and what is missing |
| `GET payroll/me/payslips`, `GET payroll/me/payslips/{payroll_payslip}`, `GET …/pdf` | the linked employee |

Errors: `statutory_rates_missing`, `payroll_pack_missing`, `run_exists`,
`run_status`, `run_has_errors`, `run_not_posted`, `run_empty`,
`pay_group_other_company`, `item_other_company`, `currency_mismatch`,
`loan_settled`, `loan_balance_short`, `employee_not_in_run`,
`not_an_employee`, `format_unknown`, `field_readonly`, `rate_missing`.

## Screens (web)

Under **Payroll** in the sidebar: Runs (list; a run with lines, totals,
variances, missing rates, calculate, submit, post, payment file,
corrections), Employees (pay setup, items), Loans, Payslips, Statutory
returns, Settings (company settings with multipliers, pay groups, items,
the statutory pack in force with what is missing). **My payslips** in
self-service. Design-system components and tokens only, en/fr.

## Integrations

- **HR → Payroll:** HR's events and read contracts (above).
- **Payroll → Accounting:** `PayrollPosted` (PAY-22, ACC-07). Built in the
  Accounting module (`PayrollSources`), with default rules in the KE and
  CD chart templates.
- **Core:** workflow, numbering, document templates, exchange rates, audit.

## Decisions (recorded for the owner)

1. **Statutory values ship empty.** Every rate, band, relief, cap, tier,
   levy, rounding rule and return layout in the packs is null until the
   owner confirms it with its source; runs are refused until then.
2. **The engine's order is fixed** (above). If a law needs another order
   (for example relief before a cap), the engine gets a new method; the
   order is never a per-tenant setting.
3. **Rates are taken on the pay date** (end of day, company time zone).
4. **Monthly pay is pro-rated by calendar days** employed in the period.
5. **Multipliers are company settings.** Overtime is paid at the
   multiplier; night, holiday and rest-day hours at (multiplier − 1) on
   top of what the basic pays. They stack when hours overlap.
6. **Approval is separate from posting**, so the release of pay is a
   deliberate step after approval.
7. **Bank details and ID numbers are never stored by Payroll**; payment
   files and return files are rebuilt on demand from posted lines and HR's
   contracts, and each build is audited.
8. **One journal per department and currency** of a run (the source
   `payroll.run` with an id derived from the run and the group), so each
   carries its department as a dimension.
9. **Loans are interest-free**; their disbursement is paid outside
   payroll and recorded in Accounting by the accountant.
10. **Daily and hourly pay need attendance**; without it the basic is zero
    with a warning, and a one-off item pays the days.

## Open for the owner

- Every statutory value listed in the packs' `todo`.
- The CD liability accounts for INPP and ONEM and for other deductions.
- The M-Pesa B2C bulk file layout and each statutory return layout.
