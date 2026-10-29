---
id: imports-undo
title: Undo an import
module: core
order: 540
permissions: [core.import.run]
roles: [owner, admin, accountant]
screens: [/settings/imports]
---
Warning: undoing an import archives the records it added and restores the earlier values of the records it updated. It affects live records, so check the counts before you confirm.

1. Open Settings, then "Imports".
2. Open the completed import from the list.
3. Tap "Undo import". The window says how many records were added and updated.
4. Tap "Undo import", or "Keep import" to leave things as they are.

A record that is now in use, or that changed since the import, is not undone. The page lists each one with the reason, and the import shows "Partly undone". You can try the undo again later.

Tills that already received imported items keep them until they next sync.
