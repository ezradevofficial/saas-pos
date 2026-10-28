---
id: pos-work-offline
title: Sell without a connection
module: pos
order: 130
permissions: [pos.sale.create]
roles: [cashier, branch_manager]
screens: [pos:SellScreen, pos:PaymentScreen]
---
The till keeps selling when the connection drops. It can sell for at least 7 days offline.

## What to expect

1. The status at the top changes to "Offline · selling continues". The sell screen adds a note that sales are recorded on the till and uploaded when you reconnect.
2. Sell as usual. Cash and M-Pesa codes typed by hand work offline. A payment request to the customer's phone needs a connection, so use the code from their message.
3. Each sale takes its receipt number from a range the till already holds.
4. When the connection returns, the till uploads the sales by itself. The status changes to "Syncing…", then to "Online · all synced".

## What needs a connection

- Changing a PIN, and checking a PIN that the till cannot check offline.
- New prices, products and customers. The till keeps using the last data it received.
- Sending sales to the tax authority. They queue and are sent after the till uploads them.

## If the till runs out of receipt numbers

The till says it has no receipt numbers left. Connect it to the internet so the server can give it more, then complete the sale.

Do not clear the app's data or unpair the till while sales are waiting to upload.
