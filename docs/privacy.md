# Data protection (NFR-08)

Kenya's Data Protection Act 2019 and the DRC's rules give people the right to see, correct and delete the personal data a business holds about them, and require consent for direct marketing. Each tenant is the data controller for its customers, employees and users; the platform is its processor. This is how the platform helps a tenant meet those duties. It is not legal advice; the points under "To confirm" need the owner's counsel.

## Data subject requests (Settings → Privacy)

A request is about one person in the tenant: a **customer** (a party), an **employee** (HR) or a **user**. Each module registers where its personal data lives (`PrivacySubjects`): core registers parties and users, HR its employees, Payroll its copy of an employee, CRM the leads converted to a customer, E-commerce the storefront accounts and, for a rider (a user), their delivery trail, the last positions on their deliveries and their rider record (ECOM-40).

| Kind | What carrying it out does |
| --- | --- |
| Access | Makes the access report available: the person's rows in the tables their modules **declared** as being about them (a customer's sales, loyalty, invoices, CRM opportunities and storefront orders; an employee's leave, attendance, documents and payslips; a user's sign-ins, role assignments and own employee record), at most 500 per table with full counts. Nothing is found by following foreign keys, so rows someone merely touched for others (sales rung up as cashier, leads they own, appraisals they reviewed, approvals, custody) never appear. The tenant export's column rules apply (no password or PIN material, secrets, tokens, or encrypted ID, account and medical values), and HR's sensitive groups the downloader may not see are masked (below). The report is built when downloaded (JSON), never stored, and each download is audited. |
| Correction | Applies the approved new values of correctable fields (names, tax ID, contact details; lists such as phones are edited on the record). Audited with the values before and after, like any edit. |
| Deletion | Anonymises the person (below), because business records are never hard-deleted (TEN-06). |

### Flow

1. **Received**: someone with `core.privacy_request.manage` logs it, noting how it arrived and how the person's identity was checked. The deadline is set from the subject's company country (`config/privacy.php`).
2. **Approved or rejected** by someone with `core.privacy_request.approve` who did not log it (four eyes). A rejection keeps its reason.
3. **Completed**: an approved request is carried out by someone with `manage`.
4. Requests past their deadline and still open show as **Overdue**.

Every step is audited (AUD-01). Permissions: `core.privacy_request.view`, `.manage`, `.approve` and `core.consent.view`, `.manage`, all at tenant scope; Owner and Admin hold them. Whoever downloads an access report sees everything about that person, medical notes included for an employee: give `manage` sparingly.

### Employees: HR's sensitive groups (HR-09, HR-11, RBAC-05)

