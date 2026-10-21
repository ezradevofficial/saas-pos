// NFR-02: the bundle budget check reads what index.html loads first and fails above budget.
import { initialAssets, measure, overBudget } from './bundle-budget.mjs'

const html = `<!doctype html><html><head>
<script type="module" crossorigin src="/assets/index-abc.js"></script>
<link rel="modulepreload" crossorigin href="/assets/ds-def.js">
<link rel="stylesheet" crossorigin href="/assets/index-ghi.css">
</head><body></body></html>`

describe('bundle budget', () => {
  it('finds the entry, the preloaded chunks and the stylesheets', () => {
    expect(initialAssets(html)).toEqual({
      entry: 'assets/index-abc.js',
      preloads: ['assets/ds-def.js'],
      styles: ['assets/index-ghi.css'],
    })
  })

  it('measures the entry and the whole first load, gzipped', () => {
    const files = {
      'dist/index.html': Buffer.from(html),
      'dist/assets/index-abc.js': Buffer.from('a'.repeat(200 * 1024)),
      'dist/assets/ds-def.js': Buffer.from('b'.repeat(10 * 1024)),
      'dist/assets/index-ghi.css': Buffer.from('c'.repeat(1024)),
    }
    const sizes = measure('dist', (path) => files[path])
    expect(sizes.entry).toBe('assets/index-abc.js')
    expect(sizes.entryRawKb).toBe(200)
    expect(sizes.entryGzipKb).toBeLessThan(sizes.entryRawKb)
    expect(sizes.initialGzipKb).toBeGreaterThan(sizes.entryGzipKb)
    expect(sizes.files).toBe(3)
  })

  it('reports every budget exceeded and nothing when within budget', () => {
    const budget = { entryRawKb: 100, entryGzipKb: 30, initialGzipKb: 50 }
    expect(overBudget({ entryRawKb: 90, entryGzipKb: 20, initialGzipKb: 40 }, budget)).toEqual([])
    const problems = overBudget({ entryRawKb: 120, entryGzipKb: 20, initialGzipKb: 60 }, budget)
    expect(problems).toHaveLength(2)
    expect(problems[0]).toContain('entryRawKb is 120 kB')
  })

  it('refuses an index.html without an entry module', () => {
    expect(() => measure('dist', () => Buffer.from('<html></html>'))).toThrow('no entry module')
  })
})
