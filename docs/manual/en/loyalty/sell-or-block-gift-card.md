---
id: loyalty-sell-or-block-gift-card
title: Sell or block a gift card
module: loyalty
order: 60
permissions: [loyalty.gift_card.view, loyalty.gift_card.sell, loyalty.gift_card.block]
roles: [owner, branch_manager]
screens: [/loyalty/gift-cards, /loyalty/gift-cards/:cardId]
---
1. Open "Gift cards and vouchers" and tap the card.
2. To sell a card that shows "Not sold", take the customer's payment first. Under "Sell this card" type the "Payment reference" and tap "Sell for <amount>". The value is loaded once the customer has paid.
3. To stop a lost card being used, tap "Block". To allow it again, tap "Unblock".
4. "Card history" lists every use of the card.
