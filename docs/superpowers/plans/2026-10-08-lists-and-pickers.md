# Lists and pickers (between Phase 2 and Phase 3)

Owner request, 2026-10-08: every table gets search, filters, export to Excel
and PDF, sorting, pagination with a record count, a rows-per-page selector
and showing or hiding columns. Every dropdown becomes a searchable combobox;
the native `<select>` goes away.

Requirements: EXP-01 (export any list to Excel, CSV or PDF, respecting
permissions and field rules), RBAC-05 (field rules), AUD-01 (exports are
audited), L10N-01/02, BR-01, LAY-04 (personal column choice now; saved views
shared with roles stay in Phase 5).

Design-system conflict, owner ruling: the design system's Select README says
"labelled native dropdown for short lists". The owner wants a searchable
combobox everywhere. The owner's words win; record it in
`docs/adr/008-lists-and-pickers.md`.

## API contract (every list endpoint)

- `?search=` free text (each list says what it matches), `?sort=key` or
  `?sort=-key` (descending), keys whitelisted per list, unknown key → 422;
  ties always broken by `id` so pages are stable.
- `?page`, `?per_page` (1 to 200; the UI offers 10, 25, 50, 100). Responses
  keep Laravel's `meta` (`total`, `from`, `to`, `last_page`).
- Existing filters stay (`status`, `category`, `type`, ...).
- Export: the same endpoint with `?format=csv|xlsx|pdf` and
  `?columns[]=key...` (whitelisted, in the order given; default = all
  exportable columns). Same filters, search and sort; every matching row, no
  paging. Streamed in the tenant's context (`TenantContext::run`, as
  `AccessReviewController` does). Values come from the same API resource as
  the JSON, so fields hidden by field rules (RBAC-05) never appear; a hidden
  column is dropped. Headers and values in the user's language: translated
  enums, money as "KES 12,450.00", dates in the company or user time zone.
- PDF: at most 500 rows (422 with "Too many rows for a PDF. Narrow the
  filters or export to Excel."). Black on white, A4 landscape, title,
  filters summary, generated at, page numbers. Excel/CSV: streamed, no cap
  beyond the request timeout; CSV starts with a UTF-8 BOM so Excel opens
  French accents correctly.
- Every export is audited: action `<module>.<resource>.export`, metadata
  `{format, rows, columns, filters}`. Permission: the list's own view
  permission (no separate export permission yet; flagged to the owner).
- Filename `<list>-YYYY-MM-DD.<ext>`.
- New dependencies: `openspout/openspout` (xlsx streaming), `dompdf/dompdf`
  (PDF).

## Web

- `Combobox` (ds): shadcn Popover + Command (cmdk), added with the shadcn
  CLI. The ds `Select` keeps its props (`label, help, error, placeholder,
  options, value, onChange, defaultValue, disabled, required, name, id`) and
  becomes a searchable combobox; `onChange` still receives
  `{ target: { value, name } }` so call sites don't change. Search box inside
  the popup, keyboard support, disabled options, a hidden input when `name`
  is given. The company switcher moves to it too; `ui/select` is removed
  once unused. A test helper `chooseOption(label, optionText)` replaces
  `fireEvent.change` on selects in tests.
- `ListView` (ds) + `useServerList` hook: toolbar (search, the page's
  filters, a "Columns" menu with checkboxes, an "Export" menu: Excel, CSV,
  PDF), the DataTable with sortable headers (`aria-sort`), and a footer with
  "1–25 of 312", rows per page (10/25/50/100) and first/previous/next/last.
  State (search, filters, sort, page, per_page) lives in the URL; column
  choice in localStorage per user and list. Export downloads with the bearer
  token (fetch → blob) and shows a toast while it runs and when it fails.
- Every table page moves to ListView.

## Tasks

| # | Task | Risk | Depends on |
|---|------|------|------------|
| 1 | API list framework: sort/search/per-page trait, `ListExport` (csv, xlsx, pdf), audit, field rules, wired into items and parties first | risky (field rules, tenancy) | – |
| 2 | Web Combobox, ds Select rebuilt on it, all call sites and tests, company switcher, `ui/select` removed | normal | – |
| 3 | API: sort, search and export on every other list endpoint | risky | 1 |
| 4 | Web ListView + useServerList, Items and Customers/Suppliers migrated | normal | 1, 2 |
| 5 | Web: every other table page on ListView | normal | 3, 4 |
| 6 | ADR 008, Playwright walkthrough of every list and picker, whole review | – | 5 |
