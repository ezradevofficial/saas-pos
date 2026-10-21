// NFR-02: the web bundle budget. After `vite build`, measures what the browser
// downloads before the first page renders (the entry script, every chunk
// index.html preloads, and the entry stylesheet), gzipped as served, and
// fails when the entry chunk or that first load is above its budget
// (web/bundle-budget.json). Pages are lazy chunks (routes.jsx), so a page
// growing never counts here; a heavy import reaching the shell does.
import { readFileSync } from 'node:fs'
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

/** Sizes in kB (raw and gzipped) of the entry chunk and of the whole first load. */
export function measure(distDir, read = (path) => readFileSync(path)) {
  const html = String(read(join(distDir, 'index.html')))
  const { entry, preloads, styles } = initialAssets(html)
  if (!entry) throw new Error('index.html has no entry module script')
  const size = (file) => {
    const content = read(join(distDir, file))
    return { raw: content.length, gzip: gzipSync(content).length }
  }
  const entrySize = size(entry)
  const all = [entry, ...preloads, ...styles].map(size)
  return {
    entry,
    entryRawKb: kb(entrySize.raw),
    entryGzipKb: kb(entrySize.gzip),
    initialGzipKb: kb(all.reduce((sum, s) => sum + s.gzip, 0)),
    files: 1 + preloads.length + styles.length,
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
  const sizes = measure(join(root, 'dist'))
  console.log(
    `Bundle: entry ${sizes.entryRawKb} kB (${sizes.entryGzipKb} kB gzip), first load ${sizes.initialGzipKb} kB gzip in ${sizes.files} files`,
  )
  const problems = overBudget(sizes, budget)
  if (problems.length > 0) {
    for (const problem of problems) console.error(`Bundle budget exceeded (NFR-02): ${problem}`)
    console.error('Lazy-load the new import (routes.jsx, dynamic import()) or raise the budget in web/bundle-budget.json with a reason.')
    process.exit(1)
  }
}
