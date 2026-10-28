// Captures the POS app's screens for the manual topics that name a `pos:` screen, in English and French.
// The Expo app runs on the web (react-native-web) at 1280x800, a tablet size: start it with
// `npm run web --workspace pos` (port 3009), with the API on 8008 and the demo tenant seeded.
// It pairs a till (devices named "Manual till {locale}" are made once and paired again on each run),
// signs in with the demo users' PIN, and saves docs/manual/images/{locale}/{topic-id}.png.
// Run: npm run manual:screenshots:pos [-- --locale=fr --topic=pos-sell]
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { spawnSync } from 'node:child_process'
import { chromium } from '@playwright/test'
import { recipes } from './manual-pos-recipes.mjs'

const root = resolve(fileURLToPath(new URL('../..', import.meta.url)))
const posUrl = process.env.MANUAL_POS_URL ?? 'http://localhost:3009'
const apiUrl = process.env.MANUAL_API_URL ?? 'http://localhost:8008'
const password = process.env.MANUAL_DEMO_PASSWORD ?? 'Demo-Harbour-4242'
const pin = process.env.MANUAL_DEMO_PIN ?? '739152'
const pngquant = process.env.MANUAL_PNGQUANT ?? 'pngquant'

const args = Object.fromEntries(process.argv.slice(2).filter((a) => a.startsWith('--')).map((a) => a.slice(2).split('=')))
const locales = args.locale ? [args.locale] : ['en', 'fr']

const headers = (token) => ({ 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) })
const call = async (method, path, body, token) => {
  const response = await fetch(`${apiUrl}/api/v1/${path}`, { method, headers: headers(token), body: body === undefined ? undefined : JSON.stringify(body) })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(`${method} ${path} answered ${response.status}: ${JSON.stringify(data).slice(0, 200)}`)
  return data
}

function flatten(object, prefix = '') {
  return Object.entries(object).flatMap(([key, value]) => (value && typeof value === 'object' ? flatten(value, `${prefix}${key}.`) : [[`${prefix}${key}`, String(value)]]))
}

function optimise(png) {
  const run = spawnSync(pngquant, ['--quality', '80-98', '--speed', '4', '--strip', '-'], { input: png, maxBuffer: 64 * 1024 * 1024 })
  return run.status === 0 && run.stdout?.length ? run.stdout : png
}

/** A till device by name: made once, unpaired and given a new pairing code on each run. */
async function pairingCode(ownerToken, name, van = null) {
  const location = van ? { id: van.location_id } : (await call('GET', 'locations', undefined, ownerToken)).data[0]
  const devices = (await call('GET', `locations/${location.id}/devices`, undefined, ownerToken)).data
  const device = devices.find((entry) => entry.name === name) ?? (await call('POST', `locations/${location.id}/devices`, { name }, ownerToken)).data
  if (device.status === 'active' || device.paired_at) await call('POST', `devices/${device.id}/unpair`, {}, ownerToken).catch(() => {})
  return { name, deviceId: device.id, code: (await call('POST', `devices/${device.id}/pairing-code`, {}, ownerToken)).code }
}

const browser = await chromium.launch({ headless: true })
const summary = { saved: 0, failed: [] }

