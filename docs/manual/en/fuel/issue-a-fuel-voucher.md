---
id: fuel-issue-a-fuel-voucher
title: Issue and void a fuel voucher
module: fuel
order: 56
permissions: [fuel.voucher.view, fuel.voucher.manage]
roles: [station_manager]
screens: [/fuel/vouchers, /fuel/vouchers/:voucherId]
---
A voucher is prepaid fuel value. It is spent at the till as a payment and its balance goes down.

1. Open "Fuel vouchers". Search by code or filter by status.
2. Tap "New voucher".
3. Choose the "Currency" and type the "Value". The voucher can only be spent in that currency.
4. Leave "Code" empty to generate one. Choose the "Customer" if the voucher belongs to one, and the "Fuel type" if it is limited to one. Set "Expires on" if it has a last day.
5. Tap "Issue voucher". Tap "Open the voucher" to read it.
6. On the voucher page, "Redemptions" lists each time it was spent, with the amount and the date.
7. To cancel one, tap "Void voucher", type the "Reason" and confirm.

A void voucher cannot be spent. Its redemptions stay in the records. To spend a voucher at the pump, see "Sell fuel at a pump".
