# Phase 5: custom fields, templates, branding and layout designers

Roadmap exit criteria: custom fields everywhere; template designer with locked fiscal elements; theme editor with contrast check; dashboard, navigation, form, list and POS layout designers on versioned JSON.

Requirements: CF-01..06, TPL-01..05, BR-02..08, LAY-01..07 (LAY-08 white-label app stays "Later"). NUM-01/02 were delivered in phase 4; custom forms reuse them.

## Ground rules

- **CLAUDE.md in full.** In particular:
  - tenancy, RLS and an isolation test on every new table;
  - audit of every create, update, publish and rollback;
  - Form Requests and policies, permission names `core.<resource>.<action>`;
  - en/fr for every string;
  - tokens-only styling, with a spacing scale of 0, px, 1–6, 10, 12 only;
  - single-language tenant text (custom field labels, form names, template texts and dashboard titles are typed once).
- **Configuration over code (rule 4).** Every customer difference is data: versioned JSON rendered by the same components, never per-tenant screens or CSS.
- **Upgrade safety (LAY-07).** A layout names the fields and widgets it places. Anything the platform adds later that a layout doesn't mention appears in a default position or hidden, and never breaks rendering. A layout that names something that no longer exists skips it.
- **Fiscal elements are locked (TPL-03).** Country-pack fiscal blocks (eTIMS/DGI data, tax lines, QR codes) are always rendered, and the designer cannot remove them. Printed documents are black on white in every theme.
- **Branding scope (CLAUDE.md "Customisation is a core feature").** Tenants may override only:
  - the logo;
  - `primary` and its pair;
  - `accent` and its pair;
  - the sidebar, light or dark;
  - the corner style;
  - the font, from the curated list.

  Status colours, spacing, type sizes and the focus ring are never overridable. A pair under WCAG AA contrast can't be saved (BR-03).
- **Dependencies.** Allowed for this phase (controller decision; listed in the report):
  - `@dnd-kit/core`, `@dnd-kit/sortable` (web) for the designers;
  - `picqer/php-barcode-generator` (api) for Code 128 barcodes in templates.

  Charts are drawn with a small in-house SVG component, not a chart library.
- **Testing.**
  - Tests are written as we go, and only touched tests run locally.
  - Read the test result before merging.
  - When a change alters shared behaviour, run the folders that use it (memory: review-depth).
  - Check CI on main after every merge, waiting in the foreground (memory: no-idle-waits).
- **Reviews.** Risky tasks get a reviewer: config core, custom fields (field visibility, filters and the API), custom domains and login branding, and template output (email and share links). Screens get Playwright checks. One whole-phase review at the end.

## Tasks

| # | Task | Requirements | Risk | After |
|---|------|--------------|------|-------|
| 1 | **Versioned configuration core** | LAY-06, LAY-07 | risky | – |
| 2 | **Custom fields core** | CF-01, CF-02, CF-03, CF-06 | risky | – |
| 3 | **Theme editor and branding** | BR-02..BR-08 | risky (domains, login) | 1 |
| 4 | **Document templates** | TPL-01..TPL-05 | risky (output, share links) | 1 (and 2 for custom fields in templates) |
| 5 | **Web layout designers** | LAY-01..LAY-04 | normal | 1, 2 (form designer) |
| 6 | **POS layout designer and runtime** | LAY-05, BR on POS | normal | 1, 3 |
| 7 | **Custom forms** | CF-04, CF-05 | risky (workflow, scope) | 2 |
| 8 | **Whole-phase review, Playwright walkthrough page, report, roadmap tick** | all | – | all |

### Task details

1. **Versioned configuration core.** A generic `config_documents` / `config_versions` store:
   - Each document has a kind (theme, dashboard, navigation, form_layout, list_view, pos_layout, template) and a scope: tenant, company, branch, location, role or user.
   - Each version has a JSON payload and a status of draft, published or archived. Published versions are immutable (DB trigger).
   - Operations: save draft, validate (per-kind validator registry), preview, publish, roll back, copy between companies and branches, discard draft.
   - Resolution: most specific published version wins, with inheritance up the scope chain.
   - Upgrade-safety helpers merge a stored layout with the platform's current catalogue of fields and widgets.
   - API and audit. The workflow engine keeps its own tables.
