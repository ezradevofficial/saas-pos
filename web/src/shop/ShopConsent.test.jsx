// NFR-08 (KE DPA), ECOM-08: the storefront asks for marketing consent at sign-in,
// unticked by default, sends the wording version it showed, and lets a signed-in
// customer turn marketing messages off (or on again) from the account.
import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockApi, renderApp, resetSession } from '@/test/renderApp'
import { MARKETING_WORDING } from './ShopPages'
import { setShopSession } from './shopClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const STORE = {
  handle: 'duka', name: 'Duka Online', currency: 'KES', contact_phone: null, contact_email: null, whatsapp: null, share: { url: 'u', whatsapp: 'w' },
  min_order_minor: '0', delivery_enabled: true, pickup_enabled: false, pickup_locations: [], zones: [], payment_kinds: ['cod'], categories: [], signed_in: false,
}

let calls = []
let consenting = false
let smsAvailable = true

function json(body, status = 200) {
  return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
}

function mockShop() {
  calls = []
  vi.stubGlobal('fetch', vi.fn((url, init = {}) => {
    // AUTH-01: the platform's public settings, asked with no session.
    if (String(url).endsWith('/api/v1/public/branding')) {
      calls.push({ path: 'public/branding', method: 'GET', headers: init.headers })
      return json({ data: null, meta: { sms_available: smsAvailable } })
    }
    const path = String(url).replace(/^.*\/api\/v1\/shop\/duka/, '')
    const method = init.method ?? 'GET'
    const body = init.body ? JSON.parse(init.body) : null
    calls.push({ path, method, body })
    if (path === '' && method === 'GET') return json({ data: STORE })
    if (path === '/auth/code') return json({ data: { challenge_id: 'c-1', channel: 'sms', destination: '+2547•••678' } }, 201)
    if (path === '/auth/verify') {
      consenting = Boolean(body.marketing_consent)
      return json({ data: { token: 'ecs_token', customer: { name: 'Amina', phone: '+254712345678', email: null, marketing_consent: consenting } } })
    }
    if (path === '/account') return json({ data: { customer: { name: 'Amina', phone: '+254712345678', email: null, marketing_consent: consenting }, orders: [], addresses: [] } })
    if (path === '/account/marketing-consent' && method === 'PUT') {
      consenting = body.granted
      return json({ data: { marketing_consent: consenting } })
    }
    return json({ message: 'Not found', code: 'not_found' }, 404)
  }))
}

const wording = 'Send me offers and news from Duka Online by SMS, WhatsApp or email. I can stop them at any time in my account.'

async function signIn({ tick }) {
  renderApp('/shop/duka/account')
  fireEvent.change(await screen.findByLabelText('Phone or email'), { target: { value: '0712345678' } })
  fireEvent.click(screen.getByRole('button', { name: 'Send code' }))
  fireEvent.change(await screen.findByLabelText('Code'), { target: { value: '123456' } })
  const box = await screen.findByRole('checkbox', { name: wording })
  expect(box).not.toBeChecked()
  if (tick) fireEvent.click(box)
  fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
  await screen.findByRole('heading', { name: 'Hello Amina' })
}

describe('Storefront marketing consent (NFR-08)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    mockApi(api, { permissions: [], modules: ['core'] })
    window.localStorage.clear()
    window.sessionStorage.clear()
    setShopSession('duka', null)
    consenting = false
    smsAvailable = true
    mockShop()
  })

  afterEach(() => vi.unstubAllGlobals())

  it('sends the consent and the wording version only when the box is ticked', async () => {
    await signIn({ tick: true })
    const verify = calls.find((call) => call.path === '/auth/verify')
    expect(verify.body).toEqual({ challenge_id: 'c-1', code: '123456', marketing_consent: true, wording_version: MARKETING_WORDING })
    expect(await screen.findByRole('switch', { name: wording })).toBeChecked()
  })

  it('sends no consent when the box is left unticked', async () => {
    await signIn({ tick: false })
    const verify = calls.find((call) => call.path === '/auth/verify')
    expect(verify.body).toEqual({ challenge_id: 'c-1', code: '123456' })
    expect(await screen.findByText('You don’t get offers or news.')).toBeInTheDocument()
  })

  it('withdraws from the account, and turns it on again with the wording shown', async () => {
    await signIn({ tick: true })
    fireEvent.click(await screen.findByRole('switch', { name: wording }))
    await waitFor(() => expect(calls.some((call) => call.path === '/account/marketing-consent' && call.body.granted === false)).toBe(true))
    const off = calls.find((call) => call.path === '/account/marketing-consent')
    expect(off.body).toEqual({ granted: false })
    expect(await screen.findByText('You don’t get offers or news.')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('switch', { name: wording }))
    await waitFor(() => expect(calls.filter((call) => call.path === '/account/marketing-consent')).toHaveLength(2))
    expect(calls.filter((call) => call.path === '/account/marketing-consent')[1].body).toEqual({ granted: true, wording_version: MARKETING_WORDING })
    expect(await screen.findByText('You get offers and news. You can stop them here at any time.')).toBeInTheDocument()
  })

  it('asks for an email address to sign in when text messages aren’t available (AUTH-01, ECOM-08)', async () => {
    smsAvailable = false
    renderApp('/shop/duka/account')

    const field = await screen.findByLabelText('Email address')
    expect(field).toHaveAttribute('type', 'email')
    expect(screen.queryByLabelText('Phone or email')).not.toBeInTheDocument()
    expect(screen.getByText('We send you a 6-digit code. Codes by text message aren’t available yet.')).toBeInTheDocument()
    const asked = calls.find((call) => call.path === 'public/branding')
    expect(asked.headers.Authorization).toBeUndefined()
  })
})
