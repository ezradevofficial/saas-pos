---
id: imports-run
title: Import records from a spreadsheet
module: core
order: 530
permissions: [core.import.run]
roles: [owner, admin, accountant]
screens: [/settings/imports, /settings/imports/new, /settings/imports/:importId]
---
Bring items, customers, suppliers and prices from a spreadsheet. Each row is checked with the same rules as a record typed in the app, and nothing is saved until you import. You see only the import types you may create.

1. Open Settings, then "Imports".
2. Tap "New import".
3. Under "What do you want to import?", choose the type, then tap "Continue to the template".
4. Download the template in English or French, fill in one row per record, then tap "Continue to upload". Required columns are marked.
5. Tap "Choose file" and pick your XLSX or UTF-8 CSV file (up to 50,000 rows and 20 MB), then tap "Upload and map columns".
6. Under "Map columns", choose the file column for each field. Choose what happens "When a record already exists": "Add new records only", or "Add new and update existing records". Tap "Save and preview".
7. Read the "Preview". Use "Rows with errors" to see rows with errors, or "Change mapping" to change the mapping. Tap "Check the whole file".
8. When the whole file has been checked, rows with errors are skipped. Tap "Download rows with errors" to fix and upload them again, or import the other rows now with the import button.
9. The import continues in the background if you leave the page. The "Result" page shows what was added, updated and not imported, and "Download result file".
