# ADR 014: Imports

Status: Accepted (Phase 6, task 5: IMP-01, IMP-02, IMP-03, CF-03, AUD-01, TEN-01)

## Context

Tenants bring items, customers, suppliers and prices from spreadsheets (IMP-01), check them before anything is saved, and undo a bad batch (IMP-02). Files reach 50,000 rows (IMP-03). An imported row must meet exactly the rules of the same record typed in the app: permissions and scope, field rules (RBAC-05), custom fields (CF-03), uniqueness, audit.

## Decision

**Importers** (`app/Core/Imports`). An `Importer` registers in `ImportRegistry` and declares:
- its key, record model, and the permissions it needs besides `core.import.run` (the entity's create permission, plus edit to update);
- its columns (`ImportColumn`: type, required, help, en/fr labels from `imports.columns.*`), then the entity's custom fields the user may change (`cf_<key>`, not formulas or files);
- `prepare` (lookups by code or name, the record matched in update mode), `validate`, `write`, `undo`, an example row for the template, and the `RecordUsage` entity whose uses block an undo.

Core registers `items`, `customers`, `suppliers` and `item_prices`. Employees, opening stock and opening balances register the same way from HR, Inventory and Accounting when they exist; their usage checks go to `RecordUsage`.

**Same rules as the API.** `DomainRequests` runs the API's own Form Request for each row (`StoreItemRequest`, `UpdatePartyRequest`, `SetItemPriceRequest`...) as the importing user: `authorize()`, `GuardsFieldRules`/`FreezesPrices`, the rules and the after-hooks, so `CustomFieldValidator` checks custom values. Rows are saved by the same writers as the controllers: `ItemWriter` and `PartyWriter` (extracted from the controllers) and `PriceWriter`, so `CustomFieldWriter`, uniqueness under the sharing locks and audit apply. Nothing in an import bypasses RLS: it runs on the runtime connection in the tenant's context.

**Files.** XLSX or UTF-8 CSV (with or without a BOM; comma, semicolon or tab), up to 50,000 rows and 20 MB, read streaming with OpenSpout. Stored on the `media` disk at `tenants/{tenant}/imports/{import}/`. Result and error files are downloaded through temporary URLs signed for one user; the controller enters the tenant the path names and checks the user again. Every cell written back is text and formula-safe (`SpreadsheetCell`). Templates come in English and French with an example row and a notes sheet; headers in either language map themselves.

**Wizard.** Upload (rate-limited, 10 a minute per user) → mapping (guessed from the mapping last used for the importer, then from keys and en/fr labels) → preview (first 200 rows, errors by column) → dry run (whole file, nothing written, error file) → import → result file → undo. Mode: create only, or create and update (items matched by code, parties by tax ID, prices by list, item, unit, date and quantity break). Blank cells are left out: an update never clears a value.

**Running.** `RunImport` is a queued job with `TenantAware`, in the import's language, on the `imports` queue: Horizon's `supervisor-imports` works it on the `redis-imports` connection, whose `retry_after` (3,900 s) outlasts the job's timeout (3,600 s), so redis never hands a running import to a second worker. Each start gets a run token; the one worker that claims it (`Import::claim`) runs, and a second delivery does nothing. Every status change is conditional on the status expected (`Import::transition`), so a stale worker never overwrites a later state such as undone. Batches of 500 rows, one transaction each; a refused row rolls back to its savepoint and is reported. Progress is saved after every batch. One dry run, import or undo per tenant and importer at a time (a partial unique index; 409 `import_running`).

**Lock order.** A batch takes the importer's locks first (`Importer::lockForBatch`: the master data sharing lock for items or parties; the tenant's price lists in id order for prices), then the audit chain head (`Auditor::batch`), as the API's writers do. Never the other way round.

**Audit.** Every entry written during an import or undo carries `metadata.import_id` (`AuditContext::actingAs`), plus `core.import.*` entries for upload, mapping, dry run, start, completion and undo. A batch appends through `Auditor::batch`: the chain head is locked once and moved once per batch; each entry still links to the previous one, and a savepoint rolled back mid-batch is noticed (`TransactionRolledBack`) so the chain never has a gap. Updating the head row for every entry left thousands of row versions in one transaction and made each append slower than the last.

**Undo (IMP-02).** Within 24 hours of finishing. `import_rows` keeps every record created or updated, with its before image. Refused (422 `import_undo_blocked`, listing up to 100 blocking rows) when a created record is used (`RecordUsage`: POS sale lines and sales, prices, credit limit requests, custom field lookups) or when any record changed after the import outside it (an audit entry without the import's id; by anyone, so nothing done since is lost). Otherwise the import is marked UNDOING and the job undoes rows last first, in committed chunks of 500, each under its own `Auditor::batch`, so the tenant's audit chain is never held for a whole undo. Each chunk locks the records it created FOR UPDATE (a sale racing to use one waits, then is seen), checks its rows again, and undoes each one as the undoer, through the entity's own requests and writers: created records are archived (never deleted, TEN-06) as `POST …/archive` would, and updated ones get back only the values the import changed, as `PATCH` would (field rules, the credit limit rule, sharing locks; `PriceWriter` for prices). If a chunk finds something depending, or a row the undoer may not undo, the undo stops: rows undone stay undone (`import_rows.undone_at`), the import is PARTLY_UNDONE with the blocking rows, and may be undone again within the 24 hours.

*Offline tills (known limitation).* The server does not keep each till's pull cursor, so it cannot tell whether a till already holds an imported item. A sale a till made offline with an undone item still arrives and is kept (the item is archived, not deleted). The undo dialog says so before an items undo.

**Permissions.** `core.import.run` (Owner and Admin through `core.*`; Accountant, who can then import customers and suppliers), plus the importer's create (and, to update, edit) permission. The uploader sees their import, maps and starts it. Anyone else sees an import only once it ran, and only if their scope covers every company of the records it touched (`imports.scope_companies`, computed from `import_rows`; "shared" for shared records) with the entity's read permission; otherwise it is not found, in the list too. Undo needs that and the importer's permissions in the import's mode; each row then goes through the entity's policy as the undoer. The file's own rows (preview, error and result files) go to the uploader only: they are the uploader's data, and another reader's field rules may hide columns the file holds, so we chose not to serve them rather than strip columns.

**Hostile files.** CSV must be UTF-8; Windows-1252 and ISO-8859-1 are converted at upload, UTF-16 and anything else refused (422 `file_encoding`). XLSX parts may expand to at most 200 MB and declare at most 1,000,000 shared strings (422 `file_too_large`). Rows are counted physically, empty ones included, up to 100,000, so a sheet with one row at 1,048,576 is refused before a million empty rows are read. A cell over 10,000 characters is a row error. Counting stays in the upload request because the wizard shows the total at once; it is bounded by these caps.

**Matching.** Items by code and parties by tax ID, both within the row's scope (its company, or shared records); a tax ID held by more than one party there is a row error, never a guess.

## Consequences

- Throughput is that of the API: about 8 ms a row locally (50,000 items in about 6 minutes), dominated by per-row authorisation and validation. `LargeImportTest` runs 50,000 rows in committed batches (outside the test transaction: one transaction holding 50,000 rows' savepoints runs out of lock space). It is in the PHPUnit group `slow`, which CI runs as its own job beside the main tests.
- `items.code_key` (stored `lower(code)`) was added: under RLS the planner only uses leakproof comparisons in index conditions, and citext's `=` is not, so every code check read all of the tenant's items. Its index `(tenant_id, company_id, code_key)` is built CONCURRENTLY in a migration outside a transaction.
- Files stay on the media disk; pruning old import files is a follow-up.
