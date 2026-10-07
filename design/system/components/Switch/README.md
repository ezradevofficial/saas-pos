# Switch

A setting that applies immediately when toggled, such as turning a feature on for a branch.

**Props the consumer provides:** `checked`, `onChange(next)`, `label`, optional `disabled`.

**Do:** save immediately and confirm with a short message.
**Don't:** use inside a form that needs a Save button; use Checkbox there.
