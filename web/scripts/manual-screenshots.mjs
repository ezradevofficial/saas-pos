// Captures the screen each manual topic names (docs/manual/{locale}/**/*.md,
// front matter `screens`) in English and French, signed in as the seeded demo
// users of `php artisan manual:demo-tenant`, and saves
// docs/manual/images/{locale}/{topic-id}.png (main content area, 1280 wide).
//
// Needs the web app on 3008 and the API on 8008 (local, with the demo tenant).
// Run: npm run manual:screenshots [-- --locale=fr --topic=pos-sell]
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import zlib from 'node:zlib'
import { chromium } from '@playwright/test'

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)))
const manual = join(root, 'docs', 'manual')
const webUrl = process.env.MANUAL_WEB_URL ?? 'http://localhost:3008'
const apiUrl = process.env.MANUAL_API_URL ?? 'http://localhost:8008'
const password = process.env.MANUAL_DEMO_PASSWORD ?? 'Demo-Harbour-4242'
const WIDTH = 1280
const MAX_HEIGHT = 900
// pngquant (on the PATH, or MANUAL_PNGQUANT) shrinks the images to about a third; without it they are only re-deflated.
const pngquant = process.env.MANUAL_PNGQUANT ?? 'pngquant'

// Signed-out or one-off pages that cannot be captured with a demo session.
const SKIP = new Set(['/welcome', '/sign-up', '/verify', '/two-factor', '/two-factor/enrol'])

const args = Object.fromEntries(process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => a.slice(2).split('=')))
const locales = args.locale ? [args.locale] : ['en', 'fr']

async function files(dir) {
  const found = []
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name)
    if (entry.isDirectory()) found.push(...(await files(path)))
    else if (entry.name.endsWith('.md')) found.push(path)
  }
  return found.sort()
}

function frontMatter(source) {
  const match = source.replace(/\r\n/g, '\n').match(/^---\n([\s\S]*?)\n---\n/)
  const meta = {}
  for (const line of (match?.[1] ?? '').split('\n')) {
    const pair = line.match(/^([a-z_]+):\s*(.*)$/)
    if (!pair) continue
    const value = pair[2].trim()
    meta[pair[1]] = value.startsWith('[') ? value.slice(1, -1).split(',').map((v) => v.trim()).filter(Boolean) : value
  }
  return meta
}

/** The first web route of the topic that needs no id and is not a signed-out page. */
function routeFor(meta) {
  return (meta.screens ?? []).find((screen) => screen.startsWith('/') && !screen.includes(':') && !SKIP.has(screen)) ?? null
}

/** Quantises to a small palette with pngquant when it is installed (flat UI colours lose nothing visible). */
function optimise(png) {
  const run = spawnSync(pngquant, ['--quality', '80-98', '--speed', '4', '--strip', '-'], { input: png, maxBuffer: 64 * 1024 * 1024 })
  return run.status === 0 && run.stdout?.length ? run.stdout : recompress(png)
}

/** Re-deflates the IDAT data at the highest level; the image is unchanged. */
function recompress(png) {
  const chunks = []
  const data = []
  let offset = 8
  while (offset < png.length) {
    const length = png.readUInt32BE(offset)
    const type = png.toString('latin1', offset + 4, offset + 8)
    const body = png.subarray(offset + 8, offset + 8 + length)
    if (type === 'IDAT') data.push(body)
    else if (type !== 'IEND') chunks.push({ type, body })
    offset += 12 + length
  }
  const packed = zlib.deflateSync(zlib.inflateSync(Buffer.concat(data)), { level: 9, memLevel: 9 })
  const out = [png.subarray(0, 8)]
  const write = (type, body) => {
    const head = Buffer.alloc(8)
    head.writeUInt32BE(body.length, 0)
    head.write(type, 4, 'latin1')
    const tail = Buffer.alloc(4)
    tail.writeUInt32BE(zlib.crc32(Buffer.concat([head.subarray(4), body])) >>> 0, 0)
    out.push(head, body, tail)
  }
  for (const chunk of chunks) write(chunk.type, chunk.body)
  write('IDAT', packed)
  write('IEND', Buffer.alloc(0))
  const result = Buffer.concat(out)
  return result.length < png.length ? result : png
}

async function session(email) {
  const response = await fetch(`${apiUrl}/api/v1/auth/sign-in`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ login: email, password }),
  })
  if (!response.ok) throw new Error(`Demo sign-in failed (${response.status}). Run php artisan manual:demo-tenant first.`)
  return (await response.json()).token
}

async function setLocale(token, locale) {
  await fetch(`${apiUrl}/api/v1/me`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token}` },
    body: JSON.stringify({ locale }),
  })
}

const browser = await chromium.launch({ headless: true })
const summary = { saved: 0, skipped: [], failed: [] }

try {
  const token = await session('demo-owner@demo.example.com')

  for (const locale of locales) {
    await setLocale(token, locale)
    const context = await browser.newContext({ viewport: { width: WIDTH + 400, height: 900 }, locale: locale === 'fr' ? 'fr-FR' : 'en-GB', reducedMotion: 'reduce' })
    await context.addInitScript(([t, l]) => {
      window.localStorage.setItem('app.token', t)
      window.localStorage.setItem('app.locale', l)
    }, [token, locale])
    const page = await context.newPage()
    await mkdir(join(manual, 'images', locale), { recursive: true })

    for (const path of await files(join(manual, locale))) {
      const meta = frontMatter(await readFile(path, 'utf8'))
      if (args.topic && meta.id !== args.topic) continue
      const route = routeFor(meta)
      if (!route) {
        summary.skipped.push(`${locale}/${meta.id}`)
        continue
      }
      try {
        await page.goto(`${webUrl}${route}`, { waitUntil: 'networkidle' })
        const main = page.locator('main').first()
        await main.waitFor({ state: 'visible', timeout: 15000 })
        await page.waitForTimeout(600)
        await page.evaluate(() => document.fonts.ready)
        const box = await main.boundingBox()
        // Crop below the last element, not to the page's minimum height, so a short screen has no empty band.
        const content = await main.evaluate((node) => Math.max(...[...node.querySelectorAll('*')].map((el) => el.getBoundingClientRect().bottom)) - node.getBoundingClientRect().top)
        const height = Math.min(Math.max(Math.ceil(Math.min(content + 40, box.height)), 240), MAX_HEIGHT)
        // The viewport leaves the main area exactly 1280 wide with the sidebar beside it.
        await page.setViewportSize({ width: Math.round(box.x + WIDTH), height: Math.max(900, height) })
        await page.waitForTimeout(150)
        const fresh = await main.boundingBox()
        const png = await page.screenshot({ clip: { x: fresh.x, y: fresh.y, width: WIDTH, height }, animations: 'disabled' })
        await writeFile(join(manual, 'images', locale, `${meta.id}.png`), optimise(png))
        summary.saved += 1
        await page.setViewportSize({ width: WIDTH + 400, height: 900 })
      } catch (error) {
        summary.failed.push(`${locale}/${meta.id}: ${String(error.message).split('\n')[0]}`)
      }
    }
    await context.close()
  }
} finally {
  await browser.close()
}

console.log(`Saved ${summary.saved} images.`)
console.log(`No web screen (POS or signed-out): ${summary.skipped.length} topics.`)
if (summary.failed.length) console.log(`Failed:\n${summary.failed.join('\n')}`)
