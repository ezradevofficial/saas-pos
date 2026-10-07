# ADR 005: Styling across web and POS

Status: accepted (short note; Task 15 expands it)

## Decision

- **One token source.** `packages/tokens` compiles `design/tokens.json` into CSS variables, a Tailwind 4 theme for the web (`dist/tokens.css`, `dist/tailwind.css`), a Tailwind 3 preset (`dist/tailwind-v3-preset.js`) and per-theme variable maps for the POS (`dist/native-themes.js`). Both apps use the same class names (`bg-surface-200 text-ink rounded-md`) and never Tailwind's default palette or arbitrary values.
- **Web: Tailwind 4** with shadcn/ui, themed by CSS variables.
- **POS: Tailwind 3.4 + NativeWind 4.2.7**, because NativeWind 4 supports Tailwind 3 only. `pos/tailwind.config.js` uses `nativewind/preset` and `@app/tokens/tailwind-v3-preset`, and declares the light values on `:root` (NativeWind resolves only variables that exist in the stylesheet). Themes and tenant overrides are applied at runtime through `vars()` on the root view (`pos/src/theme/ThemeProvider.jsx`), so a white-label theme needs no new build.

## Deliberate React version split

- **web uses React 19.3** because `react-router@8.4` requires `react`/`react-dom >= 19.2.7`.
- **pos uses React 19.2.3**, pinned exactly by React Native 0.86 / Expo SDK 57. The RN renderer rejects any other React version.
- npm therefore installs two copies: one hoisted to the root and one nested.

How pos keeps to a single React:

- **Metro:** `pos/metro.config.js` resolves every `react`/`react-dom` import from `pos/`.
- **Autolinking:** `pos/package.json` sets `expo.autolinking.exclude: ["react", "react-dom"]`.
  - Neither package is a native module, so nothing native is affected.
  - Without this exclusion, `expo-doctor`'s duplicate-dependency check fails on the web copy. SDK 57's expo-doctor has no per-check exclusion for that check.
  - Autolinking's own React dedupe used to be what kept one copy in the bundle; this exclusion turns it off, which is why the Metro pin above is required.
  - I verified the web bundle with the pin in place: a single React 19.2.3.
- **Jest:** `pos/jest.config.js` maps `react`/`react-dom` to the copy that `require.resolve` finds from `pos/`. This works whether or not npm nests a copy.

**Rule:** when an Expo SDK pins React >= 19.2.7, put both apps on that version. Then remove:
- the Metro pin
- the autolinking exclusion
- the Jest mappers

## react-native-worklets override

The root `package.json` `overrides` pins `react-native-worklets` to the version the Expo SDK expects (0.10.1 for SDK 57). Without it, npm hoists a newer patch version, which react-native-reanimated's `0.10.x` peer range allows. Expo then reports a version mismatch and a duplicate native module. Bump this override together with the Expo SDK (`npx expo install --check` shows the expected version).
