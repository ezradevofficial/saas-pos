# ADR 008: Lists, exports and pickers

Status: Accepted (2026-10-08, between Phase 2 and Phase 3). Covers EXP-01, RBAC-05, AUD-01, LAY-04 (personal column choice) and BR-01.

## Context

The owner found the tables too shallow: they asked for search, filters, sorting, a record count, rows per page, paging, showing and hiding columns, and export to Excel and PDF on every table. They also asked that every dropdown become a searchable combobox and that native `<select>` options go away. EXP-01 requires exports to respect permissions and field rules.

## Decision

### Lists are server-side

- Every list endpoint takes `search`, `sort=key|-key`, `page`, `per_page` (1 to 200; the UI offers 10, 25, 50, 100) and its own filters. Sort keys are a whitelist per list; an unknown key is a 422. Ties are broken by `id`, so pages are stable.
- Each list is described once by a `ListDefinition` (`api/app/Core/Lists`): its sort keys (`ListSort`), its export columns (`ListColumn`), the resource that renders a row, its title and its audit action. FormRequests use `SortsAndExports` (or `ListsRecords`, which adds the archive `status` filter).
- Field rules (RBAC-05) apply to more than the columns. A sort key, a search field and a filter each declare the fields they read. Sorting or filtering on a field the user's rules hide is a 422, because the order or the matches would reveal the hidden values. Search skips hidden fields. A default sort on a hidden field falls back to `id`.
- A few small lists (a user's role assignments, sessions, tenant currencies) still return every row when no paging parameter is sent, because pickers rely on that. Tables always send paging parameters.

### Exports

- The same endpoint with `format=csv|xlsx|pdf` and `columns[]=` (the visible columns, in display order) exports every row matching the current search, filters and sort.
- Rows are rendered through the same API resource as the JSON response, so a hidden field never reaches a file. A column built from a hidden field is dropped. If every requested column is hidden, the request is refused.
- The stream runs inside `TenantContext::run` for the requesting tenant (RLS), with the list's export relations only. A test proves rows of another tenant never appear even when the context has changed before streaming.
- CSV carries a UTF-8 BOM and is guarded against formula injection. XLSX is written with OpenSpout. PDF is rendered with Dompdf, black on white, A4 landscape, in DejaVu Sans (Geist has no TTF build Dompdf can use; DejaVu covers French), with remote resources, PHP and JavaScript off and a dedicated chroot.
- PDF is capped at 500 rows. Measured: 500 rows peak at about 111 MB and take about 3 seconds; 2,000 rows exhausted 1 GB before the layout was split into tables of 100 rows. Larger exports go to Excel or CSV.
- Exports are rate-limited to 10 a minute per user (the `exports` limiter, shared with the access review export) and audited as `<module>.<resource>.export` with the format, row count, columns and filters.
- Exporting needs the list's own view permission. There is no separate export permission yet; the owner may ask for one.
- Exports name another user (an inviter, a dimension owner) only when the reader may see that user, otherwise "Someone you can’t see". Grants and places outside the reader's scope are left out.

### Web

- `useServerList` keeps search, filters, sort, page and rows per page in the URL, so links and Back keep the view. Column choice is stored per user and list in the browser (`app.list.<userId>.<listId>.columns`); saved views shared with roles remain LAY-04 work for Phase 5.
- `ListView` renders the toolbar (search, the page's filters, Columns, Export), the sortable table (`aria-sort`) and the footer (record count, rows per page, first, previous, next, last). A refused sort falls back to the default order and shows the reason.
- Trees (item categories, dimensions) and payment-method cards keep their layout, because paging or sorting would separate parents from children or break the till order; they get the Export menu.

### Pickers

- The design system's Select README describes a native dropdown for short lists. The owner ruled that every picker is a searchable combobox; the owner's words win over the design system here.
- `Combobox` (shadcn Popover + Command, cmdk) filters by label, ignoring case and accents. It is fully keyboard-driven and works inside dialogs. The popup is at least as wide as its field and grows to fit long names.
- The ds `Select` keeps its props and `onChange(event)` contract and is built on `Combobox`, so call sites did not change. The native `<select>` and shadcn's `ui/select` are gone.

## Consequences

- New dependencies: `openspout/openspout`, `dompdf/dompdf` (API) and `cmdk` (web, through shadcn).
- Adding a list means a `ListDefinition`, sort keys with their fields, export columns with their fields, and en/fr labels. The isolation suite exports every list in all three formats.
- Exports run in the request. If tenants need PDFs of more than 500 rows, or exports slower than the request timeout, they move to a queued job with a download link.
