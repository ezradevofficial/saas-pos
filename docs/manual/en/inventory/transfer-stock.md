---
id: inventory-transfer-stock
title: Transfer stock between locations
module: inventory
order: 30
permissions: [inventory.transfer.view, inventory.transfer.create, inventory.transfer.dispatch, inventory.transfer.receive]
roles: [storekeeper, branch_manager]
screens: [/inventory/transfers, /inventory/transfers/new, /inventory/transfers/:transferId]
---
A transfer moves stock between two locations of the same company. It goes for approval before it is sent.

1. Open "Transfers" under "Inventory" and tap "New transfer".
2. Choose "From location" and "To location", and add a "Note" if useful.
3. Under "Lines", choose the "Item", the "Unit" and the "Quantity". Choose the "Batch" or type "Serial numbers" if the item needs them. Tap "Add line" for more items.
4. Tap "Request transfer".
5. When the transfer is approved, open it and tap "Dispatch". The stock is now in transit.
6. At the receiving location, open the transfer and tap "Receive". Type what arrived for each item and tap "Receive stock".
7. If less arrived than was sent, type the "Reason for the loss". The difference is written off as lost in transit.

To stop a transfer, tap "Cancel transfer" and give a reason. The stock stays where it is.
