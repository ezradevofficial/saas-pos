---
id: numbering-set-format
title: Set how documents are numbered
module: core
order: 500
permissions: [core.numbering.view, core.numbering.edit]
roles: [owner, admin]
screens: [/settings/numbering]
---
Each document type, such as a receipt or an invoice, has a number format. You can set one format for the whole organisation, and a different one for a company or a branch.

1. Open Settings, then "Numbering".
2. Find the document type. A type with no format of its own uses the default, and shows an example number.
3. Tap "Add format" to give a company or branch its own format, or "Edit" to change one.
4. Choose the "Company" and, if the format is for one branch, the "Branch".
5. Type the "Pattern". Use letters, digits and - / _ . and one counter, such as {000001}; its zeros set the minimum width. Tap a token under "Insert" to insert it.
6. Choose the "Counter": it never restarts, or restarts every year. A counter that restarts every year needs {YYYY} or {YY} in the pattern.
7. Switch on "No gaps" if each number must follow the last with none skipped. Documents then wait for each other while they are numbered.
8. Check the "Example", then tap "Save format".

Tills number receipts from ranges they receive in advance, so receipt numbers can have gaps.
