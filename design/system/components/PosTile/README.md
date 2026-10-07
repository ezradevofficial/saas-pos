# PosTile

A tappable product button on the POS grid, showing name, price and low or out-of-stock state.

**Props the consumer provides:** `name`, `price`, `currency`, `onSelect`, optional `stock`, `lowStock` threshold, `image`, `color` (category colour shown as a small dot, from the customer's POS layout).

**Do:** let the customer's POS layout decide order, size and category colours; keep names under two lines.
**Don't:** hide out-of-stock items without a setting; show cost price.