2. **Custom fields core.**
   - Definitions per entity (items, parties, and any registered entity, including custom forms later).
   - Types: text, long text, number, currency amount, date, date-time, yes/no, dropdown, multi-select, file, lookup (items, parties, users, other records) and formula (read-only, calculated by a safe expression evaluator, never `eval`).
   - Settings: label, help, default, required, unique, min/max, pattern, visible and editable by role (RBAC-05 field rules), shown on POS.
   - Values live in `custom jsonb` with GIN indexes and expression indexes for filtered fields, and are validated server-side.
   - Exposed in API resources (hidden fields removed), list filters and columns, exports, workflow and automation conditions (FieldDefinition from definitions), notification and template merge fields, and sync to the till for "shown on POS".
   - Admin screen to manage definitions. The item and party forms render custom fields.
3. **Theme editor and branding.**
   - Tenant theme = preset plus overrides, as versioned config (task 1), editable with a live preview. Logo (light and dark), favicon, primary/accent with derived pairs, sidebar light or dark, corner style, font from the curated list. Publishing is refused under AA.
   - Scope: tenant, overridable per company or branch (BR-08). The web app applies the published theme at runtime.
   - Branded sign-in page per tenant host (BR-04).
   - Custom domains (BR-05):
     - add a domain;
     - verify it with a DNS TXT record (scheduled check);
     - an on-demand TLS "ask" endpoint for Caddy, so the certificate is issued automatically on the server. Server setup is documented for the owner.
   - Branded email sender settings with SPF/DKIM guidance, and an SMS sender ID field (BR-06).
   - "Powered by" shown unless the tenant's plan includes hiding it. This is a tenant flag until phase 6 plans exist (BR-07).
4. **Document templates.**
   - Block-based JSON templates for:
     - 58 and 80 mm receipts;
     - A4 and A5 invoices, quotes, POs, delivery notes, payslips, statements and letters.
   - Blocks: text, field, logo, table of lines, totals, QR, barcode, signature, terms, spacer and divider.
   - Drag-and-drop designer with a live preview. Language: English, French or both side by side; the app's own wording is translated, while record names print as entered.
   - Locked fiscal blocks come from the country pack.
   - Server renderer: HTML for printing, and PDF through dompdf. The till renders the same template JSON with a JS port of the renderer for its receipts.
   - Assignment by branch, customer group or document condition (TPL-05).
   - Output: print, PDF download, email with a PDF attachment, and WhatsApp share through a signed, expiring link (TPL-04).
   - The POS receipt uses the published receipt template.
5. **Web layout designers.**
   - Dashboard designer (LAY-01): a 12-column grid with drag and resize; KPI, chart, list, shortcut and approval-count widgets fed by registered data sources; per role, with personal copies. The home page renders the user's dashboard.
   - Navigation editor (LAY-02): reorder, rename, group and hide per role, plus each role's home page. Permissions still apply, so a hidden item is never a grant.
   - Form layout designer (LAY-03): sections, tabs and columns; hiding a field per role; labels and help text. Applied to the item and party forms and to custom forms.
   - List view designer (LAY-04): columns, default filters and sort; saved views shared with roles or kept personal. This replaces the per-browser column choices.
6. **POS layout designer and runtime.**
   - Per location: product grid size and order, category tiles (colour from a token set, image), quick buttons, keypad position and customer display content.
   - Synced to the till.
   - The till also applies the published tenant theme at runtime through NativeWind `vars()`.
7. **Custom forms.**
   - The admin builds a form type with custom fields, attachments, an optional line table with totals (CF-05), numbering (NUM-01) and a workflow.
   - Registered as a workflow document type at runtime, with an inbox, list view and detail, scoped by company, branch and location.
   - The form layout comes from task 5.
