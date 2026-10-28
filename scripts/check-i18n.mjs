// Missing-key check (L10N-02). Plain Node, no dependencies.
// Fails when en/fr key sets differ, a literal t('key') is missing from a
// catalogue, or an fr value is empty or identical to en (longer than 3
// characters) and not listed in scripts/i18n-same-allowlist.json.
// Stage 7B closing review: the API's catalogues (api/lang/{en,fr}/*.php,
// read by a small parser of PHP array literals, and api/lang/{en,fr}.json)
// must have the same keys too, and no catalogue may use a dotted key name
// (a dot is the path separator: "a.b" as one key can never be looked up).
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

// I18N_ROOT: another tree to check (the script's own test, check-i18n.test.mjs).
const root = process.env.I18N_ROOT ?? join(dirname(fileURLToPath(import.meta.url)), '..')
const allowlistFile = join(root, 'scripts/i18n-same-allowlist.json')
const allowlist = new Set(existsSync(allowlistFile) ? JSON.parse(readFileSync(allowlistFile, 'utf8')) : [])

const apps = [
  { name: 'web', locales: 'web/src/locales', sources: ['web/src'], extensions: ['.js', '.jsx', '.ts', '.tsx'] },
  { name: 'pos', locales: 'pos/src/locales', sources: ['pos/src', 'pos/App.js'], extensions: ['.js', '.jsx', '.ts', '.tsx'] },
]

const isTest = (path) => /\.(test|spec)\.[jt]sx?$/.test(path) || /(^|\/)(__tests__|test)\//.test(path)

function flatten(object, prefix = '') {
  const result = {}
  for (const [key, value] of Object.entries(object)) {
    if (value !== null && typeof value === 'object') Object.assign(result, flatten(value, `${prefix}${key}.`))
    else result[`${prefix}${key}`] = value
  }
  return result
}

function sourceFiles(path, extensions) {
  const full = join(root, path)
  if (!existsSync(full)) return []
  if (statSync(full).isFile()) return [path]
  return readdirSync(full).flatMap((entry) => {
    const child = join(path, entry)
    if (statSync(join(root, child)).isDirectory()) return entry === 'node_modules' ? [] : sourceFiles(child, extensions)
    return extensions.some((ext) => entry.endsWith(ext)) && !isTest(child) ? [child] : []
  })
}

