# Accessibility (NFR-09)

Target: WCAG 2.1 AA for the back office; large touch targets on the POS.

## Automated

- **Lint (CI)**: oxlint's `jsx-a11y` plugin with its correctness rules as errors (`web/.oxlintrc.json`): alt text, valid ARIA roles and properties, roles with their required properties (comboboxes name their listbox), labels tied to controls, no positive tab index, no handlers on non-interactive elements without a keyboard path, no autofocus traps, headings and links with content. shadcn's generated primitives and test files are exempt from the interaction rules. `prefer-tag-over-role` is off: `role="status"` on a paragraph is valid.
- **Reviewed exceptions** (annotated in code): the chart is focusable on purpose (arrow keys move its tooltip); the CRM board's drag targets (every card also has move buttons); the storefront's payment labels wrap their radio and text.
- **Contrast**: the tokens tests check every text and background pair of the four themes, and the theme editor refuses tenant colours under AA (BR-03).
- **Component tests** query by role and accessible name (Testing Library), so a control without a name fails its test.
- An axe-based browser check is not available without a new dependency; the manual checklist below covers what lint cannot.

## Manual checklist (each release, on Home, a list, a form, a dialog, Settings → Privacy and the POS sell screen)

1. **Keyboard only**: every action reachable with Tab, Shift+Tab, Enter, Space and arrows; focus always visible (the `focus` ring token); no trap (dialogs return focus to their trigger on close; Escape closes).
2. **Order**: focus follows the visual order; skip to the main content from the shell.
3. **Names**: every button and field has a visible label or an accessible name that matches it; icon-only buttons have `aria-label`.
4. **Status**: status is a dot plus a word, never colour alone; errors say what happened and what to do, and are announced (`role="alert"` or tied to the field with `aria-describedby`).
5. **Zoom and reflow**: 200% zoom and 320 px width without horizontal scrolling or lost content.
6. **Contrast**: check any new colour use in light and dark with the browser's contrast picker (4.5:1 text, 3:1 large text and controls).
7. **Screen reader**: VoiceOver (Safari) or NVDA (Firefox): sign in, open a list, filter, open a record, submit a form with an error.
8. **Motion**: nothing flashes; animations respect "reduce motion".
9. **POS**: touch targets at least 48 px; the sell screen works with large text (Android font size largest); TalkBack reads tiles, totals and the Charge button.

Record the result in the release notes; an AA failure on a main screen blocks the release.
