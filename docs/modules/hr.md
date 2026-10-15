# HR module spec (phase 7, stage 7C)

Source: concept note sections 5 (HR row: employee records, contracts,
departments and positions, leave, shifts and rosters with swaps, overtime,
night and public-holiday rules, attendance by app, PIN or biometric device,
late and absence tracking, documents, onboarding and offboarding,
disciplinary, appraisals, employee self-service), 6.2 (the employee
onboarding flow), 7.3 (payroll compliance: rates and bands are dated data)
and 7.4 (data protection: encryption in transit and at rest); platform core
spec (TEN-01, TEN-04, TEN-06, TEN-08, AUTH-06, AUTH-12, AUTH-13, RBAC-01 to
RBAC-09, MD-05, WF-01, WF-02, APR-01, APR-02, APR-05, APR-07, CF-01, NUM-01,
LAY-04, AUD-01 to AUD-03, IMP-01 to IMP-03, EXP-01, EXP-02, LIM-01 to
LIM-04, CP-01, CP-02, NFR-04, NFR-08); ADR 002 (RLS), ADR 003 (money), ADR
004 (offline sync), ADR 006 (RBAC), ADR 007 (country packs), ADR 008
(lists), ADR 014 (imports), ADR 018 (usage limits). When this file and the
core spec disagree, the core spec wins.

## Scope

HR is a module (`api/modules/HR`, permissions `hr.*`, catalogue key `hr`),
sold per employee and switched on per tenant (RBAC-08). It works with only
the core installed: it reads core companies, branches, locations, devices,
users, departments (MD-05), the public-holiday calendar (CP-01) and the
workflow engine, and raises its own events. It never reads another module's
tables. Payroll (stage 7C, next) consumes its events; their payloads are
fixed below.

- **An employee is not a user.** An employee is an HR record of a company.
  It may be linked to one core user (AUTH-12: the person then gets
  self-service), and most are not: a cook clocks in with a PIN on the till
  or on a biometric terminal without ever signing in.
- **Employees belong to one company** (the employer, a legal entity) and
  optionally to a branch, a location, a department of that company and a
  position. Their place (location, else branch, else company) is the scope
  every permission is checked at (RBAC-04). TEN-08's `employees` sharing
  setting does not apply: an employment contract is with one company.
- **Personal data is sensitive** (concept note 7.4, NFR-08). Five groups of
  data are shown only to holders of a group permission at the employee's
  place and only when the reader's field rules (RBAC-05) do not hide them:
  identity (ID numbers, date of birth), bank (pay accounts), pay (contract
  pay), medical (medical notes and medical documents) and disciplinary
  (cases and their documents). ID and account numbers and medical notes are
  encrypted at rest; reading sensitive data is audited; writes are audited
  without the sensitive values.
- **Country rules are data.** Identifier types and statutory leave types
  come from the country pack (`api/country-packs/{KE,CD}/hr.json`, CP-01);
  public holidays from the core holiday calendar. Statutory leave days are
  **not** in the pack yet: they are seeded as "needs confirmation" and block
  the actions that depend on them (HR-12). Overtime, night and holiday
  classification thresholds are company data (time rules, HR-20); HR counts
  hours, Payroll applies rates.

## Requirements

