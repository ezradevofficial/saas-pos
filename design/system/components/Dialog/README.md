# Dialog

A focused window for a decision or a short form that must be finished before going back to the page.

**Props the consumer provides:** `open`, `title` (a question for confirmations), `onClose`, `footer` (Buttons, the confirming one last), `children`, optional `size`.

**Do:** name the consequence in the body; label buttons with what they do ("Void sale", "Keep sale").
**Don't:** open a dialog from a dialog; use for long forms (use a page).
