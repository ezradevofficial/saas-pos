// NFR-02: the bundle budget check reads what index.html loads first and fails above budget.
import { initialAssets, localeChunks, measure, overBudget } from './bundle-budget.mjs'

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

  it('counts the larger catalogue in the first load and fails when one is not a lazy chunk', () => {
    const files = {
      'dist/index.html': Buffer.from(html),
      'dist/assets/index-abc.js': Buffer.from('a'.repeat(1024)),
      'dist/assets/ds-def.js': Buffer.from('b'.repeat(1024)),
      'dist/assets/index-ghi.css': Buffer.from('c'.repeat(1024)),
      'dist/assets/en-A1_b.js': Buffer.from('x'.repeat(10 * 1024)),
      'dist/assets/fr-C2-d.js': Buffer.from(Array.from({ length: 4000 }, (_, i) => String(i * 7919)).join('')),
    }
    const assets = ['index-abc.js', 'ds-def.js', 'index-ghi.css', 'en-A1_b.js', 'fr-C2-d.js', 'entries-x.js']
    expect(localeChunks(assets, ['en', 'fr'])).toEqual({ en: 'assets/en-A1_b.js', fr: 'assets/fr-C2-d.js' })

    const without = measure('dist', (path) => files[path])
    const sizes = measure('dist', (path) => files[path], { locales: ['en', 'fr'], list: () => assets })
    expect(sizes.localeGzipKb.fr).toBeGreaterThan(sizes.localeGzipKb.en)
    expect(sizes.initialGzipKb).toBeCloseTo(without.initialGzipKb + sizes.localeGzipKb.fr, 0)
    expect(sizes.files).toBe(4)

    expect(() => measure('dist', (path) => files[path], { locales: ['en', 'fr'], list: () => ['index-abc.js', 'en-A1_b.js'] })).toThrow(
      'no lazy chunk for the fr catalogue',
    )
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