// Matches t('a.b'), i18n.t("a.b"), i18nKey="a.b" is not covered on purpose.
const keyPattern = /(?<![\w$])t\(\s*(['"])([^'"`$]+)\1/g

// i18next plurals: t('a.b', { count }) resolves a.b_one / a.b_other, so a key
// is present when it exists itself or as its plural "_other" form.
const hasKey = (catalogue, key) => key in catalogue || `${key}_other` in catalogue

const errors = []

// Keys containing a dot, at any depth, in a nested catalogue.
function dottedKeys(object, prefix = '') {
  return Object.entries(object).flatMap(([key, value]) => [
    ...(key.includes('.') ? [`${prefix}${key}`] : []),
    ...(value !== null && typeof value === 'object' ? dottedKeys(value, `${prefix}${key} > `) : []),
  ])
}

// A PHP file that returns an array literal of strings, numbers, booleans,
// null and nested arrays (the API's lang files), as a plain object. Anything
// else is an error: lang files hold data only.
function parsePhpArrayFile(file) {
  const text = readFileSync(join(root, file), 'utf8')
  let i = 0
  const fail = (what) => {
    throw new Error(`${file}: ${what} at offset ${i}`)
  }
  const skip = () => {
    for (;;) {
      if (/\s/.test(text[i] ?? '')) i += 1
      else if (text.startsWith('//', i) || text[i] === '#') i = text.indexOf('\n', i) === -1 ? text.length : text.indexOf('\n', i)
      else if (text.startsWith('/*', i)) i = text.indexOf('*/', i) + 2
      else return
    }
  }
  const expect = (token) => {
    skip()
    if (!text.startsWith(token, i)) fail(`expected ${token}`)
    i += token.length
  }
  const string = () => {
    const quote = text[i]
    i += 1
    let out = ''
    while (text[i] !== quote) {
      if (i >= text.length) fail('unterminated string')
      if (text[i] === '\\') {
        const next = text[i + 1]
        if (quote === "'") out += next === "'" || next === '\\' ? next : `\\${next}`
        else out += { n: '\n', t: '\t', '"': '"', '\\': '\\', $: '$' }[next] ?? `\\${next}`
        i += 2
      } else {
        if (quote === '"' && text[i] === '$') fail('interpolation in a lang string')
        out += text[i]
        i += 1
      }
    }
    i += 1
    return out
  }
  const value = () => {
    skip()
    const c = text[i]
    if (c === "'" || c === '"') return string()
    if (c === '[') return array()
    // A call such as config('app.name'): kept as an opaque value (its parentheses balanced).
    const call = /^[A-Za-z_\\][\w\\]*\s*\(/.exec(text.slice(i))
    if (call) {
      i += call[0].length - 1
      let depth = 0
      do {
        if (text[i] === "'" || text[i] === '"') {
          string()
          continue
        }
        if (text[i] === '(') depth += 1
        if (text[i] === ')') depth -= 1
        i += 1
      } while (depth > 0 && i < text.length)
      return '(expression)'
    }
    const word = /^(-?\d+(?:\.\d+)?|true|false|null)/.exec(text.slice(i))
    if (!word) fail('unsupported value')
    i += word[0].length
    return word[0] === 'true' ? true : word[0] === 'false' ? false : word[0] === 'null' ? null : Number(word[0])
  }
  const array = () => {
    expect('[')
    const out = {}
    let index = 0
    for (;;) {
      skip()
      if (text[i] === ']') {
        i += 1
        return out
      }
      const first = value()
      skip()
      if (text.startsWith('=>', i)) {
        i += 2
        out[String(first)] = value()
      } else {
        out[String(index)] = first
        index += 1
      }
      skip()
      if (text[i] === ',') i += 1
      else if (text[i] !== ']') fail('expected , or ]')
    }
  }
  expect('<?php')
  skip()
  if (text.startsWith('declare', i)) i = text.indexOf(';', i) + 1
  expect('return')
  const result = array()
  expect(';')
  return result
}

// The API: each lang file in en and fr, and the JSON catalogues.
const api = { en: {}, fr: {} }
for (const locale of ['en', 'fr']) {
  const dir = join(root, 'api/lang', locale)
  for (const entry of (existsSync(dir) ? readdirSync(dir) : []).filter((name) => name.endsWith('.php')).sort()) {
    try {
      api[locale][entry.replace(/\.php$/, '')] = parsePhpArrayFile(join('api/lang', locale, entry))
    } catch (error) {
      errors.push(`api: ${error.message}`)
    }
  }
  const json = join(root, 'api/lang', `${locale}.json`)
  if (existsSync(json)) api[locale]['(json)'] = JSON.parse(readFileSync(json, 'utf8'))
}
{
  const en = flatten(api.en)
  const fr = flatten(api.fr)
  for (const key of Object.keys(en)) if (!(key in fr)) errors.push(`api: "${key}" is in lang/en but not in lang/fr`)
  for (const key of Object.keys(fr)) if (!(key in en)) errors.push(`api: "${key}" is in lang/fr but not in lang/en`)
  for (const [key, value] of Object.entries(fr)) if (typeof value === 'string' && value.trim() === '' && en[key] !== '') errors.push(`api: fr value for "${key}" is empty`)
  for (const locale of ['en', 'fr']) {
    for (const [file, catalogue] of Object.entries(api[locale])) {
      // JSON catalogues are keyed by the English text itself (Laravel's __('Sentence.')): dots are allowed there.
      if (file === '(json)') continue
      for (const key of dottedKeys(catalogue)) errors.push(`api: lang/${locale}/${file}.php has a dotted key name "${key}"`)
    }
  }
}

// A catalogue: <locales>/<locale>.json plus the per-module chunks in
// <locales>/modules/<locale>/<module>.json (web, NFR-02), each holding one
// top-level key named after its file and not repeated in the main file.
function readCatalogue(app, locale) {
  const tree = JSON.parse(readFileSync(join(root, app.locales, `${locale}.json`), 'utf8'))
  const dir = join(root, app.locales, 'modules', locale)
  for (const entry of (existsSync(dir) ? readdirSync(dir) : []).filter((name) => name.endsWith('.json')).sort()) {
    const module = JSON.parse(readFileSync(join(dir, entry), 'utf8'))
    const name = entry.replace(/\.json$/, '')
    if (Object.keys(module).join() !== name) errors.push(`${app.name}: modules/${locale}/${entry} must hold the single top-level key "${name}"`)
    for (const key of Object.keys(module)) {
      if (key in tree) errors.push(`${app.name}: "${key}" is in both ${locale}.json and modules/${locale}/${entry}`)
    }
    Object.assign(tree, module)
  }
  return tree
}

for (const app of apps) {
  const enTree = readCatalogue(app, 'en')
  const frTree = readCatalogue(app, 'fr')
  for (const [locale, tree] of [['en', enTree], ['fr', frTree]]) {
    for (const key of dottedKeys(tree)) errors.push(`${app.name}: ${locale}.json has a dotted key name "${key}"`)
  }
  const en = flatten(enTree)
  const fr = flatten(frTree)

  for (const key of Object.keys(en)) if (!(key in fr)) errors.push(`${app.name}: "${key}" is in en.json but not in fr.json`)
  for (const key of Object.keys(fr)) if (!(key in en)) errors.push(`${app.name}: "${key}" is in fr.json but not in en.json`)

  for (const [key, value] of Object.entries(fr)) {
    if (String(value).trim() === '') errors.push(`${app.name}: fr value for "${key}" is empty`)
    else if (key in en && value === en[key] && String(value).length > 3 && !allowlist.has(value)) {
      errors.push(`${app.name}: fr value for "${key}" is identical to en ("${value}"); translate it or add it to scripts/i18n-same-allowlist.json`)
    }
  }

  for (const file of app.sources.flatMap((source) => sourceFiles(source, app.extensions))) {
    const text = readFileSync(join(root, file), 'utf8')
    for (const match of text.matchAll(keyPattern)) {
      const key = match[2]
      for (const [locale, catalogue] of [['en', en], ['fr', fr]]) {
        if (!hasKey(catalogue, key)) errors.push(`${app.name}: "${key}" used in ${relative(root, join(root, file))} is missing from ${locale}.json`)
      }
    }
  }
}

// L10N-02: the mobile app's native texts the phone shows itself (iOS
// permission prompts), through Expo's per-locale strings: pos/app.json
// `expo.locales` must name an en and an fr file, both with the same keys,
// French translated, and every permission text a config plugin sets must
// have its Info.plist key in both. Android shows its own localised
// permission dialog (the app's reason is the in-app explanation, en/fr).
const nativePermissionKeys = {
  cameraPermission: 'NSCameraUsageDescription',
  microphonePermission: 'NSMicrophoneUsageDescription',
  photosPermission: 'NSPhotoLibraryUsageDescription',
  savePhotosPermission: 'NSPhotoLibraryAddUsageDescription',
  locationWhenInUsePermission: 'NSLocationWhenInUseUsageDescription',
  locationAlwaysPermission: 'NSLocationAlwaysUsageDescription',
  locationAlwaysAndWhenInUsePermission: 'NSLocationAlwaysAndWhenInUseUsageDescription',
  faceIDPermission: 'NSFaceIDUsageDescription',
}
const appJsonFile = join(root, 'pos/app.json')
if (existsSync(appJsonFile)) {
  const expo = JSON.parse(readFileSync(appJsonFile, 'utf8')).expo ?? {}
  const required = new Set()
  for (const plugin of expo.plugins ?? []) {
    const options = Array.isArray(plugin) ? (plugin[1] ?? {}) : {}
    for (const [option, plistKey] of Object.entries(nativePermissionKeys)) {
      if (typeof options[option] === 'string') required.add(`ios.${plistKey}`)
    }
  }
  for (const [key, value] of Object.entries(expo.ios?.infoPlist ?? {})) {
    if (/UsageDescription$/.test(key) && typeof value === 'string') required.add(`ios.${key}`)
  }
  const native = {}
  for (const locale of ['en', 'fr']) {
    const path = expo.locales?.[locale]
    if (typeof path !== 'string') {
      errors.push(`pos native: app.json expo.locales has no "${locale}" file`)
      continue
    }
    const file = join(root, 'pos', path)
    if (!existsSync(file)) {
      errors.push(`pos native: ${relative(root, file)} (expo.locales.${locale}) does not exist`)
      continue
    }
    native[locale] = flatten(JSON.parse(readFileSync(file, 'utf8')))
    for (const key of required) if (!(key in native[locale])) errors.push(`pos native: "${key}" (a permission text set in app.json) is missing from the ${locale} locale file`)
  }
  if (native.en && native.fr) {
    for (const key of Object.keys(native.en)) if (!(key in native.fr)) errors.push(`pos native: "${key}" is in the en locale file but not in fr`)
    for (const key of Object.keys(native.fr)) if (!(key in native.en)) errors.push(`pos native: "${key}" is in the fr locale file but not in en`)
    for (const [key, value] of Object.entries(native.fr)) {
      if (String(value).trim() === '') errors.push(`pos native: fr value for "${key}" is empty`)
      else if (value === native.en[key] && String(value).length > 3 && !allowlist.has(value)) errors.push(`pos native: fr value for "${key}" is identical to en`)
    }
  }
}

// The user manual (docs/manual): every topic file exists in en and fr with the same
// id and front matter lists. The API test (ManualTopicsTest) also checks that the
// permissions and roles are real.
{
  const manual = join(root, 'docs/manual')
  const topicFiles = (locale) => {
    const base = join(manual, locale)
    const walk = (dir) =>
      readdirSync(dir).flatMap((entry) => {
        const full = join(dir, entry)
        return statSync(full).isDirectory() ? walk(full) : entry.endsWith('.md') ? [relative(base, full)] : []
      })
    return existsSync(base) ? walk(base).sort() : []
  }
  const meta = (locale, file) => {
    const match = readFileSync(join(manual, locale, file), 'utf8').match(/^---\r?\n([\s\S]*?)\r?\n---/)
    if (!match) return null
    return Object.fromEntries(match[1].split(/\r?\n/).filter((line) => line.includes(':')).map((line) => [line.slice(0, line.indexOf(':')), line.slice(line.indexOf(':') + 1).trim()]))
  }
  if (existsSync(manual)) {
    const en = topicFiles('en')
    const fr = topicFiles('fr')
    for (const file of en) if (!fr.includes(file)) errors.push(`manual: ${file} is in docs/manual/en but not in fr`)
    for (const file of fr) if (!en.includes(file)) errors.push(`manual: ${file} is in docs/manual/fr but not in en`)
    for (const file of en.filter((name) => fr.includes(name))) {
      const a = meta('en', file)
      const b = meta('fr', file)
      if (!a || !b || !a.id) {
        errors.push(`manual: ${file} needs front matter with an id in both languages`)
        continue
      }
      for (const key of ['id', 'module', 'order', 'permissions', 'roles', 'screens']) {
        if (a[key] !== b[key]) errors.push(`manual: ${file} has a different "${key}" in en ("${a[key]}") and fr ("${b[key]}")`)
      }
    }
  }
}

if (errors.length > 0) {
  console.error(`i18n check failed (${errors.length}):\n- ${errors.join('\n- ')}`)
  process.exit(1)
}
console.log('i18n check passed')
