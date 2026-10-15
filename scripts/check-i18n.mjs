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

for (const app of apps) {
  const enTree = JSON.parse(readFileSync(join(root, app.locales, 'en.json'), 'utf8'))
  const frTree = JSON.parse(readFileSync(join(root, app.locales, 'fr.json'), 'utf8'))
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

if (errors.length > 0) {
  console.error(`i18n check failed (${errors.length}):\n- ${errors.join('\n- ')}`)
  process.exit(1)
}
console.log('i18n check passed')