/** A paired till in its own browser context, with the helpers the recipes use. */
async function makeTill(ownerToken, locale, t, name, bindVan = false) {
  // A van's till is paired at the van's own location (VAN-01).
  const van = bindVan ? (await call('GET', 'van-sales/vans', undefined, ownerToken)).data[0] : null
  const { code, deviceId } = await pairingCode(ownerToken, name, van)
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: locale === 'fr' ? 'fr-FR' : 'en-GB', reducedMotion: 'reduce' })
  const page = await context.newPage()
  await page.goto(posUrl, { waitUntil: 'networkidle', timeout: 240000 })
  await page.getByLabel(t('pairing.code')).fill(code)
  await page.getByLabel(t('pairing.name')).fill(name)
  await page.getByRole('button', { name: t('pairing.submit') }).click()
  await page.getByRole('button', { name: t('common.continue') }).click()
  await page.getByText(t('signIn.title')).waitFor()
  await page.waitForTimeout(2500)

  if (bindVan) {
    // A van's till is the device its van is bound to (VAN-01): bound once paired, then the till pulls the van.
    await call('PUT', `van-sales/vans/${van.id}`, { device_id: deviceId }, ownerToken)
    await page.reload({ waitUntil: 'networkidle' })
    await page.waitForTimeout(3000)
  }

  const wait = (ms = 900) => page.waitForTimeout(ms)
  const h = {
    page,
    context,
    locale,
    t,
    wait,
    pin,
    click: async (label, options = {}) => {
      await page.getByRole('button', { name: label, ...options }).first().click()
      await wait(500)
    },
    /** Signs in as a demo user from the sign-in list (the page is reloaded first, so each recipe starts clean). */
    signIn: async (user) => {
      await page.reload({ waitUntil: 'networkidle' })
      await page.getByText(t('signIn.title')).waitFor()
      await page.getByText(user, { exact: true }).click()
      for (const digit of pin) await page.getByRole('button', { name: digit, exact: true }).click()
      await page.getByRole('button', { name: t('signIn.submit') }).click()
      await wait(1200)
    },
    openShift: async (cash = '5000') => {
      const field = page.getByLabel(t('pos.shift.float', { currency: 'KES' }))
      if (await field.count()) {
        await field.fill(cash)
        await h.click(t('pos.shift.open'))
        await wait(1200)
      }
    },
    /** The cashier's sell screen with a shift open (a quick sale on a restaurant till). */
    sell: async (user = 'Demo cashier') => {
      await h.signIn(user)
      await h.openShift()
      const quick = page.getByRole('button', { name: t('restaurant.quickSale') })
      if (await quick.count()) {
        await quick.first().click()
        await wait(900)
      }
      // A sale left in the cart by an earlier recipe is cleared first.
      const clear = page.getByRole('button', { name: t('pos.sale.clear'), exact: true })
      if (await clear.first().isVisible({ timeout: 1000 }).catch(() => false)) {
        await clear.first().click({ timeout: 5000 }).catch(() => {})
        await wait(400)
      }
    },
    tile: async (product) => {
      await page.getByRole('button', { name: new RegExp(product) }).first().click()
      await wait(400)
    },
  }

  return h
}

try {
  const { token: ownerToken } = await call('POST', 'auth/sign-in', { login: 'demo-owner@demo.example.com', password })

  for (const locale of locales) {
    const strings = Object.fromEntries(flatten(JSON.parse(await readFile(join(root, 'pos/src/locales', `${locale}.json`), 'utf8'))))
    const t = (key, vars = {}) => {
      const text = strings[key] ?? strings[`${key}_other`]
      if (text === undefined) throw new Error(`POS string [${key}] is missing in ${locale}`)
      return text.replace(/\{\{(\w+)\}\}/g, (_, name) => String(vars[name] ?? ''))
    }
    const tills = { till: await makeTill(ownerToken, locale, t, `Manual till ${locale}`) }

    for (const recipe of recipes) {
      if (args.topic && recipe.id !== args.topic) continue
      const kind = recipe.till ?? 'till'
      try {
        tills[kind] ??= await makeTill(ownerToken, locale, t, `Manual ${kind} till ${locale}`, kind === 'van')
        const h = tills[kind]
        await h.context.setOffline(false)
        await recipe.run(h)
        await h.wait(700)
        if (recipe.save === false) continue
        const png = await h.page.screenshot()
        await mkdir(join(root, 'docs/manual/images', locale), { recursive: true })
        await writeFile(join(root, 'docs/manual/images', locale, `${recipe.id}.png`), optimise(png))
        summary.saved += 1
      } catch (error) {
        const page = tills[kind]?.page
        const screen = ((await page?.locator('body').innerText().catch(() => '')) ?? '').replace(/\s+/g, ' ').slice(0, process.env.MANUAL_DEBUG ? 1200 : 160)
        summary.failed.push(`${locale}/${recipe.id}: ${String(error.message).split('\n').slice(0, process.env.MANUAL_DEBUG ? 12 : 3).join(' ')} | screen: ${screen}`)
      }
    }

    for (const till of Object.values(tills)) await till.context.close()
  }
} finally {
  await browser.close()
}

console.log(`Saved ${summary.saved} POS images.`)
if (summary.failed.length) console.log(`Failed:\n${summary.failed.join('\n')}`)