| ID | Requirement | Priority |
| --- | --- | --- |
| HR-01 | **Stand-alone module.** HR registers through the core seams (module registry, permission catalogue, role templates, document types and default flows, number formats, custom field entity `hr_employee`, list views, the import framework, sync entities, the approver type `line_manager`, the usage counter of employees) and works with only the core. Every route is behind `module:hr` (403 `module_inactive`). | Must |
| HR-02 | **Employee records.** An employee has a number (HR-36), a company, an optional branch and location (of that company; `place_other_company`), department (a core department of the company, `department_other_company`), position (HR-08) and line manager (`reports_to`, an active employee of the same company, never itself or below itself: `manager_cycle`); first, middle and last name; gender; work email and phone; personal email and phone; an address; emergency contacts (name, relationship, phones; up to 5); hire date; status (`active`, `terminated`); clock-in methods (`web`, `till`, `device`); an optional biometric enrolment reference (unique per company); custom fields (CF-01, entity `hr_employee`). It may be linked to one core user of the tenant (unique; `user_linked`). Employees are never deleted (TEN-06): a leaver is terminated. | Must |
| HR-03 | **Identifiers from the country pack.** The company's country pack lists identifier types (Kenya: `national_id`, `kra_pin`; DR Congo: `national_id`, `cnss_number`), each with an optional format pattern (null until confirmed: no format is enforced then). An employee holds at most one value per type. Values are **encrypted at rest**, unique per company and type (through a keyed digest, `identifier_taken`), returned masked (last 4 characters) to every reader except holders of `hr.identity.view` at the employee's place, and never exported (EXP-02 deny-list). Unknown types for the company's country are refused (`identifier_type_unknown`). | Must |
| HR-04 | **Pay accounts.** Bank and mobile-money accounts for pay: kind (`bank`, `mobile_money`), provider (bank or wallet name), branch code, account name, account number (**encrypted at rest**, masked to its last 4 characters for others), currency, one primary account per employee. Archived, never deleted. Shown in full only with `hr.bank.view`; changed only with `hr.bank.edit`. Payroll will read them through `HR\Contracts\PayAccounts` (HR-33). | Must |
| HR-05 | **Lifecycle.** Hire (create) an employee, terminate (date, reason `resignation`, `dismissal`, `end_of_contract`, `retirement`, `redundancy`, `death`, `other`, note; ends the open contract on that date; unlinks nothing) and rehire (a terminated employee back to active, new hire date). Hire and rehire go through the employee usage guard (HR-32). Events `EmployeeHired` and `EmployeeTerminated` (HR-33). Terminating an employee with an approved or pending leave after the date cancels that leave; leave spanning the date is trimmed (decision 20). | Must |
| HR-06 | **Emergency contacts** are part of the record (HR-02), visible with `hr.employee.view`, editable by the employee in self-service (HR-31). | Must |
| HR-07 | **Contracts.** Type (`permanent`, `fixed_term`, `casual`, `internship`, `consultant`), start and optional end date (required for `fixed_term`), probation end date, position and department at signature, pay basis (`monthly`, `daily`, `hourly`), pay amount (bigint minor units) and currency (ISO 4217, active in the tenant, CUR-01), contracted hours per week. At most one contract in force per employee on any date (`contract_overlap`). Ending a contract sets its end date and reason. `ContractChanged` on create, change and end. Pay is shown only with `hr.pay.view` and changed only with `hr.pay.edit`; it is the reference Payroll starts from, never a payroll calculation. | Must |
| HR-08 | **Departments and positions.** Departments are the core's (MD-05), with their heads. Positions are HR's: per company, a code (unique per company), a title, an optional department and grade, archived not deleted. | Must |
| HR-09 | **Sensitive data by role.** Groups and their permissions at the employee's place: identity (`hr.identity.view`/`edit`: identifiers, date of birth), bank (`hr.bank.view`/`edit`: pay accounts), pay (`hr.pay.view`/`edit`: contract pay), medical (`hr.medical.view`/`edit`: medical notes, medical documents), disciplinary (`hr.disciplinary.view`/`manage`: cases and their documents). On top, field rules (RBAC-05) on resource `hr_employee` (fields `identifiers`, `date_of_birth`, `pay_accounts`, `pay`, `medical_notes`, `disciplinary`, `personal_email`, `personal_phone`, `address`, `emergency_contacts`) hide a field from a role even when its permission is held; hidden fields are left out of API responses, lists, saved list views, exports (EXP-01) and record history, and writing a hidden or read-only field is refused (`field_readonly`). | Must |
| HR-10 | **Encryption at rest.** Identifier values, pay account numbers and medical notes are stored encrypted with the application key; the database never holds them in clear. Uniqueness and lookup use a keyed digest (HMAC-SHA256 under `HR_DIGEST_KEY`, a key separate from the application key, required outside local and testing and checked at boot), never the clear value. | Must |
| HR-11 | **Audit of sensitive data.** Every response that shows a sensitive group in full records `hr.employee.sensitive_view` (the groups and the employee; one entry per employee and request), and every document download `hr.document.download`. Changes are audited (AUD-01) without the sensitive values: identifier, pay account and medical changes record which fields changed (`hr.employee.sensitive_update`), never the values; contract pay changes record `pay_changed`. | Must |
| HR-12 | **Leave types.** Per company: code, name, paid or unpaid, unit days, accrual (`none`, `annual`, `monthly`), entitlement days per year, day count basis (`working_days`, `calendar_days`), carry-over cap (null: no cap, 0: nothing carried), negative balance allowed, whether a document is required, and the statutory key it comes from. **Statutory types come from the country pack** (`statutory_leave_types`, effective-dated): `POST hr/leave-types/apply-pack` copies the missing ones into a company (and the first hire in a company does it). The pack has no confirmed day counts yet, so copied types are **"Days needed"** (`entitlement_days` null, `needs_confirmation`): accrual skips them (reported) and requests of them are refused (`leave_days_needed`) until a holder of `hr.leave.manage` enters and confirms the days, which the company then owns. Days are never guessed. | Must |
| HR-13 | **Entitlement and accrual.** A leave ledger (`hr_leave_entries`), append-only, holds every movement in days (2 decimals): `accrual`, `adjustment`, `taken`, `reversal`, `carry_over`, `expiry`. The balance at a date is the sum of entries effective on or before it; available = balance minus pending requests. Accrual runs daily per tenant (and on demand, `POST hr/leave-accruals/run`), idempotently (one entry per employee, type and accrual key): accrual starts on the type's `accrues_from` date (earlier entitlement is an opening adjustment); `annual` grants the year's entitlement on 1 January (or the first day employed), pro-rated for the days employed in the year as known (to the termination date, else 31 December) ÷ days in the year; `monthly` credits entitlement ÷ 12 at each month end, pro-rated for a partial first or last month (days employed ÷ days in the month); amounts round half up to 2 decimals. At the start of each year, a balance above the type's carry-over cap expires (`expiry` on 1 January). Manual adjustments (signed days, reason) need `hr.leave.manage`. Terminated employees stop accruing after their termination date. | Must |
| HR-14 | **Leave requests with approval.** Employee, type, start and end date, half days at either end, reason. Days are counted on the company's working days (core business hours, closed on public holidays) or calendar days, per the type. Refused when the range overlaps another pending or approved request (`leave_overlap`), when available days are short and the type does not allow negative balances (`leave_balance_short`), when the type needs confirmation (`leave_days_needed`), and outside the employee's employment. A request is the workflow document `hr.leave_request` (WF-01), numbered (HR-36), pending until its flow ends. Default flow (WF-02): one approval by the employee's line manager (APR-02 `line_manager`, registered by HR: the linked user of the `reports_to` employee), escalated to the HR Officers at the employee's place after two business days; no line manager (or one without a user): the node's escalation target at once. Whoever asked on the employee's behalf never approves either (APR-07). The employee never approves their own (APR-07: the requester is the employee's user when linked). Approved: the days are posted as `taken` and `LeaveApproved` is raised. Cancel: pending (its flow cancelled) or approved and not yet ended (the days posted back as `reversal`, `LeaveCancelled`). | Must |
| HR-15 | **Balances and calendar.** Balances per employee and type at a date (entitled this year, taken, pending, balance, available); a leave calendar of pending and approved leave over a date range, filtered by company, branch, department and employee. | Must |
| HR-16 | **Shift patterns.** Shifts (per company: code, name, start and end time, which may cross midnight, unpaid break minutes) and rotations (a cycle of N days, each day a shift or a rest day). Archived, not deleted. | Must |
| HR-17 | **Rotating rosters per branch or department.** A roster names a company, a branch or department (or both), a rotation and its anchor date, and members (employees of the company, each with a day offset into the cycle and an optional period). Generating a roster over a date range (at most 92 days) creates one planned shift per member and working day of the rotation, idempotently: an existing shift of that employee and date (generated, manual or swapped) is kept, a day of approved leave is skipped, a terminated employee gets none after their termination date. Rosters and shifts are edited by holders of `hr.roster.manage` at the place. | Must |
| HR-18 | **Roster grid.** Planned shifts over a week (or any range up to 31 days) by employee and date, filtered by company, branch, department and employee, with each shift's times and whether it is a public holiday. Single shifts are added, moved or cancelled by hand. | Must |
| HR-19 | **Shift swaps with approval.** An employee (or HR for them) asks to swap one of their planned future shifts with a peer's shift, or to give it to a peer. The peer accepts or declines; on acceptance the swap is the workflow document `hr.shift_swap`, by default approved by the Branch Managers at the shift's branch (APR-02 role, escalating to HR Officers after one business day). Approval moves the shifts (`source = swap`) if they are still as they were (`swap_stale` otherwise); a swap never leaves an employee two shifts on one date (`shift_clash`). | Must |
| HR-20 | **Time rules (overtime, night, grace) as data.** Per company, effective-dated rows (`effective_from`, optional `effective_to`, never overlapping): daily overtime after N minutes worked, weekly overtime after N minutes worked in an ISO week, the night window (start and end time, may cross midnight), late and early-leave grace minutes and the weekly rest days. HR classifies worked minutes; Payroll applies the rates. A period cannot close without a time rule in force for every day of it (`time_rules_missing`). | Must |
| HR-21 | **Public holidays from the country pack.** Holiday minutes are worked minutes on a public holiday of the company's country (the core holiday calendar, CP-01, effective-dated rows loaded by `country-packs:holidays`). | Must |
| HR-22 | **Punches.** A punch is an `in` or `out` at an instant, from `web` (self-service), `till` (PIN on a paired POS device), `device` (biometric terminal) or `manual` (HR, with a reason). Punches are append-only; a wrong one is voided (reason), never changed. Uploaded punches carry their own UUID: a punch already held is answered as stored again (idempotent, NFR-04). A punch dated in a closed period is kept but flagged `period_closed` and never changes the closed period. | Must |
| HR-23 | **Clock-in on the till with a PIN.** HR keeps a clock-in PIN per employee (4 to 6 digits; set by `hr.attendance.manage`), stored as the core PIN scheme does (AUTH-06: an Argon2id hash and an encrypted PBKDF2 key, never the PIN). A paired POS device of the tenant receives the employees who clock in at its location (sync entity `hr_clock_staff`, a snapshot: id, name, number, and a verifier under the device's current secret), so clocking in works offline for days; five wrong PINs lock the employee on that device until a new PIN is synced. Punches are queued on the device and uploaded (`POST hr/till/punches`, device token) idempotently. | Must |
| HR-24 | **Biometric device interface.** An attendance device is registered per location (name, an optional vendor label) and gets a bearer token once (shown once, stored hashed, rotatable; suspended or retired devices are refused). The device (or the vendor's bridge) pushes punches (`POST hr/device/punches`: its own event id, the employee's enrolment reference, `in`, `out` or `auto`, the instant): idempotent per device and event id; unknown references are answered `rejected`. No vendor driver is built. `auto` is `out` when the employee's latest punch within the last 16 hours is an `in`, else `in` (see decision 16). Only active employees match an enrolment reference. | Must |
| HR-25 | **Timesheets, lateness and absence.** One day per employee and work date (the date the shift starts, else the punch's local date): first in, last out, worked minutes (paired in and out punches, minus the shift's unpaid break), regular and overtime minutes (daily, then weekly threshold), night minutes (inside the night window), holiday minutes, rest-day minutes, late and early-leave minutes against the planned shift (beyond the grace), and the day's status (`present`, `absent`, `leave`, `holiday`, `incomplete`; a rest day without punches has no day row, and time worked on a rest day counts as rest-day minutes) with exceptions (`late`, `early_leave`, `missing_in`, `missing_out`, `absent`, `unplanned`, `no_time_rule`). The shift's unpaid break is deducted when the time worked is longer than it. Recomputed whenever its punches, shift or leave change, unless approved or in a closed period. Days are approved by `hr.attendance.manage`; an approved day can be reopened until its period closes. | Must |
| HR-26 | **Attendance periods and close.** Per company, non-overlapping periods. Closing needs `hr.attendance.close`, a time rule in force (HR-20), every day with planned shifts or punches approved (`days_unapproved`, with the count) and the tills' offline window over: 7 days after the end date in the company's time zone (`period_offline_window`), unless forced with a reason (decision 25). It freezes the period's days and raises `AttendancePeriodClosed` with the totals per employee (HR-33). A closed period is never reopened by HR (Payroll may have used it); corrections are punches in the next period. | Must |
| HR-27 | **Documents with restricted access.** Files on an employee (category `contract`, `identity`, `certificate`, `medical`, `disciplinary`, `other`, a title, an optional expiry date), stored through the core file storage (the `media` disk, never public, the custom field file types and size). Category decides who sees it: `contract` needs `hr.pay.view`, `identity` `hr.identity.view`, `medical` `hr.medical.view`, `disciplinary` `hr.disciplinary.view`, others `hr.document.view`. Downloads go through a temporary signed URL bound to the reader, checked again on download and audited (HR-11). Archived, never deleted. | Must |
| HR-28 | **Onboarding and offboarding checklists.** Templates per company and kind (`onboarding`, `offboarding`) with ordered items (title, days after the start, optional responsible role key). A checklist starts from a template on an employee (and automatically on hire or termination when the company has a default template of that kind); items are completed (who, when, note) or reopened; the checklist completes when every item is done. | Must |
| HR-29 | **Disciplinary records.** Cases on an employee: number, category (`verbal_warning`, `written_warning`, `final_warning`, `suspension`, `dismissal`, `other`), incident date, description, action taken, status (`open`, `closed`), outcome, closed by and at. Seen and changed only with `hr.disciplinary.view` / `hr.disciplinary.manage` at the employee's place (and not hidden by field rules, field `disciplinary`). | Must |
| HR-30 | **Appraisals.** Cycles per company (name, period, status `draft`, `open`, `closed`). Opening a cycle creates one appraisal per active employee hired before its end, reviewed by their line manager's user. An appraisal holds goals (title, weight, rating 1 to 5, comment), the employee's self review and comments, the manager's ratings and comments and an overall rating (weighted mean of goal ratings, 2 decimals), status `self_review` → `manager_review` → `completed` → `acknowledged`. | Should |
| HR-31 | **Employee self-service.** A signed-in user linked to an active employee sees their own profile (their identifiers and pay accounts masked, never in full), edits their personal contacts, address and emergency contacts, sees their leave balances and requests, asks for and cancels leave, sees their roster and attendance, clocks in and out on the web when `web` is one of their clock-in methods, asks for and answers shift swaps, and fills in their self review. Being the linked employee is the authorisation: no role is needed (AUTH-12). Payslips come with Payroll. | Must |
| HR-32 | **Usage limits.** HR binds the core employee count (LIM-01): active (not terminated) employees of the tenant. Hiring and rehiring call `UsageLimits::ensureCanAdd('employee')` in the transaction that adds the employee (LIM-03); nothing else in HR consults the limits (LIM-04: clocking in, leave, rosters and period close are never blocked). | Must |
| HR-33 | **Events for Payroll.** `EmployeeHired`, `EmployeeTerminated`, `ContractChanged`, `LeaveApproved`, `LeaveCancelled`, `AttendancePeriodClosed`, dispatched after commit, ids and values only (payloads below). | Must |
| HR-34 | **Employees import.** The core import framework (IMP-01 to IMP-03) gets an `employees` importer: company, number (optional: assigned when empty), names, gender, work and personal contacts, hire date, branch, location, department (by code or name), position (by code), line manager (by number), clock-in methods and custom fields; matched by number in update mode. Each row passes the API's own Form Request and the hire path (usage guard included); undo terminates nothing and archives nothing: a created employee is undone only if nothing depends on it (no contract, leave, punch or shift), by marking it terminated on its hire date with reason `other`. Sensitive data (identifiers, accounts, pay) is not imported. | Must |
| HR-35 | **Lists and exports** (EXP-01, LAY-04): employees, positions, contracts, leave requests, balances, roster shifts, punches, timesheets, attendance periods, documents, disciplinary cases and appraisals with search, filters, sort, saved views and export; the employee list's sensitive columns (date of birth, pay) follow HR-09. | Must |
| HR-36 | **Numbering** (NUM-01): employees `EMP-{00001}` (never reset, per company), leave requests `LV-{YYYY}-{00001}` and disciplinary cases `DC-{YYYY}-{00001}` (yearly). | Must |
| HR-37 | **Audit** (AUD-01): every employee, contract, pay account, position, leave type, leave request and adjustment, roster and shift, swap, punch (voids), time rule, period close, device, document, checklist, case and appraisal created, changed, decided or archived. The leave ledger and the punches are themselves append-only records. | Must |
| HR-38 | **Scope.** Every list, record and action is scoped by the employee's place (RBAC-04): an employee out of the reader's places is not found, and neither is anything of theirs. Company-level setup (positions, leave types, shifts, rotations, rosters, time rules, periods, checklist templates, appraisal cycles) is scoped by its company (rosters by their branch when they have one). | Must |

## Data model

Every table has `tenant_id` under forced RLS (TEN-01) and UUID v7 keys.
Money is `bigint` minor units with `currency char(3)` (ADR 003). Leave days
are `numeric(8,2)`.

| Table | Holds |
| --- | --- |
| `hr_positions` | company, code, title, department, grade, `archived_at` |
| `hr_employees` | number, company, branch, location, department, position, `reports_to_id`, `user_id` (unique), names, gender, `date_of_birth`, work and personal email and phone, `address` jsonb, `emergency_contacts` jsonb, `medical_notes` (encrypted), hire date, status, termination date, reason and note, `clock_methods` jsonb, `biometric_ref`, `custom` jsonb |
| `hr_employee_identifiers` | employee, `type` (pack key), `value` (encrypted), `value_hint` (last 4), `value_digest` (keyed digest; unique per company and type) |
| `hr_pay_accounts` | employee, kind, provider, branch code, account name, `account_number` (encrypted), `account_hint`, currency, `is_primary`, `archived_at` |
| `hr_contracts` | employee, type, start and end date, probation end, position, department, pay basis, `pay_minor`, `currency`, hours per week, end reason, status (`active`, `ended`) |
| `hr_leave_types` | company, code, name, `statutory_key`, paid, accrual, `entitlement_days`, `needs_confirmation`, `count_basis`, `carry_over_max_days`, `allow_negative`, `requires_document`, `accrues_from`, `archived_at` |
| `hr_leave_entries` | Append-only ledger: employee, leave type, kind, signed `days`, `effective_on`, `key` (unique per employee and type: accrual and expiry runs and requests post once), source request, note, who |
| `hr_leave_requests` | number, employee, company, branch, location, leave type, dates, half days, `days`, reason, status (`pending`, `approved`, `rejected`, `cancelled`), requester, decider and time, canceller, time and reason |
| `hr_shifts` | company, code, name, `start_time`, `end_time`, `break_minutes`, `archived_at` |
| `hr_rotations` | company, name, `cycle_days`, `days` jsonb (shift id or null per day), `archived_at` |
| `hr_rosters` | company, branch, department, name, rotation, `anchor_date`, `archived_at` |
| `hr_roster_members` | roster, employee, `offset_days`, `from_date`, `to_date` |
| `hr_roster_shifts` | employee, company, branch, location, date, shift, `start_at`, `end_at`, `break_minutes`, `source` (`generated`, `manual`, `swap`), roster, status (`planned`, `cancelled`); one planned shift per employee and date |
| `hr_shift_swaps` | requester employee, roster shift, peer employee, peer roster shift (null: a give-away), reason, status (`awaiting_peer`, `pending`, `approved`, `rejected`, `declined`, `cancelled`), who asked, peer's answer and time, decider and time |
| `hr_time_rules` | company, `effective_from`, `effective_to`, `daily_overtime_after_minutes`, `weekly_overtime_after_minutes`, `night_start`, `night_end`, `late_grace_minutes`, `early_leave_grace_minutes`, `rest_days` jsonb |
| `hr_punches` | Append-only (a void is written once): employee, company, `kind` (`in`, `out`), `occurred_at`, `source`, POS `device_id`, `attendance_device_id`, `external_id` (unique per attendance device), location, note, who recorded it, `flags`, `received_at`, `voided_at`, `voided_by`, `void_reason` |
| `hr_attendance_days` | employee, company, `work_date` (unique per employee), roster shift, `first_in`, `last_out`, minutes (`worked`, `regular`, `overtime`, `night`, `holiday`, `rest_day`, `late`, `early_leave`), status, `exceptions` jsonb, `approved_at`, `approved_by`, period |
| `hr_attendance_periods` | company, `start_date`, `end_date` (never overlapping per company), status (`open`, `closed`), closer and time, `totals` jsonb |
| `hr_clock_pins` | employee (unique), the PIN's Argon2id hash, salt, iterations and encrypted key (Pins' scheme), digits, `version`, set by and at |
| `hr_attendance_devices` | company, location, name, vendor label, status (`active`, `suspended`, `retired`), last seen; its tokens are core personal access tokens (ability `hr.attendance_device`) |
| `hr_documents` | employee, category, title, `expires_on`, disk, path, file name, mime, size, uploader, `archived_at` |
| `hr_checklist_templates` | company, kind, name, `is_default`, `items` jsonb, `archived_at` |
| `hr_checklists` | employee, kind, template, name, status (`open`, `completed`), completed at |
| `hr_checklist_items` | checklist, position, title, `due_on`, responsible role key, done by and at, note |
| `hr_disciplinary_cases` | number, employee, company, category, incident date, description, action taken, status, outcome, closer and time, who opened it |
| `hr_appraisal_cycles` | company, name, `period_start`, `period_end`, status |
| `hr_appraisals` | cycle, employee, reviewer user, goals jsonb, self rating and comments, manager rating and comments, `overall_rating numeric(4,2)`, status, completed and acknowledged at |

## Events

**Raised** (`Modules\HR\Events`, after commit, ids and values only). Payroll
listens to them when both modules are active.

| Event | When | Carries |
| --- | --- | --- |
| `EmployeeHired` | An employee is created or rehired | tenant, employee id and number, company, branch, location, department, position, hire date, `rehired` |
| `EmployeeTerminated` | An employee is terminated | tenant, employee id and number, company, termination date, reason |
| `ContractChanged` | A contract is created, changed or ended | tenant, contract id, employee id, company, `change` (`created`, `updated`, `ended`), type, start and end date, probation end, pay basis, `pay_minor`, currency, hours per week, position, department |
| `LeaveApproved` | A leave request's flow approved it | tenant, request id and number, employee id, company, leave type id and code, `paid`, start and end date, half days, `days`, `dates` (each day counted, with its fraction) |
| `LeaveCancelled` | An approved leave is cancelled | tenant, request id, employee id, company, leave type id, `days`, `dates` |
| `AttendancePeriodClosed` | A period is closed | tenant, period id, company, start and end date, time zone, closer, `employees`: `[{employee_id, days_present, days_absent, days_leave, worked_minutes, regular_minutes, overtime_minutes, night_minutes, holiday_minutes, rest_day_minutes, late_minutes, early_leave_minutes}]` |

**What Payroll needs from them.** Payroll keeps its own copy of what it
pays from: the employee's company and number on hire (and stops paying from
the termination date), the contract in force for a pay date (pay basis,
amount and currency: the base salary, daily or hourly rate), unpaid leave
days by date (paid leave changes nothing), and per closed period the
overtime, night, holiday and rest-day minutes it multiplies by its own
rates (statutory or contractual, Payroll's country-pack data). Payroll
reads pay accounts and identifiers through `Modules\HR\Contracts\PayAccounts`
and `Modules\HR\Contracts\Identifiers` (decrypted, for the payment file
and statutory returns), which check `hr.bank.view` / `hr.identity.view` of
the acting user or run as the system inside a payroll run. Stage 7C adds two
read contracts for Payroll's copy: `Modules\HR\Contracts\Employees`
(non-sensitive basics: number, name, company, place, department, position,
the linked user, hire and termination dates, status; and the employees
linked to a user, for "My payslips") and
`Modules\HR\Contracts\EmploymentContracts` (a company's contracts with
their pay, read inside a payroll run and audited as
`hr.contract.payroll_read`), so a Payroll switched on after HR's records
exist, or a change HR raises no event for (a name, a department), still
reaches Payroll without it reading HR's tables.

**Consumed:**

| Event | From | Effect |
| --- | --- | --- |
| `WorkflowCompleted`, `WorkflowCancelled` | Core | settle leave requests and shift swaps |

## Permissions

| Permission | What it allows |
| --- | --- |
| `hr.employee.view`, `create`, `edit`, `terminate` | Employee records (non-sensitive), hire, change, terminate and rehire, set clock-in PINs |
| `hr.employee.link` | Link or unlink a user (self-service); nobody links themselves (`link_self`). HR Officer and Owner only |
| `hr.identity.view`, `edit` | Identifiers and date of birth |
| `hr.bank.view`, `edit` | Pay accounts |
| `hr.pay.view`, `edit` | Contract pay; contract documents |
| `hr.medical.view`, `edit` | Medical notes and documents |
| `hr.contract.view`, `manage` | Contracts (pay follows `hr.pay.*`) |
| `hr.position.view`, `manage` | Positions |
| `hr.leave.view`, `request`, `approve`, `manage` | Leave requests and balances; asking on behalf; acting on the flow (WF-08); types, adjustments, accruals |
| `hr.roster.view`, `manage` | Shifts, rotations, rosters and planned shifts; swaps |
| `hr.swap.approve` | Acting on the swap flow |
| `hr.attendance.view`, `manage`, `close` | Punches, timesheets and periods; manual punches, voids, approvals, time rules, clock-in PINs; closing periods |
| `hr.device.manage` | Attendance devices and their tokens |
| `hr.document.view`, `manage` | Documents of the general categories; uploading and archiving (each category's own view permission applies too) |
| `hr.checklist.view`, `manage` | Checklists and their templates |
| `hr.disciplinary.view`, `manage` | Disciplinary cases |
| `hr.appraisal.view`, `manage` | Appraisal cycles and appraisals |

Role templates (RBAC-03): **Owner** everything; **HR Officer** `hr.*`;
**Admin** `hr.*.view` except the five sensitive views; **Branch Manager**
employees, positions and contracts (view), leave (view, request, approve),
rosters (all), swaps, attendance (view, manage), checklists (view) and
appraisals (view, manage); **Payroll Officer** employees, contracts, leave
and attendance (view) and identity, bank and pay (view); **Approver**
`*.approve` and `hr.leave.view`; **Read-only Auditor** `*.view` except the
five sensitive views. Self-service needs no permission (HR-31).

## API

Prefix `/api/v1`, every route behind `module:hr`, except the employee core (employees, terminate, rehire, identifiers, pay accounts, contracts, positions, `hr/me`), which is behind `module:hr,payroll` (decision B). People's tokens unless
marked. Lists follow ADR 008 (`?search`, `?sort`, `?per_page`, `?page`,
`?format=csv|xlsx|pdf`, `?columns[]`, `?company`, `?branch`, `?location`,
`?department`, `?employee`, `?from`, `?to`, `?status`). Money is
`{amount_minor, currency}`; days are decimal strings.

| Route | Permission |
| --- | --- |
| `GET/POST hr/employees`, `GET/PATCH hr/employees/{hr_employee}` | `employee.view` / `create` / `edit` (sensitive keys need their group's edit) |
| `POST hr/employees/{hr_employee}/terminate`, `/rehire` | `employee.terminate` |
| `PUT hr/employees/{hr_employee}/identifiers` | `identity.edit` |
| `GET/POST hr/employees/{hr_employee}/pay-accounts`, `PATCH hr/pay-accounts/{hr_pay_account}`, `POST …/archive` | `bank.view` / `bank.edit` |
| `PUT hr/employees/{hr_employee}/clock-pin`, `POST …/clock-pin/clear` | `attendance.manage` |
| `GET/POST hr/employees/{hr_employee}/contracts`, `PATCH hr/contracts/{hr_contract}`, `POST …/end` | `contract.view` / `contract.manage` |
| `GET/POST hr/positions`, `PATCH hr/positions/{hr_position}`, `POST …/archive`, `/restore` | `position.view` / `manage` |
| `GET/POST hr/employees/{hr_employee}/documents`, `GET hr/documents/{hr_document}/link`, `POST …/archive` | `document.*` and the category's view |
| `GET/POST hr/leave-types`, `PATCH hr/leave-types/{hr_leave_type}`, `POST …/archive`, `POST hr/leave-types/apply-pack` | `leave.view` / `leave.manage` |
| `GET hr/leave-balances`, `POST hr/leave-adjustments`, `POST hr/leave-accruals/run` | `leave.view` / `leave.manage` |
| `GET/POST hr/leave-requests`, `GET hr/leave-requests/{hr_leave_request}`, `POST …/cancel`, `GET hr/leave-calendar` | `leave.view` / `leave.request` |
| `GET/POST hr/shifts`, `PATCH hr/shifts/{hr_shift}`, `POST …/archive`; same for `hr/rotations` | `roster.view` / `manage` |
| `GET/POST hr/rosters`, `GET/PATCH hr/rosters/{hr_roster}`, `PUT …/members`, `POST …/generate` | `roster.view` / `manage` |
| `GET/POST hr/roster-shifts`, `PATCH hr/roster-shifts/{hr_roster_shift}`, `POST …/cancel` | `roster.view` / `manage` |
| `GET/POST hr/shift-swaps`, `GET hr/shift-swaps/{hr_shift_swap}`, `POST …/accept`, `/decline`, `/cancel` | `roster.view` / `roster.manage` (the peer accepts in self-service) |
| `GET/POST hr/time-rules`, `PATCH hr/time-rules/{hr_time_rule}` | `attendance.view` / `manage` |
| `GET/POST hr/punches`, `POST hr/punches/{hr_punch}/void` | `attendance.view` / `manage` |
| `GET hr/timesheets`, `POST hr/timesheets/approve`, `POST hr/timesheets/reopen` | `attendance.view` / `manage` |
| `GET/POST hr/attendance-periods`, `GET hr/attendance-periods/{hr_attendance_period}`, `POST …/close` | `attendance.view` / `manage` / `close` |
| `GET/POST hr/attendance-devices`, `POST hr/attendance-devices/{hr_attendance_device}/token`, `/suspend`, `/resume`, `/retire` | `device.manage` |
| `POST hr/till/punches` (POS device token) | the device's location |
| `POST hr/device/punches` (attendance device token) | the device |
| `GET/POST hr/checklist-templates`, `PATCH hr/checklist-templates/{hr_checklist_template}`, `POST …/archive` | `checklist.view` / `manage` |
| `GET hr/checklists`, `POST hr/employees/{hr_employee}/checklists`, `POST hr/checklist-items/{hr_checklist_item}/complete`, `/reopen` | `checklist.view` / `manage` |
| `GET/POST hr/disciplinary-cases`, `GET/PATCH hr/disciplinary-cases/{hr_disciplinary_case}`, `POST …/close` | `disciplinary.view` / `manage` |
| `GET/POST hr/appraisal-cycles`, `PATCH hr/appraisal-cycles/{hr_appraisal_cycle}`, `POST …/open`, `/close` | `appraisal.view` / `manage` |
| `GET hr/appraisals`, `GET/PATCH hr/appraisals/{hr_appraisal}`, `POST …/complete` | `appraisal.view` / the reviewer or `appraisal.manage` |
| `GET/PATCH hr/me`, `GET hr/me/leave`, `POST hr/me/leave-requests`, `POST hr/me/leave-requests/{hr_leave_request}/cancel`, `GET hr/me/roster`, `GET hr/me/attendance`, `POST hr/me/clock`, `GET/POST hr/me/swaps`, `GET hr/me/swap-peers` (colleagues of the same company and branch with their planned shifts of the next 31 days), `POST hr/me/swaps/{hr_shift_swap}/accept`, `/decline`, `GET hr/me/appraisals`, `PATCH hr/me/appraisals/{hr_appraisal}`, `POST …/acknowledge` | the linked employee (HR-31) |
| `GET hr-files/{path}` (public, signed) | the reader the URL was signed for, checked again |

Errors: `place_other_company`, `department_other_company`,
`position_other_company`, `manager_cycle`, `manager_other_company`,
`user_linked`, `employee_terminated`, `employee_active`,
`identifier_type_unknown`, `identifier_format`, `identifier_taken`,
`contract_overlap`, `leave_days_needed`, `leave_balance_short`,
`leave_overlap`, `leave_outside_employment`, `document_not_open`,
`shift_clash`, `swap_stale`, `time_rules_missing`, `time_rule_overlap`,
`period_overlap`, `period_closed`, `period_offline_window`, `days_unapproved`, `clock_method_off`,
`not_an_employee`, `device_inactive`, `field_readonly`.

## Screens (web)

Under **People** in the sidebar: Employees (list; profile with tabs
Overview, Personal, Identity, Pay accounts, Contracts, Leave, Attendance,
Documents, Checklists, Disciplinary, Appraisals), Positions, Leave
(requests, approvals through the inbox, balances, calendar, types),
Rosters (week grid, rosters, shifts and rotations, swaps), Attendance
(timesheets with exceptions, punches, periods, time rules, devices),
Onboarding and offboarding (checklists and templates), Disciplinary,
Appraisals, and **My HR** (self-service: profile, leave, roster,
attendance with the clock button, swaps, appraisals). Design-system
components and tokens only, en/fr, back-office list patterns (ADR 008).

**POS app:** a staff clock-in screen reached from the sign-in screen:
choose your name, type your PIN, see "Clocked in at 08:02". Works offline;
punches upload with the till's other records.

## Integrations

- **HR → Payroll**: the events above, and the two read contracts.
- **HR → Accounting**: none (Payroll posts payroll journals).
- **POS**: none; HR uses the core device, PIN scheme and sync framework, not
  POS's tables.

## Open questions and decisions taken

Decisions taken while building (no owner input asked, per instructions):

1. **Till clock-in uses HR's own clock-in PIN, built on the core PIN
   scheme**, not the till sign-in PIN: most staff who clock in are not
   users and never sell, and the till's staff list (`pos.till.sign_in`
   holders) must stay the list of people who may sell. The scheme, the
   per-device verifiers and the device secret are the core's (AUTH-06).
2. **Biometric devices are HR attendance devices, not core devices**, so
   they never count or bill as POS devices (LIM-01). Their tokens are core
   personal access tokens; the core token check accepts any tokenable that
   says whether it is active (a small core change).
3. **Statutory leave days are not seeded.** The packs list the statutory
   types; their days are null and "Days needed" until confirmed (HR-12).
4. **HR counts, Payroll pays.** Time rules classify minutes; no multiplier
   or premium rate lives in HR.
5. **Emergency contacts are part of the employee record** (jsonb), not a
   table: they are never referenced elsewhere.
6. **Self-service is authorised by the link**, not a permission (HR-31).
   The `employee_self_service` template keeps its core permissions.
7. **Sensitive values are left out of the audit log** (they are recorded
   as "changed"), so audit readers (`core.audit.view`) never see them.
8. **Encrypted columns are denied in the full export (EXP-02)**, as the
   core does for encrypted phone numbers: the export would need the
   application key. Masked hints are exported.
9. **Leave counting** is set by decision C below (superseded).
10. **A closed attendance period is final in HR.** Late punches are kept
    and flagged for the next period.
11. **Linking a user is manual** (HR picks an existing user of the tenant);
    the web offers the core invitation with the Employee self-service role.
12. **Leave types that do not accrue (`accrual = none`)** keep no balance: a request is limited to the type's days (when set).
13. **Clock-in PIN lockouts live on the till** (five wrong PINs lock that employee on that device until a new PIN syncs). The till reports failure counts and lockouts (`POST hr/till/clock-attempts`); each report is audited (`hr.employee.clock_pin_failures`, `hr.employee.clock_pin_locked`) and a lockout alerts the holders of `hr.attendance.manage` at the employee's place (`hr.clock_pin.locked`). A clock-in PIN equal to the linked user's till PIN is refused (`clock_pin_same_as_till`). **The key control against PIN guessing on a stolen or abused till is suspending the device** (core devices screen): a suspended device's token is refused at once, so it can upload no more punches.
14. **Employee imports carry no sensitive data** (identifiers, accounts,
    pay): those are entered in the app, one group permission at a time.

15. **Punch bounds.** A punch before the hire date or after the termination
    date is refused (`punch_outside_employment`). Every instant carries an
    explicit UTC offset. Till and device punches must lie between now
    minus 7 days (the offline window, NFR-04) and now plus 1 hour
    (`punch_out_of_window`, rejected per row); a manual punch may not be
    later than now plus 1 hour. A queued till or device punch of an
    employee who has since moved is accepted when the device's location
    was theirs on the punch's date (`hr_employee_places`). Each punch
    stores its `work_date` when recorded; an out pairs with the open in
    (the latest punch within 16 hours, when it is an in).
16. **Next punch kind (owner-changeable).** The till, the device `auto`
    kind and the web clock all take the employee's latest punch within the
    last 16 hours: an `in` makes the next punch `out`, anything else `in`.
    This differs from HR-24's "previous punch of the day", which breaks
    night shifts; the owner may change it back. The till ignores its own
    punches the server rejected.
17. **Closed periods are final.** A period cannot close before its end
    date has passed in the company's time zone (`period_not_ended`).
    Planned shifts in a closed period cannot be created, changed or
    cancelled, by hand, by the generator (it skips them and counts them as
    `closed`) or by swaps (`period_closed`). Recompute and approval lock
    the covering period row.
18. **Rosters and swaps by branch.** A roster member from another branch,
    and a swap peer outside the shift's branch, need `hr.roster.manage` at
    their place (`place_forbidden`). A swap takes its company, branch and
    location from the shift; accepting or declining is checked at the
    peer's place; changing a roster's branch needs the permission at the
    new branch. A swap is stale (`swap_stale`) when either shift changed
    since it was asked (shift `version`), has started, or lies in a closed
    period. Generating a roster keeps every existing row, so a shift a
    manager cancelled stays cancelled; a second run at the same time is
    answered `roster_busy` (409).
19. **Leave balances.** The available balance counts credits effective on
    or before the date, plus every debit whatever its date, minus pending
    requests. Accrual keys do not depend on the accrual mode (a type moved
    from annual to monthly never accrues twice in a year), the year-end
    expiry is corrected when December entries are posted late, accruals
    cannot run for a future date, and the employee is checked active
    inside the lock. Approving or cancelling leave recomputes the
    timesheet days it covers.
20. **Backdated termination.** Terminating on a past date withdraws
    approved or pending leave that starts after it, trims leave spanning
    it (only the days after it are reversed) and keeps leave wholly before
    it. Self-service and "own" requests need an active employee.
21. **Identifiers are saved per type.** A type left out of the request, or
    sent without a value, keeps its value; `remove: true` deletes it.
22. **Sensitive reads by other modules.** `HR\Contracts\PayAccounts` and
    `HR\Contracts\Identifiers` answer only for a user with `hr.bank.view` or
    `hr.identity.view` at the employee's place, or inside a payroll run
    (`forPayrollRun`); each read is audited (`hr.employee.sensitive_read`).
23. **Record history.** `hr_employee` and `hr_disciplinary_case` are history
    types. The employee history hides fields the reader's field rules hide
    and every sensitive value; disciplinary history needs
    `hr.disciplinary.view`. Downloading a document is audited when the file
    is served, through a link valid for 5 minutes.
24. **`HR_DIGEST_KEY` rotation.** To rotate: set the new key, run
    `php artisan hr:rekey-digests` (it recomputes every identifier digest,
    tenant by tenant, under the new key), then deploy. Until the command
    has run, uniqueness checks miss existing values, so run it in the same
    maintenance window. Losing the key loses nothing (the values are
    encrypted under the application key); rerun the command with a new
    one.
25. **Offline punches before a period closes (decision, owner may
    change).** Till and device punches may arrive up to 7 days late
    (decision 15, NFR-04), so a period cannot close until 7 days after its
    end date in the company's time zone (`period_offline_window`, 422,
    with `window_ends`). HR may force the close sooner with `force: true`
    and a `reason` (required), audited as
    `hr.attendance_period.force_close` with the reason; punches arriving
    later are flagged `period_closed` as usual. The web close dialog
    offers the forced close with a reason when that error comes back.
26. **Punch uploads recompute once (decision, owner may change).** A till
    or device upload (`hr/till/punches`, `hr/device/punches`, up to 500
    punches) stores every punch first, then recomputes each touched
    (employee, work date) day and the later days of each touched ISO week
    (weekly overtime) once, in date order, instead of once per punch
    (HR-22, HR-25). A test holds a 500-punch upload under a fixed query
    budget.
27. **Personal contact details stay out of the audit log (decision, owner
    may change).** Personal email, personal phone, address and emergency
    contacts are left out of the audit values like the sensitive fields
    (decision 7): a change to one is recorded by name in
    `hr.employee.sensitive_update`, so audit readers and the record
    history never see the values. Work email and work phone stay audited.
28. **`hr:rekey-digests` reaches every tenant (decision, owner may
    change)** holding an identifier, suspended and closed ones included
    (`app_tenants_with_hr_identifiers`, ids only, ADR 002), and a
    malformed `HR_DIGEST_KEY` (`base64:` that is not valid base64, or
    under 32 bytes either way) fails the boot check (EnvironmentGuard) and
    is never used to digest.
29. **Time rules and approvals recompute (decision, owner may change).**
    Saving a time rule (create or update) recomputes the timesheet days of
    its dates, before and after the change, up to today, that are not
    approved and not in a closed period. Approving days recomputes each
    open one first (with the later days of its week, in date order), so an
    approval never freezes stale minutes; a day left with nothing to show
    is removed and not approved.

Owner decisions (2026-10-14):

- **A. User limit.** Self-service sign-ins do not count toward the user
  limit (LIM-01): only active users with an assignment to a role that has
  at least one back-office permission count.
- **B. Payroll without HR.** Payroll sold alone includes HR's employee
  records: employees, contracts, pay accounts, identifiers and positions.
  It does not include leave, rosters, attendance, documents, checklists,
  disciplinary cases or appraisals. The employee core's routes and
  permissions open when `hr` or `payroll` is active.
- **C. Leave for rostered staff.** A member of an active roster uses only
  the days they are rostered to work: a day with a planned shift counts,
  a day already planned without one does not, and a day not planned yet
  counts when the roster's rotation has a shift then. Office staff use the company calendar's
  working days. Calendar-day types are unchanged.

Open for the owner:

- Statutory leave days per country and their legal references (Kenya:
  annual, maternity, paternity, sick, compassionate; DR Congo: annual
  (congé annuel), maternity, sick, circumstantial): see the packs' `todo`.
- Identifier formats (KRA PIN, Kenyan national ID, DRC national ID, CNSS
  number): the packs enforce none until confirmed.
- Decision 16 (next punch kind within 16 hours) against HR-24.
