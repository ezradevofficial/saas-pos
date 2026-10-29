---
id: stores-return-goods
title: Return goods to the store
module: stores
order: 30
permissions: [stores.issue.view, stores.return.view, stores.return.create]
roles: [storekeeper, branch_manager, owner]
screens: [/stores/returns, /stores/issues/:issueId, /stores/returns/:returnId]
---
Goods a department does not use go back to the store against the issue they came from.

1. Open "Issues" under "Stores" and open the issue.
2. Tap "Return goods".
3. For each line, type in "Return" the quantity that comes back, in the line’s unit. The dialog shows how many were issued. Leave a line empty to return nothing.
4. Choose the "Reason": "Unused", "More than needed", "Wrong item", "Damaged" or "Other". Add a "Note" if useful.
5. Tap "Return to store". The goods go back into stock under a new return number.

Open "Returns" under "Stores" to see every return, with its issue, reason and who returned it. Open a return to read its lines. The department’s consumption report counts issued less returned.
