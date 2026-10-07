# SaleTotal

The POS sale summary with subtotal, discount, VAT, total in one or two currencies, and the amber Charge button.

**Props the consumer provides:** `currency`, `subtotal`, `tax`, `total`, optional `discount`, `secondary` (second currency at the shop rate), `onPay`, translated `labels`.

**Do:** keep it always visible on the POS; show the second currency in the DRC.
**Don't:** add other buttons inside it; split payment and change are handled on the payment sheet.
