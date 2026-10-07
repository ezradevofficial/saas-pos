A quiet, precise business platform for Kenya and the DR Congo: near-white surfaces, graphite type, hairline borders, a deep muted teal used sparingly, and near-black for the one decisive action. It should feel calm and expensive, never playful.

> The product has no name yet. Never write a product name in designs or code; use "Product name" as the placeholder and read the real name from one setting.

## Principles

- **Quiet by default.** Most of every screen is `surface-100`, `surface-200`, `ink` and `ink-muted`. Colour is information, never decoration.
- **Hairlines, not boxes.** Separate with `border` lines and space. Cards have a 1px `border` and no shadow; shadows only for floating layers (`shadow-lg`).
- **One brand colour, used sparingly.** `primary` marks links, the main button and the highlighted data point. If more than a few things on a screen are `primary`, remove some.
- **One decisive action.** `accent` (near-black by default) is only for the action that finishes the job: Charge, Approve all, Complete. One per screen.
- **Status is a dot and a word.** Never a bright filled pill; never colour alone.
- **Readable at arm's length on the POS.** POS and phone screens use `body-lg` and touch targets of at least `space-12` (48px).
- **Everything is a token.** Customers re-theme the product (see Customisation), so components never hard-code a colour, font, size, radius or shadow.

## Content fundamentals

- English and French with equal care; every string from translation files. French runs 20–30% longer: never size a button to its English label.
- Sentence case. Address the user as "you"; the product never says "I" or "we".
- Buttons are verbs naming the result: "Approve", "Charge USD 48.50". Never "OK" or "Submit".
- Money always carries its currency code first: "KES 12,450.00", "USD 48.50", "CDF 135,000". In dual-currency views the selling currency comes first.
- Errors say what happened and what to do next. No emoji, no exclamation marks.
- Dates: "7 Oct 2026" / "7 oct. 2026"; 24-hour times.

## Colour

- Page `surface-100`; cards, tables, dialogs and POS tiles `surface-200` with a `border` hairline; segmented controls and disabled inputs `surface-300`.
- Text `ink`; labels, captions, table headers and help `ink-muted`.
- Control outlines (inputs, checkboxes, switches, secondary buttons) use `border-strong`, which meets 3:1.
- Primary button: `primary` fill, `on-primary` text, `primary-hover` on hover. Links: `primary` text. Selected rows: `primary-tint`. Chart: `primary-tint` bars with the one highlighted value in `primary`.
- Decisive action: `accent` fill, `on-accent` text, `accent-hover` on hover.
- Promotions, discounts and loyalty points as text: `accent-ink`.
- Status dots: `success`, `warning`, `danger`, `neutral-dot`. Alerts use the matching `-tint` background with `ink` text.
- Destructive buttons are outlined in `border-strong` with `danger` text, filling with `danger` only on hover; confirm in a Dialog.
- Navigation: `sidebar` background, `sidebar-ink` labels and icons, `sidebar-active` and `sidebar-ink-active` for the current item, `sidebar-border` for its dividers.
- Focus: a solid 2px `focus` outline with 2px offset on every interactive element.

## Typography

- Geist (`--font-sans`) for everything, including numbers, with tabular figures so columns align. Geist Mono (`--font-mono`) only for codes such as receipt numbers in technical views.
- Weights 400 and 500 for nearly everything; 600 only for `h1`, `h2` and dialog titles. Never 700.
- Page titles `h1`, section titles `h2`, card titles `h3`. Hero figures `display`.
- Back office `body` (13px); POS and phone `body-lg` (15px); buttons and labels `label`; help, timestamps and table headers `caption`. Nothing smaller than `caption`.
- Money in tables `amount`; amount due, totals and KPIs `amount-lg`. The currency code is set in `ink-muted` at regular weight beside the figure.

## Spacing, radius and elevation

