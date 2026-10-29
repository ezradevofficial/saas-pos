# User manual

The manual is written once as topics. Today each role template gets a PDF built from the topics written for it. Later the same topics will be filtered by permission inside the app.

Each topic is one task, in one Markdown file, in both languages:

```
docs/manual/
  en/pos/sell.md
  fr/pos/sell.md
  en/rbac/invite-user.md
  fr/rbac/invite-user.md
```

The folder only helps writers find files. The topic's `module` in the front matter decides where it lands in the PDF.

## Write a topic

Start the file with front matter between two `---` lines, then the body.

```
---
id: pos-sell
title: Sell items
module: pos
order: 30
permissions: [pos.sale.create, pos.sale.view]
roles: [cashier, branch_manager]
screens: [pos:SellScreen]
---
Build the sale on the sell screen, then charge it.

1. Tap a product to add it to "Current sale".
2. Tap "Charge" with the amount shown.
```

| Key | Meaning |
| --- | --- |
| `id` | Stable, unique, the same in `en` and `fr`. |
| `title` | The task as a verb phrase, in sentence case. No colon. |
| `module` | `core` or a module key (`pos`, `inventory`, and so on). Topics are grouped by module, `core` first. The module's name in the PDF is `manual.modules.{module}` in `api/lang`; add it there for a new module. |
| `order` | Optional number. Lower comes first inside a module. |
| `permissions` | The real `module.resource.action` names the task needs. A test checks each one against the permission catalogue. Use `[]` when any signed-in user can do it. |
| `roles` | System role template keys (`api/app/Core/Rbac/role-templates.php`) the topic is written for. A test checks each key. A role's guide holds the topics that list it. |
| `screens` | Web routes (`/settings/users`) or POS screens (`pos:SellScreen`). The screenshot script captures the first web route that has no `:id` in it. |
| `image` | Optional. File name in `images/{locale}/` (for example `pos-sell.png`). Without it, `images/{locale}/{id}.png` is used when it exists. |
| `image_alt` | Optional. The picture's alt text, in the topic's language. Without it, "Screenshot of {title}" is used. |

Lists are written inline as `[a, b]`. Values need no quotes. Keep the front matter identical in both languages except `title`.

### The body

- Short numbered steps, plain sentence case, one action per step.
- Use the real labels from the screens, in double quotes in English and in « guillemets » in French. Copy them from `web/src/locales/{en,fr}.json` (and `web/src/locales/modules`) and `pos/src/locales/{en,fr}.json`. Read the screen's code first so each step is true. Do not describe a feature that is not built.
- Follow the writing rules in `CLAUDE.md`: buttons are verbs naming the result, money shows its currency code first, errors say what happened and what to do, no emoji, no exclamation marks.
- Refer to another topic by its title, for example `See "Ask a manager to approve".`
- Never write the product name. Write `{{app_name}}`; the build replaces it with `APP_NAME`. It is the only placeholder.
- Use only characters Geist can print (all of French and English: accents, `«»`, `·`, `…`, curly quotes). A test checks every topic against the font.
- Markdown supports headings (`##`), lists and tables. Raw HTML is escaped.
- Do not paste screenshots into the body. They come from the screenshot script (see "Screenshots") and sit under the title.

## Build the PDFs

From `api/`:

```
php artisan manual:build --role=cashier            # en and fr, one PDF each
php artisan manual:build --role=owner --locale=fr
php artisan manual:build --all                     # every topic, written to all.pdf
```

- `--role` is a system role template key. `--all` builds one guide with every topic. Give exactly one of them.
- `--locale` is `en` or `fr`. Leave it out for both.
- The PDFs go to `api/storage/app/manual/{locale}/{role}.pdf` (`all.pdf` for `--all`). They are not committed.
- The build reads `docs/manual` (`MANUAL_PATH` to change it) and the light colours of `design/tokens.json` (`MANUAL_TOKENS`).

A guide has a cover, a table of contents with page numbers, then the topics grouped by module. It is black on white, with captions and hairlines in the `ink-muted` and `border` tokens. A topic's screenshot sits under its title with a hairline border.

The PDF is set in Geist (SIL Open Font License, files and licence in `api/resources/fonts`), registered with dompdf in `ManualBuilder`. dompdf cannot switch on OpenType features such as tabular figures, so the numbers that must line up (page numbers in the contents and footer) use Geist Mono. If a font file is missing, the build falls back to Helvetica.

## Screenshots

Each topic with a web screen gets a picture of that screen, in English and French: `docs/manual/images/{locale}/{topic-id}.png`, cropped to the main content area, 1280 px wide, PNG. The images are committed. In the app the Help reader shows the picture (`GET /api/v1/manual/topics/{id}/image`, only for a user who can see the topic) and the PDF embeds it.

Topics that name a POS screen (`pos:`) are captured from the Expo app running on the web (react-native-web, 1280x800, a tablet size). Signed-out pages (sign-up, verify, two-factor enrolment) and the hotel housekeeping screen (its user cannot sign in at a till) have no picture.

To refresh them, from a local setup (never a shared database):

