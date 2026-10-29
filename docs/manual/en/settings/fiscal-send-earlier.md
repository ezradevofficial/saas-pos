---
id: fiscal-send-earlier
title: Send earlier sales to the tax authority
module: core
order: 800
permissions: [core.fiscal.view, core.fiscal.edit]
roles: [owner, admin, accountant]
screens: [/settings/fiscal]
---
Sales, refunds and voids made before sending was switched on are not sent on their own.

Warning: this sends real tax documents to the authority, and they can't be unsent. Choose the first day carefully. Documents already sent stay as they are.

1. Open Finance, then "Tax authority".
2. Choose the company in the company switcher.
3. Tap "Send earlier sales".
4. Choose the first day under "Send documents from".
5. Tick "I understand these documents go to the tax authority".
6. Tap "Send to the authority".

The documents are queued and sent one by one. Follow them in the list under "Documents sent", where a rejected or stuck document can be retried.
