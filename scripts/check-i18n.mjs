// Missing-key check (L10N-02). Plain Node, no dependencies.
// Fails when en/fr key sets differ, a literal t('key') is missing from a
// catalogue, or an fr value is empty or identical to en (longer than 3
// characters) and not listed in scripts/i18n-same-allowlist.json.
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const allowlist = new Set(JSON.parse(readFileSync(join(root, 'scripts/i18n-same-allowlist.json'), 'utf8')))

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

const errors = []

for (const app of apps) {
  const en = flatten(JSON.parse(readFileSync(join(root, app.locales, 'en.json'), 'utf8')))
  const fr = flatten(JSON.parse(readFileSync(join(root, app.locales, 'fr.json'), 'utf8')))

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
        if (!(key in catalogue)) errors.push(`${app.name}: "${key}" used in ${relative(root, join(root, file))} is missing from ${locale}.json`)
      }
    }
  }
}

if (errors.length > 0) {
  console.error(`i18n check failed (${errors.length}):\n- ${errors.join('\n- ')}`)
  process.exit(1)
}
console.log('i18n check passed')
