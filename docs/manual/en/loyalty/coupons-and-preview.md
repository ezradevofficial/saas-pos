---
id: loyalty-coupons-and-preview
title: Add coupon codes and preview a promotion
module: loyalty
order: 40
permissions: [loyalty.promotion.view, loyalty.promotion.edit]
roles: [owner, admin, branch_manager]
screens: [/loyalty/promotions/:promotionId]
---
Open a promotion that "Needs a coupon code".

1. In the "Coupons" card, type the codes under "Codes", separated by spaces or commas. Or use "Or generate" to have codes made for you.
2. Set "Uses per code", or leave it empty for no limit. Tap "Add codes".
3. The list shows each "Code", its "Uses" and its "State" ("Usable" or "Archived"). Tap "Archive" to stop a code. Uses are counted when sales reach the server.
4. To test the promotion, use the "Preview" card: add the "Item", "Quantity" and "Unit price" of each line, choose the "Branch", the date under "At" and a "Coupon code" if needed, and tap "Preview".
5. The result shows what applies and the "Total discount", or "Nothing applies to this cart."
