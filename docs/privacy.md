# Data protection (NFR-08)

Kenya's Data Protection Act 2019 and the DRC's rules give people the right to see, correct and delete the personal data a business holds about them, and require consent for direct marketing. Each tenant is the data controller for its customers, employees and users; the platform is its processor. This is how the platform helps a tenant meet those duties. It is not legal advice; the points under "To confirm" need the owner's counsel.

## Data subject requests (Settings → Privacy)

A request is about one person in the tenant: a **customer** (a party), an **employee** (HR) or a **user**. Each module registers where its personal data lives (`PrivacySubjects`): core registers parties and users, HR its employees, Payroll its copy of an employee, CRM the leads converted to a customer, E-commerce the storefront accounts.

| Kind | What carrying it out does |
| --- | --- |
| Access | Makes the access report available: the person's rows in the tables their modules **declared** as being about them (a customer's sales, loyalty, invoices, CRM opportunities and storefront orders; an employee's leave, attendance, documents and payslips; a user's sign-ins, role assignments, single sign-on links and own employee record), at most 500 per table with full counts. Nothing is found by following foreign keys, so rows someone merely touched for others (sales rung up as cashier, leads they own, appraisals they reviewed, approvals, custody) never appear. The tenant export's column rules apply (no password or PIN material, secrets, tokens, or encrypted ID, account and medical values), HR's sensitive groups the downloader may not see are masked (below), and so is every field the downloader's field rules hide (RBAC-05) on parties, credit limit changes, CRM leads and opportunities: it reads `[hidden]`. A support session never downloads a report. The report is built when downloaded (JSON), never stored, and each download is audited. |
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

API-key and marketplace app principals (status `service`) and support principals are `users` rows but not people: they are never found as subjects, so no request is logged, searched or corrected for them (Phase 8 review L2).

### What anonymisation changes and keeps

| Subject | Replaced | Kept, and why |
| --- | --- | --- |
| Customer (party) | name → "Anonymised XXXXXXXX"; legal name, tax ID cleared; phones, emails, addresses, custom fields emptied; the party archived. CRM leads linked to it: name, email, phone, organisation, title, notes, custom fields. Storefront account: name, email, phone; archived | sales, invoices, payments, journals, loyalty ledgers, fiscal submissions: kept and still linked to the (now anonymous) party, for tax and accounting law. **Once the customer has fiscal documents, the party keeps its tax ID and legal name, and its name becomes the legal name** (the POS reads the live party when it sends later refunds and voids to KRA eTIMS or the DGI); this is recorded in the request and the access report as "retained for legal obligation" (`fiscal_records`). While any of their fiscal documents is queued, sending, retrying or needs attention, the deletion waits (`fiscal_pending`). Storefront addresses (line, area, notes) and sign-in codes are cleared too |
| Employee | work and personal email and phone, address, emergency contacts, medical notes, biometric reference, custom fields | name, number, identifiers (KRA PIN, NSSF, SHIF...), dates and pay history: statutory employment and tax records. Payslips and statutory returns are kept as issued |
| User | name → anonymised label; email → a unique address under the reserved `.invalid` domain; phone and two-step secret cleared; sign-in history keeps no IP address or browser; one-time code destinations cleared; single sign-on links archived, their asserted email and subject replaced, so the identity provider can no longer sign the account in; deactivated, password replaced, every session revoked | the user's id on records they created or approved (audit). The last active Owner can never be anonymised (RBAC-10) |

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

Each record also keeps the **wording version** the person saw (`wording_version`), so it proves exactly which text they agreed to.

### Storefront sign-up

The storefront's sign-in (a code by SMS or email) shows a box, unticked by default: "Send me offers and news from {store} by SMS, WhatsApp or email. I can stop them at any time in my account." Ticked, the API (`MarketingConsent` in E-commerce, through `Consents`) records a grant for every channel (`any`), source `storefront`, the wording version the page sent, and evidence naming the store, the sign-in code's id (the proof the contact was verified) and the language; never the phone or email. The customer's party is created then if it has none, since consent belongs to the party. An unticked box records nothing: no record means no consent, and it never withdraws a consent given elsewhere (at the till). The account page has a switch that withdraws (a refusal for `any`) or grants again with the wording shown. `ecom_customers.consent` is no longer the answer; `Consents` is.

