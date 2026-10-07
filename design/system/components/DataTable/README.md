# DataTable

A list of records with sortable-looking headers, right-aligned money and selectable rows.

**Props the consumer provides:** `columns` (`key`, `label`, `align`, `numeric`, optional `render`), `rows` (each with an `id`), optional `selectedId`, `onRowClick`, `emptyText`, `caption`.

**Do:** right-align amounts with `align: 'end'` and render them with Money; use StatusBadge for status columns; write a helpful `emptyText` ("No purchase orders yet. Create one from a requisition.").
**Don't:** put more than one action button per row; use the row click for opening the record instead.
