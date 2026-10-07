# Select

A labelled native dropdown for choosing one option from a short list (under about 15 options).

**Props the consumer provides:** `label`, `options` (strings or `{value, label}`), `value`/`onChange` or `defaultValue`, optional `placeholder`, `help`, `error`.

**Do:** use for branches, currencies, payment terms, statuses.
**Don't:** use for long lists such as items or customers; those need a searchable picker.