**Wording versions** are listed in `config/privacy.php` (`wording_versions.storefront_marketing`, current first) and the text is in the web catalogue under a key naming the version (`shop.consent.marketing_2026_10`, `MARKETING_WORDING` in `ShopPages.jsx`). A changed text gets a new version and a new key, never an edit; the API refuses a version it does not list.

### Marketing sends check consent first

All messages go through the core Notifier (NOT-02). An event type declared `marketing: true` (a campaign, an offer by SMS, WhatsApp or email) goes only to customer contacts, each naming the customer's party; a user recipient or an address without a customer is refused. For each address the Notifier checks `Consents` for that channel (one query per channel, `allowedAmong()`); without consent the delivery is written as skipped with the reason `no_consent` and nothing is sent. The queued job checks again before handing over, so a withdrawal after queueing stops the message. Transactional types (order updates, delivery codes, receipts, invitations) are not marketing and are unaffected.

Today no module sends marketing yet (CRM campaigns and marketing lists are deferred, docs/modules/crm.md); any that is added must declare its event types `marketing: true`. Tests: `MarketingConsentGateTest` (core) and `MarketingConsentTest` (E-commerce), web `ShopConsent.test.jsx`.

## Decisions to confirm

- **A buyer snapshot on each sale.** The alternative to keeping a fiscal customer's tax ID and legal name on the party: store the buyer's name and tax ID on each POS sale when it is made, so later refunds and voids read the snapshot and the party could be fully anonymised. It changes the POS schema (owner approval) and needs a backfill of existing sales.
- **Storefront consent wording and scope.** The text (version `2026_10`) and its single box for all three channels (SMS, WhatsApp, email). Counsel may want one box per channel, or the store's identity as controller spelled out. A new text is a new version.
- **A party created at sign-up when the box is ticked.** Consent is recorded on the customer's party, so a consenting shopper gets one at sign-up instead of at their first signed-in order. The alternative is to keep the answer on the storefront account until the party exists.
- **An unticked box records nothing.** The alternative is to record a refusal, which would also withdraw a consent given at the till; we chose not to.
- **No marketing sends exist yet.** The gate is in the Notifier for event types marked `marketing`; campaigns (CRM, deferred) must use it. Confirm that every future campaign goes through the Notifier rather than its own sender.
- **An unsubscribe link in each marketing message**, and STOP replies by SMS and WhatsApp, are not built: withdrawal is from the storefront account or the back office today. Kenya's direct-marketing rules expect a simple opt-out in each message; to build with campaigns.

- **Privacy duties while the tenant is read-only or suspended (Phase 8 review L8).** Logging, approving, rejecting and carrying out data subject requests, and recording a withdrawal of marketing consent, are allowed while the tenant is read-only for an unpaid invoice or suspended by platform staff (`EnforceSubscription`, class `privacy`). These are the tenant's legal obligations under the Kenya Data Protection Act 2019 and its deadlines keep running whatever the tenant owes. A new consent grant is still refused. Owner to confirm, with counsel, that the platform should keep these open in both states.

## To confirm (owner and counsel)

- **Deadlines.** `config/privacy.php` sets 7 days for KE and CD (`PRIVACY_DEADLINE_DAYS_KE`, `_CD`) as a conservative internal target. Counsel to confirm the statutory periods under the Kenya Data Protection Act 2019 and the Data Protection (General) Regulations 2021, and the DRC rules, per kind of request, and whether extensions are allowed.
- **Retention periods** for tax, accounting, payroll and employment records in Kenya and the DRC, which bound what deletion may remove.
- **Identity checks** expected before acting on a request.
- Whether **employee** deletion requests should be refused outright while employment records must be kept, rather than partly anonymised.
- Registration of tenants (and the platform) with Kenya's Office of the Data Protection Commissioner where required.
