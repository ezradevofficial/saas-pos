# Performance and device support (NFR-02, NFR-03, NFR-11)

## API: p95 under 500 ms for standard reads (NFR-02)

Timing requests in CI is too noisy to gate on, so CI gates on what makes reads slow as data grows: the number of queries.

- **`ReadQueryBudgetTest`**: parties, users, data requests and `me` cost the same number of queries for 3 rows or 30 (no N+1) and stay within a budget per read. It found and fixed an N+1 in the privacy list.
- **`UploadQueryBudgetTest`** (POS): uploading sales costs a bounded number of queries per sale with every module on.
- **`SyncPerformanceTest`**, the webhook query budget and other module budgets guard their own paths.
- **Load test before each release**: `scripts/load/k6-reads.js` (k6, on the tester's machine) ramps to 50 virtual users on staging and fails when the p95 of standard reads is 500 ms or more or more than 1% fail. Record the result in the release notes.
- **In production**: the `Slow reads` alert (docs/observability.md) watches the same p95 from `http_request_duration_seconds`.

## Web: pages in under 2 s on a 3G-class connection (NFR-02)

- **Lazy routes.** Every page except the sign-in flow is its own chunk (`web/src/routes.jsx`), loaded the first time its route opens. Layouts render pages through `PageOutlet` (a Suspense boundary with a quiet loading line), so the shell stays on screen. The entry chunk fell from 3,292 kB (841 kB gzip) to 428 kB (120 kB gzip).
- **Budget in the build.** `npm run build -w web` ends with `scripts/bundle-budget.mjs`, which fails when the entry chunk (raw or gzip) or the first load (the entry, every chunk `index.html` preloads, and the CSS, gzipped) exceeds `web/bundle-budget.json`. CI's web job runs the build, so a heavy import reaching the shell fails the PR. Raise a budget only with a reason in the commit.
- **Sentry is not in the bundle**: it is loaded as its own chunk only when a DSN is set.
- **Only the active language.** The English and French catalogues are each their own chunk (`web/src/i18n/index.js`). `main.jsx` paints once the first paint's language (remembered, else the browser's, else English) has loaded; the other is fetched the first time the user switches, and the screen stays in the current language until it arrives (offline: nothing changes). The budget script counts the larger catalogue in the first load and fails if a catalogue stops being a lazy chunk. First load fell from 554 kB to 440 kB gzip (French; English 425 kB); the entry chunk is unchanged at 413 kB (118 kB gzip). `npm run check:i18n` still reads both files (L10N-02).
- **3G check before a release**: Chrome DevTools, Network "Slow 4G"/"3G" and CPU 4x slowdown, a cold cache, sign in and open Home, a list and a form; record the Largest Contentful Paint. Repeat warm (cached chunks): pages after the first must be under 2 s.

## POS speed: add an item and complete a cash sale under 1 s on a low-end Android (NFR-03)

### Regression guard (automated)

`pos/src/pos/perf.test.js` (Jest, in CI) times the till's own logic against budgets set for a CI machine, about ten times faster than a low-end phone:

| Case | Budget on CI |
| --- | --- |
| Build a 20,000-item catalogue with prices | 3 s |
| Find an item by barcode or typed code (first, middle, last) | 5 ms |
| Add an item and recompute totals on a 200-line cart (p95, and the last 50 adds) | 60 ms |
| Complete a 150-line cash sale (numbering, payload, outbox write) | 1.5 s |

It catches a change that makes adding or completing grow with the catalogue or the cart, not small drifts.

### On-device test (runbook, each release)

Device: a low-end Android phone of the kind tills use (for example 2 GB RAM, a 4-core Cortex-A53 class chip, Android 8 or 9), battery saver off, the release build (not a development build).

1. Pair the device with a staging tenant holding a realistic catalogue (at least 5,000 items with barcodes, 3 price lists, 200 customers).
2. Let the first sync finish. Then put the device in airplane mode for the offline half.
3. For each of online and offline, 20 times:
   - scan a barcode and stop the clock when the line shows with its total (**add item**);
   - with 10 lines in the cart, tap Charge, pay cash, and stop the clock when the receipt screen shows (**complete sale**).
4. Use a screen recording at 60 fps and count frames, or the Android Studio profiler's frame timeline.
5. Pass: the 95th percentile of each is under 1 s. Record device, build, results and any outlier in the release notes; a fail blocks the release.

## Browser and device support (NFR-11)

- **Web**: the latest two versions of Chrome, Edge, Firefox and Safari (desktop and iOS), declared as the `browserslist` in `web/package.json`. Vite's default build target (widely available baseline) is older than all of them, so no polyfills are needed. Check the four browsers at each release on the sign-in, Home, a list and a form.
- **POS**: Android 8 (API 26) and later. React Native 0.86's minimum SDK is 24 (Android 7); the app does not raise it. `pos/src/platformSupport.test.js` fails if React Native's default or an `expo-build-properties` override rises above 26. Test each release on the oldest supported version (Android 8) and a current one.
