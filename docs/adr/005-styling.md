# ADR 005: Styling across web and POS

Status: Accepted (Sprint 1, BR-01; Tasks 2, 11, 13)

## Context

- **Shared look.** The web back office and the POS must look like one product, and both must be themeable per tenant at runtime, without a new build (BR-01 to BR-08). The design system defines the tokens: colour, type, spacing, radius and shadow. Components may use nothing else. That rules out Tailwind's default palette and arbitrary values.
- **Different stacks.**
  - The web uses Tailwind CSS with shadcn/ui.
  - The POS uses React Native through Expo, styled with NativeWind.
  - NativeWind supports one specific Tailwind major version, which may differ from the web's.
- **Tenant overrides.** Tenants may override only some tokens: logo, `primary` and its pair, `accent` and its pair, the `sidebar-*` set, corner style, and font from a curated list. Status colours, spacing, type sizes and the focus ring are never overridable.

## Decision

### One token source, one package

`packages/tokens` (`@app/tokens`) compiles `design/tokens.json` once (`npm run build -w @app/tokens`). The build output is committed, and CI fails when `dist/` is stale (ADR 001).

| Output | Used by | What it holds |
| --- | --- | --- |
| `dist/tokens.css` | web | CSS variables for Light (`:root`), Dark (`.dark`), and the Executive and Warm presets. Variable names equal token names (`--surface-200`, `--ink`, `--primary`). |
| `dist/tailwind.css` | web | A Tailwind 4 `@theme inline reference` block mapping utilities to those variables |
| `dist/tailwind-v3-preset.js` | POS | A Tailwind 3 preset with the same class names, reading the same variables |
| `dist/native-themes.js` | POS | Per-theme variable maps for NativeWind's `vars()` |
| `dist/tokens.json` | both | The resolved tokens |
| `src/index.js` | both | `themes`, `OVERRIDABLE_TOKENS`, `deriveBrandPair` (hover and tint derivation), `contrastRatio` and `meetsAA` (BR-03) |

Both apps use the same class names (`bg-surface-200 text-ink border-border rounded-md`). They never use Tailwind's default palette or arbitrary values.

### Web: Tailwind 4 and shadcn/ui

- **Tailwind 4.3** through `@tailwindcss/vite`. `web/src/index.css` imports `@app/tokens/tokens.css`, then `@app/tokens/tailwind.css`.
- **The theme block is `@theme inline reference`.** Tailwind inlines `var(--token)` into each utility and emits no `:root` theme variables of its own. As a result:
  - there is no self-referential variable cycle
  - nothing depends on cascade order
  - a runtime override of `--primary` reaches every utility
