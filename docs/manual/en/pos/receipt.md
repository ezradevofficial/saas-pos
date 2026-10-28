---
id: pos-receipt
title: Give the receipt
module: pos
order: 70
permissions: [pos.sale.print, pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:ReceiptScreen, pos:SalesScreen]
---
When a sale is complete, the till shows "Sale complete" with the receipt.

1. If the customer paid more than the total, give them the amount shown under "Give the customer".
2. Tap "Print" to print the receipt.
3. Tap "New sale" to start the next one.

## Tax authority line

The receipt carries a line for the tax authority (KRA eTIMS in Kenya, DGI in DR Congo). Its status tells you where the sale is:

- "Waiting to upload": the sale is on this till and is sent when the till is online.
- "Pending": the sale reached the server and the authority has not answered yet.
- "Accepted": the authority's code and QR code are added.
- "Refused by the authority": the back office follows up. The sale stands.
- "Not sent to the authority": this business does not send sales from the platform yet.

## Find an old receipt

Tap "Menu", then "Sales and returns". Type the number in "Receipt number" and tap "Find sale", or pick the sale from the list.
