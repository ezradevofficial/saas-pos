# TextField

A labelled single-line input with optional currency prefix, help text and error message.

**Props the consumer provides:** `label`, `value`/`onChange` (or `defaultValue`), optional `prefix`/`suffix` (currency codes, units), `help`, `error`, `required`, `disabled`, and any native input attribute.

**Do:** always give a visible `label`; put the currency code in `prefix` for money; write errors that say how to fix it ("A KRA PIN has 11 characters").
**Don't:** use placeholder text as the label; show an error before the user has left the field.