```
cd api && php artisan manual:demo-tenant     # once; safe to repeat. Local and testing only.
php artisan serve --port=8008                # API
cd ../web && npm run dev                     # web app on 3008
npm run web --workspace pos                  # POS on the web, port 3009 (for the POS pictures)
npx playwright install chromium              # first time only
npm run manual:screenshots                   # back-office screens, both languages
npm run manual:screenshots:pos               # POS screens, both languages
npm run manual:screenshots -- --locale=fr --topic=pos-sell   # one topic (the same options work for :pos)
```

- `manual:demo-tenant` creates the tenant "Demo Trading" (one company, two branches, every module) and one user per role template, `demo-{role}@demo.example.com` (underscores become hyphens), all with the password `Demo-Harbour-4242`, no second factor and the till PIN `739152`. It refuses to run unless `APP_ENV` is `local` or `testing`. It never reads a code from the mail log.
- It then fills the tenant with Kenyan sample data through the application's own API, signed in as demo users, so every rule and approval applies (`api/app/Core/Manual/Demo`, one section per module: stock at both branches, a purchase order received and one waiting for approval, POS sales and shifts, a chart of accounts with journals, employees with a payroll run and leave requests, hotel rooms and reservations, restaurant tables and open orders, pharmacy lots, a van route and load, a petrol and diesel forecourt with a shift variance, salon staff and appointments, and a `settings` section with a custom field, a form type, an automation rule, a webhook and an API key name so those screens are not empty; the webhook address is a placeholder that is never called and the API key's secret is not kept). Run one section with `--only=hotel`. Every section checks what exists first, so a repeat run adds nothing.
- The web script signs in as `demo-owner@demo.example.com`, sets the user's language to `en` or `fr`, opens the topic's route and saves the picture. A topic that names a "new" or "invite" form is captured with the form filled in. It uses the Chromium that Playwright installs (headless).
- The POS script pairs tills named "Manual till en" and "Manual till fr" (made once, paired again on each run) and a van till at the van's location, signs in with the PIN, and follows the steps in `web/scripts/manual-pos-recipes.mjs`. A recipe signs in as a user, reaches the screen and leaves it there. Run it with the fuel, salon and van topics last: the recipes close the shifts they open.
- Install `pngquant` (or set `MANUAL_PNGQUANT` to its path) before a refresh: the images then shrink to about a third. Without it they are only re-compressed.
- `MANUAL_WEB_URL`, `MANUAL_POS_URL`, `MANUAL_API_URL`, `MANUAL_DEMO_PASSWORD` and `MANUAL_DEMO_PIN` override the defaults.
- Commit the changed images with the topic change. Check a few by eye: a screen that shows an error or a spinner means the demo data or a route changed.

The Owner and Admin share the owner and admin guides: the topics list `[owner, admin]`. Topics only the Owner can do (choosing modules, changing the plan) list `[owner]`.

## Checks

- `npm run check:i18n` (repository root): every topic exists in `en` and `fr`, with the same id, module, order, permissions, roles and screens.
- `php artisan test tests/Unit/Core/Manual`:
  - both languages have the same topics and ids;
  - every permission is in the catalogue and every role key is a role template;
  - only `{{app_name}}` is used, and only printable characters;
  - every character of every topic exists in Geist;
  - the cashier guide builds in each language as a valid PDF set in Geist holding every topic title;
  - the screenshot route (`tests/Feature/Core/Manual/ManualImageTest.php`): a visible topic's image is served, a hidden topic's is 404, signed out is 401.

- `npm run check:manual` (CI runs it in the web job): every route of `web/src/routes.jsx` (including nested and generated route tables) is in some topic's `screens`, or in `docs/manual/route-exclusions.json` with a reason. Exclusions are for development, platform-staff, OAuth, redirect and technical pages. An entry marked `"backlog": true` is a page that still needs a topic: remove it when you write one. The check also fails when a topic names a web screen that is not a route, so the help "?" (which matches the page's path against `screens`, `:param` segments matching anything) always finds its topics.

Pages for the business's own customers or suppliers (the online booking page, the shop, the supplier portal) are written as topics for the owner and admin, so they learn what those people see. Signed-out pages (sign-in, reset, invitation, email approval) have no picture.

## Not covered yet

- The audit log has no screen in the web app yet, so there is no topic to read it. The access review export is covered by "Export the access review".
- The sandbox reset and outbox on the API keys screen, the platform console, and the pages of the developer portal (`/developers`).

## Demo data for procurement, stores, fixed assets and manufacturing

`manual:demo-tenant --only=procurement_more`, `--only=stores`, `--only=fixed_assets` and `--only=manufacturing` fill those modules (an approved requisition, a request for quotation with quotes, a published tender, a received purchase order with a matched invoice, a store requisition issued and part returned, assets with two depreciation runs, a BOM and a production order in progress). Approvals are created by a queued listener; the command runs the queue inline, so no worker is needed. The supplier portal pages are signed-out pages and have no picture.

## Demo data for contacts, invoices, payroll loans and HR links

`manual:demo-tenant --only=accounting_more`, `--only=payroll_more` and `--only=contacts` add a bank account, a posted invoice and bill, a receipt, an operating budget, a salary advance and a credit limit request (repeat `--only` for several). The `hr` section links the demo owner to the first employee through HR's own API, as the HR officer, so the self-service and "my payslips" pictures show a person instead of the "not linked" notice.
