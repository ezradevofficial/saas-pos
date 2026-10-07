# Button

Buttons start an action; the label is a verb that names the result ("Approve", "Send for approval", "Charge USD 48.50").

**Variants**
- `primary`: the main action on a screen or dialog. One per view.
- `secondary` (default): every other action.
- `ghost`: low-emphasis actions such as Cancel, Return, Clear.
- `danger`: destructive actions (Void, Reject, Delete). Outlined until hovered; always confirm with a Dialog when it cannot be undone.
- `pay`: the decisive action, filled with `accent` (near-black by default). Only one on screen: POS Charge, Approve all, Complete.

**Props the consumer provides:** `children` (the label), `onClick`, optional `variant`, `size` (`lg` = 48px for POS and phones), `icon`, `block`, `loading`, `disabled`.

**Do:** keep labels short in English so the French version (about 25% longer) still fits; use `size="lg"` on touch screens.
**Don't:** use `pay` for anything but payment; put two `primary` buttons side by side; use "OK" or "Submit".
