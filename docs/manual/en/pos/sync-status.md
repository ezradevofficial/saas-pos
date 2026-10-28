---
id: pos-sync-status
title: Read the sync status
module: pos
order: 140
permissions: [pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:SellScreen, pos:AppHeader]
---
The sync status sits at the top of the till. It is a dot with words.

| Status | What it means |
| --- | --- |
| Online · all synced | The till is connected and everything it holds is on the server. |
| Syncing… | The till is sending or receiving data. Keep selling. |
| Offline · selling continues | There is no connection. Sales are kept on the till. |
| 3 sales waiting | The number of sales not uploaded yet. It falls to zero once the till is online. |

## Sync now

Tap "Sync now" when you want the till to send and receive right away, for example after an admin gave you a role.

## Messages on the till

- "Last synced" shows when the till last received data.
- "records were refused by the server and need review": tell your manager. The back office has the details.
- "This till has lost access": the device was unpaired or suspended. Unsent sales stay on the till. Ask an admin to resume the device.
- "The POS module is not active for your business": sales stay on the till and upload once the module is active again.
- "The subscription is overdue": the till cannot send sales until the owner pays the open invoice. Sales stay on the till.
