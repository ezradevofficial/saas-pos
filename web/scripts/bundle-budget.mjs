// NFR-02: the web bundle budget. After `vite build`, measures what the browser
// downloads before the first page renders (the entry script, every chunk
// index.html preloads, and the entry stylesheet), gzipped as served, and
// fails when the entry chunk or that first load is above its budget
// (web/bundle-budget.json). Pages are lazy chunks (routes.jsx), so a page
// growing never counts here; a heavy import reaching the shell does.
// The UI catalogues are lazy chunks too (src/i18n), but the first paint waits
// for the active one, so the first load counts the larger of them; a
// catalogue missing from the chunks (bundled eagerly again) fails the check.
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { gzipSync } from 'node:zlib'

const kb = (bytes) => Math.round((bytes / 1024) * 10) / 10

/** The files index.html loads up front: the entry module, preloaded modules and stylesheets. */
export function initialAssets(html) {
  const entry = html.match(/<script[^>]*type="module"[^>]*src="\/?([^"]+)"/)?.[1] ?? null
  const preloads = [...html.matchAll(/<link[^>]*rel="modulepreload"[^>]*href="\/?([^"]+)"/g)].map((m) => m[1])
  const styles = [...html.matchAll(/<link[^>]*rel="stylesheet"[^>]*href="\/?([^"]+)"/g)].map((m) => m[1])
  return { entry, preloads, styles }
}

/** The chunk of each catalogue ("en" -> "assets/en-<hash>.js"), or null when it has none. */
export function localeChunks(assetFiles, locales) {
  return Object.fromEntries(
    locales.map((locale) => {
      const file = assetFiles.find((name) => new RegExp(`^${locale}-[\\w-]+\\.js$`).test(name))
      return [locale, file ? `assets/${file}` : null]
    }),
  )
}

/** Sizes in kB (raw and gzipped) of the entry chunk and of the whole first load. */
export function measure(distDir, read = (path) => readFileSync(path), { locales = [], list = () => [] } = {}) {
  const html = String(read(join(distDir, 'index.html')))
  const { entry, preloads, styles } = initialAssets(html)
  if (!entry) throw new Error('index.html has no entry module script')
  const size = (file) => {
    const content = read(join(distDir, file))
    return { raw: content.length, gzip: gzipSync(content).length }
  }
  const entrySize = size(entry)
  const all = [entry, ...preloads, ...styles].map(size)
  const chunks = localeChunks(list(join(distDir, 'assets')), locales)
  const missing = Object.keys(chunks).filter((locale) => chunks[locale] === null)
  if (missing.length > 0) throw new Error(`no lazy chunk for the ${missing.join(', ')} catalogue (src/i18n loads each on demand)`)
  const localeGzipKb = Object.fromEntries(Object.entries(chunks).map(([locale, file]) => [locale, kb(size(file).gzip)]))
  const largestLocale = Math.max(0, ...Object.values(chunks).map((file) => size(file).gzip))
  return {
    entry,
    entryRawKb: kb(entrySize.raw),
    entryGzipKb: kb(entrySize.gzip),
    initialGzipKb: kb(all.reduce((sum, s) => sum + s.gzip, 0) + largestLocale),
    localeGzipKb,
    files: 1 + preloads.length + styles.length + (locales.length > 0 ? 1 : 0),
  }
}

/** The budgets exceeded, as messages; empty when within budget. */
export function overBudget(sizes, budget) {
  const problems = []
  for (const key of ['entryRawKb', 'entryGzipKb', 'initialGzipKb']) {
    if (budget[key] !== undefined && sizes[key] > budget[key]) {
      problems.push(`${key} is ${sizes[key]} kB, over the budget of ${budget[key]} kB`)
    }
  }
  return problems
}

const run = process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]
if (run) {
  const root = fileURLToPath(new URL('..', import.meta.url))
  const budget = JSON.parse(readFileSync(join(root, 'bundle-budget.json'), 'utf8'))
  const locales = readdirSync(join(root, 'src/locales'))
    .filter((name) => name.endsWith('.json'))
    .map((name) => name.replace(/\.json$/, ''))
  const sizes = measure(join(root, 'dist'), undefined, { locales, list: (dir) => readdirSync(dir) })
  const catalogues = Object.entries(sizes.localeGzipKb).map(([locale, size]) => `${locale} ${size} kB`).join(', ')
  console.log(
    `Bundle: entry ${sizes.entryRawKb} kB (${sizes.entryGzipKb} kB gzip), first load ${sizes.initialGzipKb} kB gzip in ${sizes.files} files, with the larger catalogue (gzip: ${catalogues})`,
  )
  const problems = overBudget(sizes, budget)
  if (problems.length > 0) {
    for (const problem of problems) console.error(`Bundle budget exceeded (NFR-02): ${problem}`)
    console.error('Lazy-load the new import (routes.jsx, dynamic import()) or raise the budget in web/bundle-budget.json with a reason.')
    process.exit(1)
  }
}