The person downloading an employee's report sees each sensitive group (identity, bank, pay, medical, disciplinary) only with its HR view permission at the employee's place and when their field rules do not hide it; otherwise those fields read `[hidden]` (dates of birth, ID and account hints, account names) or the rows keep only their id and the group (contracts, disciplinary cases, documents of that category, Payroll's payslips, run lines, contracts, loans and items). Groups shown in full are audited as `hr.employee.sensitive_view` with the context `privacy_access_report`. Encrypted values (ID numbers, account numbers, medical notes) are never in a report; an HR officer shows them from the employee record when the person asks.

### Users: user management rules apply (AUTH-13, RBAC-10)

Asking for and carrying out a user's deletion each need `UserPolicy::deactivate` over that user (an Admin cannot delete an Owner), and the deactivation is audited as `core.user.deactivate`, as from the users screen.

### What anonymisation changes and keeps

| Subject | Replaced | Kept, and why |
| --- | --- | --- |
| Customer (party) | name → "Anonymised XXXXXXXX"; legal name, tax ID cleared; phones, emails, addresses, custom fields emptied; the party archived. CRM leads linked to it: name, email, phone, organisation, title, notes, custom fields. Storefront account: name, email, phone; archived | sales, invoices, payments, journals, loyalty ledgers, fiscal submissions: kept and still linked to the (now anonymous) party, for tax and accounting law. **Once the customer has fiscal documents, the party keeps its tax ID and legal name, and its name becomes the legal name** (the POS reads the live party when it sends later refunds and voids to KRA eTIMS or the DGI); this is recorded in the request and the access report as "retained for legal obligation" (`fiscal_records`). While any of their fiscal documents is queued, sending, retrying or needs attention, the deletion waits (`fiscal_pending`). Storefront addresses (line, area, notes) and sign-in codes are cleared too |
| Employee | work and personal email and phone, address, emergency contacts, medical notes, biometric reference, custom fields | name, number, identifiers (KRA PIN, NSSF, SHIF...), dates and pay history: statutory employment and tax records. Payslips and statutory returns are kept as issued |
| User | name → anonymised label; email → a unique address under the reserved `.invalid` domain; phone and two-step secret cleared; sign-in history keeps no IP address or browser; one-time code destinations cleared; deactivated, password replaced, every session revoked | the user's id on records they created or approved (audit). The last active Owner can never be anonymised (RBAC-10) |

The anonymisation's audit entry names the tables and columns changed, never the old values (an audited model edit would have copied them into the log).

### Retention conflicts

| Record | Conflict | Resolution |
| --- | --- | --- |
| Audit log (AUD-03) | append-only and hash-chained: its earlier entries keep the values as they were | kept: integrity of the audit trail is a legal and security obligation; access to the log is restricted (`core.audit.view`). Documented to the person on request |
| Fiscal documents and submissions (KRA eTIMS, DRC DGI) | carry buyer name and tax ID as transmitted | kept for the statutory period; cannot be changed after transmission |
| Accounting journals, invoices | reference the party | kept: they reference an id; the party itself is anonymised |
| Payroll and statutory returns | name and identifiers | kept for the statutory period; employee identity fields stay |
| Backups | restored data predates the anonymisation | after any restore, re-run completed deletion requests made after the restore point (docs/backups.md) |
| Consent records | ids, and the free-text evidence of what the person said | kept as proof of what the person agreed to: append-only by design (a trigger refuses edits) |
| The request itself | its note, corrections and rejection reason may name the person | cleared once a deletion completes; a rejection's reason is never copied into the audit log |
| Offline POS devices | a till may hold the customer until its next sync | the party's change and archive sync down at the next pull |

## Marketing consent

Consent records for customers (shared by CRM and E-commerce, which both use the party): per purpose (`marketing`) and channel (email, SMS, WhatsApp, or all), each grant or withdrawal a new record with who recorded it, where it came from (back office, storefront, import, lead form) and what the person said. Records are append-only (a database trigger refuses edits and deletes); the latest per channel is the current answer, and `Consents::allows()` answers whether a customer may be contacted on a channel now. Settings → Privacy shows the current answer, the history, and records new answers.

Follow-up: the storefront already asks for consent at sign-up (`ecom_customers.consent`); recording that answer through `Consents` (source `storefront`), and marketing sends checking `Consents::allows()`, are the next steps.

## Decisions to confirm

- **A buyer snapshot on each sale.** The alternative to keeping a fiscal customer's tax ID and legal name on the party: store the buyer's name and tax ID on each POS sale when it is made, so later refunds and voids read the snapshot and the party could be fully anonymised. It changes the POS schema (owner approval) and needs a backfill of existing sales.

## To confirm (owner and counsel)

- **Deadlines.** `config/privacy.php` sets 7 days for KE and CD (`PRIVACY_DEADLINE_DAYS_KE`, `_CD`) as a conservative internal target. Counsel to confirm the statutory periods under the Kenya Data Protection Act 2019 and the Data Protection (General) Regulations 2021, and the DRC rules, per kind of request, and whether extensions are allowed.
- **Retention periods** for tax, accounting, payroll and employment records in Kenya and the DRC, which bound what deletion may remove.
- **Identity checks** expected before acting on a request.
- Whether **employee** deletion requests should be refused outright while employment records must be kept, rather than partly anonymised.
- Registration of tenants (and the platform) with Kenya's Office of the Data Protection Commissioner where required.
