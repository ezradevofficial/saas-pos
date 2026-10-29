---
id: loyalty-record-credit-payment
title: Record a credit payment, charge or adjustment
module: loyalty
order: 90
permissions: [loyalty.credit.view, loyalty.credit.receive, loyalty.credit.charge, loyalty.credit.adjust]
roles: [owner, branch_manager, accountant]
screens: [/loyalty/credit, /loyalty/credit/:accountId]
---
1. Open "Credit accounts" and tap the customer's account. The page shows the balance and "Account history" with a running "Balance".
2. When the customer pays, tap "Record payment", type the "Amount" and the "Reference", and tap "Record payment". Payments settle the oldest sale first.
3. To add something the customer owes, tap "Charge", type the "Amount", and tap "Charge".
4. To correct a balance, tap "Adjust". Choose "Reduce what the customer owes" if it goes down, type the "Amount", the "Reason" and a "Note", and tap "Adjust".

Each button appears only if you have the matching permission, and "Record payment" and "Charge" only while the account is open.