- Spacing only from `space-1` to `space-12`. Card padding `space-5`; between cards `space-5`; between sections `space-6`; desktop page padding `space-10`; phone gutter `space-4`.
- Corners: `radius-md` (6px) for buttons, inputs, nav items and POS tiles; `radius-lg` (10px) for cards, dialogs and sheets; `radius-sm` for badges and checkboxes; `radius-pill` for dots, switches and avatars.
- Elevation: no card shadows. `shadow-sm` only for the selected segment of a segmented control; `shadow-lg` for dialogs, menus and the payment sheet.

## Layout

- Back office: sidebar (232px) with the logo block on top, separated by `sidebar-border`, then grouped navigation with small group labels; content on `surface-100`, max width about 1280px, `space-10` padding. Navigation items are 32px tall, 13px, with 16px line icons.
- Dashboards lead with one row of KPI cells divided by hairlines, then a chart and the work waiting for the user.
- POS: product grid on the left, sale and amount due on the right on tablets, a bottom sheet on phones. The amount due and the Charge button are always visible.
- Printed documents are black on white whatever the theme; the tenant's logo is the only brand element.

## Customisation (white-label, layouts, templates)

Tenants make the product look like their own. The design system makes this safe: they change token values and saved layouts, never components.

- **Theme presets.** Every tenant starts on Light. They can switch to Dark, or to one of the presets shipped as extra themes in `tokens.json`: **Executive** (graphite sidebar, emerald brand, gold decisive action, sharper feel) and **Warm** (paper tones, bronze brand, ink-black action). Each preset is just a different set of token values.
- **Brand overrides.** On top of a preset, a tenant may set: logo (light and dark), `primary` and its pair (`primary-hover`, `on-primary`, `primary-tint`), `accent` and its pair, sidebar light or dark, and corner style (sharp 4/6px, standard 6/10px, soft 8/14px, applied to `radius-md` and `radius-lg`). The theme editor derives hover and tint values, checks contrast against `surface` and `on-` tokens, and refuses to publish a pair under 4.5:1.
- **Font choice.** A curated list only: Geist (default), IBM Plex Sans (paired with Executive), Newsreader for display with Geist for text (paired with Warm). The choice sets `--font-sans` and `--font-display`; sizes and weights stay the same.
- **Not overridable:** status colours, spacing, type sizes and the focus ring. These keep every tenant legible and consistent.
- **Layouts.** Dashboards, navigation (order, names, visibility per role), form layouts, list views and the POS screen are arranged in the layout designers and saved as versioned configuration. Components render whatever layout is saved; new fields arrive hidden or in a default position.
- **Document templates.** Receipts (58/80mm), invoices, quotes, POs, delivery notes and payslips are designed in the template designer: logo, fields, terms, QR codes, English, French or both. Printed documents stay black on white; fiscal elements (KRA eTIMS, DRC DGI data, tax lines, QR codes) are locked and cannot be removed.
- **Scope.** A theme applies to the tenant, and can be overridden per company or branch.

## Components

- Build screens from the components in this system (`window.DS`, React 18): Button, TextField, Select, Checkbox, Switch, StatusBadge, Alert, SyncStatus, Money, KpiTile, DataTable, Card, Tabs, Dialog, PosTile, SaleTotal, StageTracker, ApprovalCard. Read each one's guidelines before using it.
- Show every amount with `Money` (or `formatAmount`); CDF has no decimals.
- Map every workflow stage to a `StatusBadge` tone instead of inventing colours.
- The POS screen always shows `SyncStatus` in its header and `SaleTotal` with the `pay` button.

## Iconography

- Lucide-style line icons: 24px grid drawn at 16px in navigation and 18px in tools, 1.5px stroke, round caps and joins, in `ink`, `ink-muted` or `sidebar-ink`. Flagged: confirm Lucide as the icon set.
- Icons sit `space-2` from their label. An icon-only button needs an accessible label and a tooltip.

## Logo

- No logo exists yet. Use a simple neutral mark (an `ink` square with a small `surface-100` square inside) and the words "Product name" in `h3` until one is designed. A tenant's logo replaces both under white-label.
