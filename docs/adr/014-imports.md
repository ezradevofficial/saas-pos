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

**Running.** `RunImport` is a queued job with `TenantAware`, in the import's language. Batches of 500 rows, one transaction each; a refused row rolls back to its savepoint and is reported. Progress is saved after every batch. One dry run, import or undo per tenant and importer at a time (a partial unique index; 409 `import_running`).

**Audit.** Every entry written during an import or undo carries `metadata.import_id` (`AuditContext::actingAs`), plus `core.import.*` entries for upload, mapping, dry run, start, completion and undo. A batch appends through `Auditor::batch`: the chain head is locked once and moved once per batch; each entry still links to the previous one, and a savepoint rolled back mid-batch is noticed (`TransactionRolledBack`) so the chain never has a gap. Updating the head row for every entry left thousands of row versions in one transaction and made each append slower than the last.

**Undo (IMP-02).** Within 24 hours of finishing. `import_rows` keeps every record created or updated, with its before image. Refused (422 `import_undo_blocked`, listing up to 100 blocking rows) when a created record is used (`RecordUsage`: POS sale lines and sales, prices, credit limit requests, custom field lookups) or when any record changed after the import outside it (an audit entry without the import's id; by anyone, so nothing done since is lost). Otherwise one transaction archives created records (never deletes, TEN-06) and puts back the before images, last row first.

**Permissions.** `core.import.run` (Owner and Admin through `core.*`; Accountant, who can then import customers and suppliers). Imports are seen by those who may run their importer; mapping and starting belong to the uploader; undo to anyone who may run the importer in the import's mode.

## Consequences

- Throughput is that of the API: about 8 ms a row locally (50,000 items in about 6 minutes), dominated by per-row authorisation and validation. `LargeImportTest` runs 50,000 rows in committed batches (outside the test transaction: one transaction holding 50,000 rows' savepoints runs out of lock space).
- `items.code_key` (stored `lower(code)`) was added: under RLS the planner only uses leakproof comparisons in index conditions, and citext's `=` is not, so every code check read all of the tenant's items.
- Files stay on the media disk; pruning old import files is a follow-up.
