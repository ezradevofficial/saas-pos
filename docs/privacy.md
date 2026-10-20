# Data protection (NFR-08)

Kenya's Data Protection Act 2019 and the DRC's rules give people the right to see, correct and delete the personal data a business holds about them, and require consent for direct marketing. Each tenant is the data controller for its customers, employees and users; the platform is its processor. This is how the platform helps a tenant meet those duties. It is not legal advice; the points under "To confirm" need the owner's counsel.

## Data subject requests (Settings → Privacy)

A request is about one person in the tenant: a **customer** (a party), an **employee** (HR) or a **user**. Each module registers where its personal data lives (`PrivacySubjects`): core registers parties and users, HR its employees, Payroll its copy of an employee, CRM the leads converted to a customer, E-commerce the storefront accounts.

| Kind | What carrying it out does |
| --- | --- |
| Access | Makes the access report available: the person's own rows in every registered table, and the rows of tables that point at them (purchases, loyalty, leave, payslips...), at most 500 per table with full counts. The tenant export's column rules apply: no password or PIN material, secrets or tokens. "Who did it" columns and other people's rows are left out. The report is built when downloaded (JSON), never stored, and each download is audited. |
| Correction | Applies the approved new values of correctable fields (names, tax ID, contact details; lists such as phones are edited on the record). Audited with the values before and after, like any edit. |
| Deletion | Anonymises the person (below), because business records are never hard-deleted (TEN-06). |

### Flow

1. **Received**: someone with `core.privacy_request.manage` logs it, noting how it arrived and how the person's identity was checked. The deadline is set from the subject's company country (`config/privacy.php`).
2. **Approved or rejected** by someone with `core.privacy_request.approve` who did not log it (four eyes). A rejection keeps its reason.
3. **Completed**: an approved request is carried out by someone with `manage`.
4. Requests past their deadline and still open show as **Overdue**.

Every step is audited (AUD-01). Permissions: `core.privacy_request.view`, `.manage`, `.approve` and `core.consent.view`, `.manage`, all at tenant scope; Owner and Admin hold them. Whoever downloads an access report sees everything about that person, medical notes included for an employee: give `manage` sparingly.

### What anonymisation changes and keeps

| Subject | Replaced | Kept, and why |
| --- | --- | --- |
| Customer (party) | name → "Anonymised XXXXXXXX"; legal name, tax ID cleared; phones, emails, addresses, custom fields emptied; the party archived. CRM leads linked to it: name, email, phone, organisation, title, notes, custom fields. Storefront account: name, email, phone; archived | sales, invoices, payments, journals, loyalty ledgers, fiscal submissions: kept and still linked to the (now anonymous) party, for tax and accounting law. Fiscal documents keep the buyer details printed on them at the time (KRA eTIMS, DGI) |
| Employee | work and personal email and phone, address, emergency contacts, medical notes, biometric reference, custom fields | name, number, identifiers (KRA PIN, NSSF, SHIF...), dates and pay history: statutory employment and tax records. Payslips and statutory returns are kept as issued |
| User | name → anonymised label; email → a unique address under the reserved `.invalid` domain; phone and two-step secret cleared; deactivated, password replaced, every session revoked | the user's id on records they created or approved (audit). The last active Owner can never be anonymised (RBAC-10) |

The anonymisation's audit entry names the tables and columns changed, never the old values (an audited model edit would have copied them into the log).

### Retention conflicts

| Record | Conflict | Resolution |
| --- | --- | --- |
| Audit log (AUD-03) | append-only and hash-chained: its earlier entries keep the values as they were | kept: integrity of the audit trail is a legal and security obligation; access to the log is restricted (`core.audit.view`). Documented to the person on request |
| Fiscal documents and submissions (KRA eTIMS, DRC DGI) | carry buyer name and tax ID as transmitted | kept for the statutory period; cannot be changed after transmission |
| Accounting journals, invoices | reference the party | kept: they reference an id; the party itself is anonymised |
| Payroll and statutory returns | name and identifiers | kept for the statutory period; employee identity fields stay |
| Backups | restored data predates the anonymisation | after any restore, re-run completed deletion requests made after the restore point (docs/backups.md) |
| Consent records | ids only, as evidence | kept as proof of what the person agreed to |
| Offline POS devices | a till may hold the customer until its next sync | the party's change and archive sync down at the next pull |

## Marketing consent

Consent records for customers (shared by CRM and E-commerce, which both use the party): per purpose (`marketing`) and channel (email, SMS, WhatsApp, or all), each grant or withdrawal a new record with who recorded it, where it came from (back office, storefront, import, lead form) and what the person said. Records are append-only (a database trigger refuses edits and deletes); the latest per channel is the current answer, and `Consents::allows()` answers whether a customer may be contacted on a channel now. Settings → Privacy shows the current answer, the history, and records new answers.

Follow-up: the storefront already asks for consent at sign-up (`ecom_customers.consent`); recording that answer through `Consents` (source `storefront`), and marketing sends checking `Consents::allows()`, are the next steps.

## To confirm (owner and counsel)

- **Deadlines.** `config/privacy.php` sets 7 days for KE and CD (`PRIVACY_DEADLINE_DAYS_KE`, `_CD`) as a conservative internal target. Counsel to confirm the statutory periods under the Kenya Data Protection Act 2019 and the Data Protection (General) Regulations 2021, and the DRC rules, per kind of request, and whether extensions are allowed.
- **Retention periods** for tax, accounting, payroll and employment records in Kenya and the DRC, which bound what deletion may remove.
- **Identity checks** expected before acting on a request.
- Whether **employee** deletion requests should be refused outright while employment records must be kept, rather than partly anonymised.
- Registration of tenants (and the platform) with Kenya's Office of the Data Protection Commissioner where required.
