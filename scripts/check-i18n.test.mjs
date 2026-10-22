// L10N-02 (stage 7B closing review): the i18n check compares the API's
// en and fr catalogues too and refuses dotted key names. Run with
// `node --test scripts/check-i18n.test.mjs` (CI's i18n job).
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const script = join(dirname(fileURLToPath(import.meta.url)), 'check-i18n.mjs')

function tree(files) {
  const root = mkdtempSync(join(tmpdir(), 'i18n-'))
  const all = {
    'web/src/locales/en.json': '{"app": {"name": "Name"}}',
    'web/src/locales/fr.json': '{"app": {"name": "Nom"}}',
    'pos/src/locales/en.json': '{"app": {"name": "Name"}}',
    'pos/src/locales/fr.json': '{"app": {"name": "Nom"}}',
    'api/lang/en/demo.php': "<?php\n\nreturn [\n    'title' => 'Hello',\n    'name' => config('app.name'),\n    'errors' => ['missing' => \"Missing\"],\n];\n",
    'api/lang/fr/demo.php': "<?php\n\nreturn [\n    'title' => 'Bonjour',\n    'name' => config('app.name'),\n    'errors' => ['missing' => 'Manquant'],\n];\n",
    ...files,
  }
  for (const [path, content] of Object.entries(all)) {
    mkdirSync(dirname(join(root, path)), { recursive: true })
    writeFileSync(join(root, path), content)
  }
  return root
}

function check(files = {}) {
  const root = tree(files)
  try {
    const run = spawnSync(process.execPath, [script], { env: { ...process.env, I18N_ROOT: root }, encoding: 'utf8' })
    return { status: run.status, output: `${run.stdout}${run.stderr}` }
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
}

test('passes when every catalogue has the same keys', () => {
  const { status, output } = check()
  assert.equal(status, 0, output)
})

test('fails on an API key missing in French', () => {
  const { status, output } = check({ 'api/lang/fr/demo.php': "<?php\n\nreturn [\n    'title' => 'Bonjour',\n    'name' => config('app.name'),\n];\n" })
  assert.equal(status, 1)
  assert.match(output, /"demo\.errors\.missing" is in lang\/en but not in lang\/fr/)
})

test('fails on a dotted key name in the API and in the apps', () => {
  const api = check({
    'api/lang/en/demo.php': "<?php\n\nreturn ['title' => 'Hello', 'name' => 'x', 'errors' => ['missing' => 'Missing', 'a.b' => 'Dotted']];\n",
    'api/lang/fr/demo.php': "<?php\n\nreturn ['title' => 'Bonjour', 'name' => 'x', 'errors' => ['missing' => 'Manquant', 'a.b' => 'Pointé']];\n",
  })
  assert.equal(api.status, 1)
  assert.match(api.output, /lang\/en\/demo\.php has a dotted key name "errors > a\.b"/)

  const web = check({ 'web/src/locales/en.json': '{"app": {"name": "Name", "a.b": "Dotted"}}', 'web/src/locales/fr.json': '{"app": {"name": "Nom", "a.b": "Pointé"}}' })
  assert.equal(web.status, 1)
  assert.match(web.output, /web: en\.json has a dotted key name "app > a\.b"/)
})

// L10N-02: the mobile app's native permission texts (Expo per-locale strings).
const appJson = (locales, plugins = [['expo-camera', { cameraPermission: 'The camera scans barcodes.' }]]) =>
  JSON.stringify({ expo: { name: 'app', plugins, ...(locales ? { locales } : {}) } })
const both = { en: './locales/en.json', fr: './locales/fr.json' }

test('passes when every native permission text is in en and fr', () => {
  const { status, output } = check({
    'pos/app.json': appJson(both),
    'pos/locales/en.json': '{"ios": {"NSCameraUsageDescription": "The camera scans barcodes."}}',
    'pos/locales/fr.json': '{"ios": {"NSCameraUsageDescription": "L’appareil photo scanne les codes-barres."}}',
  })
  assert.equal(status, 0, output)
})

test('fails when the native strings lack a language, a permission text or a translation', () => {
  const noFrench = check({ 'pos/app.json': appJson({ en: './locales/en.json' }), 'pos/locales/en.json': '{"ios": {"NSCameraUsageDescription": "x"}}' })
  assert.equal(noFrench.status, 1)
  assert.match(noFrench.output, /expo\.locales has no "fr" file/)

  const missing = check({
    'pos/app.json': appJson(both, [['expo-location', { locationWhenInUsePermission: 'Your position is shared.' }]]),
    'pos/locales/en.json': '{"ios": {"NSLocationWhenInUseUsageDescription": "Your position is shared."}}',
    'pos/locales/fr.json': '{"ios": {}}',
  })
  assert.equal(missing.status, 1)
  assert.match(missing.output, /"ios\.NSLocationWhenInUseUsageDescription" \(a permission text set in app\.json\) is missing from the fr locale file/)

  const untranslated = check({
    'pos/app.json': appJson(both),
    'pos/locales/en.json': '{"ios": {"NSCameraUsageDescription": "The camera scans barcodes."}}',
    'pos/locales/fr.json': '{"ios": {"NSCameraUsageDescription": "The camera scans barcodes."}}',
  })
  assert.equal(untranslated.status, 1)
  assert.match(untranslated.output, /fr value for "ios\.NSCameraUsageDescription" is identical to en/)
})
