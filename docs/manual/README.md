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
| `screens` | Web routes (`/settings/users`) or POS screens (`pos:SellScreen`). |

Lists are written inline as `[a, b]`. Values need no quotes. Keep the front matter identical in both languages except `title`.

### The body

- Short numbered steps, plain sentence case, one action per step.
- Use the real labels from the screens, in double quotes in English and in « guillemets » in French. Copy them from `web/src/locales/{en,fr}.json` (and `web/src/locales/modules`) and `pos/src/locales/{en,fr}.json`. Read the screen's code first so each step is true. Do not describe a feature that is not built.
- Follow the writing rules in `CLAUDE.md`: buttons are verbs naming the result, money shows its currency code first, errors say what happened and what to do, no emoji, no exclamation marks.
- Refer to another topic by its title, for example `See "Ask a manager to approve".`
- Never write the product name. Write `{{app_name}}`; the build replaces it with `APP_NAME`. It is the only placeholder.
- Use only characters in Windows-1252 (letters with accents, `«»`, `·`, `…` are fine). The PDF uses the core fonts. A test fails otherwise.
- Markdown supports headings (`##`), lists and tables. Raw HTML is escaped.
- Screenshots come later; leave them out for now.

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

A guide has a cover, a table of contents with page numbers, then the topics grouped by module. It is black on white, with captions and hairlines in the `ink-muted` and `border` tokens. The font stack is Geist, then Helvetica. The PDF engine only has Geist if you register it with dompdf's font loader; until then Helvetica is used.

The Owner and Admin share the owner and admin guides: the topics list `[owner, admin]`. Topics only the Owner can do (choosing modules, changing the plan) list `[owner]`.

## Checks

- `npm run check:i18n` (repository root): every topic exists in `en` and `fr`, with the same id, module, order, permissions, roles and screens.
- `php artisan test tests/Unit/Core/Manual`:
  - both languages have the same topics and ids;
  - every permission is in the catalogue and every role key is a role template;
  - only `{{app_name}}` is used, and only printable characters;
  - the cashier guide builds in each language as a valid PDF holding every topic title.

## Not covered yet

- The audit log has no screen in the web app yet, so there is no topic to read it. The access review export is covered by "Export the access review".
- Single sign-on, domains, API keys, webhooks and the other settings screens.
- Modules other than POS.