- **Font weights are explicit.** Only `normal` (400), `medium` (500) and `semibold` (600) exist. Tailwind's default `--font-weight-*`, `--leading-*` and `--tracking-*` scales are reset. A Tailwind 4 compile smoke test in the tokens package guards all of this.
- **shadcn/ui 4** uses the `radix-nova` style, `"tsx": false` (the components are `.jsx`) and `cssVariables: true`. Primitives are added with the CLI and kept close to stock. Our own behaviour lives in `web/src/components/ds/` wrappers.
- **shadcn's variables map to the tokens** in `web/src/index.css`, on every theme scope:

  | shadcn variable | Token |
  | --- | --- |
  | `--background` | `surface-100` |
  | `--card`, `--popover` | `surface-200` |
  | `--muted`, `--accent` | `surface-300` (shadcn's "accent" is a hover tint; our `accent` is the near-black decisive action) |
  | `--foreground` | `ink` |
  | `--muted-foreground` | `ink-muted` |
  | `--primary` | `primary` |
  | `--destructive` | `danger` |
  | `--ring` | `focus` |
  | `--radius` | `radius-md` |
  | `--border` | not remapped: it is already our hairline `border` token, which cards and tables need |
  | `--input` | `border-strong`, so inputs keep the stronger outline |

  The border mapping is a ruling from Task 11. CLAUDE.md's line "`--border` → `border-strong`" is superseded and awaits the owner's edit.
- **Runtime theming.**
  - `web/src/theme/ThemeProvider.jsx` switches the theme class.
  - `applyTenantTheme.js` writes only `OVERRIDABLE_TOKENS` as inline CSS variables on the root element. This is the one permitted inline style.

### POS: Tailwind 3.4 and NativeWind 4.2.7

- **NativeWind 4.2.7** with **Tailwind 3.4**. NativeWind 4 supports Tailwind 3 only, and NativeWind 5, which targets Tailwind 4, was still a release candidate when Sprint 1 shipped.
- **The token package bridges the two majors** by generating a preset for each. Both apps still share one token source.
- **Configuration.** `pos/tailwind.config.js` uses `nativewind/preset` and `@app/tokens/tailwind-v3-preset`. It also declares the light values on `:root`, because NativeWind resolves only variables that exist in the stylesheet.
- **Runtime theming.** Themes and tenant overrides go through `vars()` on the root view (`pos/src/theme/ThemeProvider.jsx`), fed by `@app/tokens/native-themes` and `OVERRIDABLE_TOKENS`. A white-label theme needs no new build.
- **Components.** shadcn/ui is web-only. The 18 design-system components are built again in `pos/src/components/ds` with NativeWind, matching the web versions' props.
- **Upgrade rule.** Move to NativeWind 5 and Tailwind 4 once NativeWind 5 is stable. Then drop `tailwind-v3-preset` and use `tailwind.css` in both apps.

## Deliberate React version split

- **web uses React 19.3**, because `react-router@8.4` requires `react`/`react-dom >= 19.2.7`.
- **pos uses React 19.2.3**, pinned exactly by Expo SDK 57 / React Native 0.86. The React Native renderer ships built for that exact React version, and `npx expo install --check` requires it.
- npm therefore installs two copies: one hoisted to the root and one nested.

How pos keeps to a single React:

- **Metro.** `pos/metro.config.js` resolves every `react`/`react-dom` import from `pos/`. The pin protects the native (Android/iOS) bundles as well as the web preview.
- **Autolinking.** `pos/package.json` sets `expo.autolinking.exclude: ["react", "react-dom"]`.
  - Neither package is a native module, so nothing native is affected.
  - Without this exclusion, `expo-doctor`'s duplicate-dependency check fails on the web copy. SDK 57's expo-doctor has no per-check exclusion for that check.
  - Autolinking's own React dedupe used to keep one copy in the bundle. This exclusion turns it off, which is why the Metro pin above is required.
  - Verified with the pin in place: the web bundle contains a single React 19.2.3. The native bundles were not inspected.
- **Jest.** `pos/jest.config.js` maps `react`/`react-dom` to the copy that `require.resolve` finds from `pos/`. This works whether or not npm nests a copy.

**Rule:** when an Expo SDK pins React >= 19.2.7, put both apps on that version. Then remove:
- the Metro pin
- the autolinking exclusion
- the Jest mappers

No automated guard ties these three together yet, so check all three on every Expo SDK upgrade.

## react-native-worklets override

The root `package.json` `overrides` pins `react-native-worklets` to the version the Expo SDK expects (0.10.1 for SDK 57). Without it, npm hoists a newer patch version, which react-native-reanimated's `0.10.x` peer range allows. Expo then reports a version mismatch and a duplicate native module. Bump this override together with the Expo SDK (`npx expo install --check` shows the expected version).

## Consequences

- A token change is one edit to `design/tokens.json` and one rebuild, and both apps follow. CI catches a forgotten rebuild.
- There are two Tailwind majors in one repository until NativeWind 5 is stable. Class names stay the same, but some utilities differ between v3 and v4. Shared components must use only utilities that exist in both.
- Some Tailwind built-ins remain in use and are not tokens: `ring-3`, `mt-px`, `h-px`, `border-b-2`, and lucide's numeric icon sizes. They are tracked for a later token pass.
- POS category colours are token names from a fixed set (`primary-tint`, `surface-300`, `success-tint`, `warning-tint`, `danger-tint`), chosen in the POS layout (LAY-05) and mapped to token classes in `pos/src/pos/layout.js`; `PosTile` takes no typed colour and no inline style. There is no `accent-tint` token yet.
- The till applies the published theme at runtime: `TillThemeProvider` reads `settings.theme` after each pull and feeds `vars()` with the preset for the mode plus the compiled overrides; the device's appearance (as the device, light or dark) is kept on the till.
- On native, only Geist Regular is bundled so far. Medium weights fall back until the font files are added.
