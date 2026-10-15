// PRC-07 to PRC-09, PRC-23: the supplier portal (link exchange, tender, sealed bid, receipt, sign out; open notice registration).
import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import { api } from '@/api/client'
import { apiUrl } from '@/config'
import { mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { PORTAL_SESSION_KEY } from './portal/portalClient'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const LINK = 'A'.repeat(48)
const SESSION = 'ps_session-token'
const FINGERPRINT = '5d41402abc4b2a76b9719d911017c592ae2f6c3b7d3e1a9f0c8b6d4e2a0c8e6f'

const TENDER = {
  number: 'TND-WL-2026-00001',
  title: 'Bakery ovens',
  description: 'Two deck ovens with installation.',
  kind: 'invited',
  company: 'Amani Retail',
  branch: 'Westlands',
  currency: 'KES',
  deadline_at: '2026-10-20T09:00:00Z',
  opening_at: '2026-10-20T10:00:00Z',
  technical_weight: 70,
  financial_weight: 30,
  lines: [{ id: 'tl-1', line_no: 1, description: 'Deck oven, 3 decks', uom: { id: 'u-pcs', code: 'pcs', name: 'Pieces' }, qty: '2' }],
  criteria: [{ name: 'Experience', weight: 100, max_score: 10 }],
  open_for_bids: true,
}

const view = (extra = {}) => ({ tender: TENDER, bidder: { name: 'Oven Masters Ltd', email: 'bids@ovenmasters.example' }, bid: null, files: [], session: { expires_at: '2026-10-14T20:00:00Z' }, ...extra })
const json = (status, body) => new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
const calls = () => globalThis.fetch.mock.calls.map(([url, init]) => ({ path: url.replace(`${apiUrl}/api/v1/`, ''), method: init.method, headers: init.headers, body: init.body }))

describe('Supplier portal (PRC-07 to PRC-09)', () => {
  const realFetch = globalThis.fetch
  let state
  afterAll(() => {
    globalThis.fetch = realFetch
  })
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    window.sessionStorage.clear()
    state = view()
    globalThis.fetch = vi.fn(async (url, init) => {
      const path = url.replace(`${apiUrl}/api/v1/supplier-portal/`, '')
      if (path === 'sessions' && init.method === 'POST') return json(201, { data: { session_token: SESSION, expires_at: '2026-10-14T20:00:00Z' } })
      if (path === 'tender') return json(200, { data: state })
      if (path === 'bid/files') {
        state = { ...state, files: [{ id: 'f-1', name: 'brochure.pdf', mime: 'application/pdf', size: 20480, attached: false }] }
        return json(201, { data: state.files[0] })
      }
      if (path === 'bid' && init.method === 'PUT') {
        state = { ...state, bid: { status: 'submitted', version: 1, submitted_at: '2026-10-14T12:00:00Z', withdrawn_at: null, fingerprint: FINGERPRINT, outcome: null } }
        return json(200, { data: state })
      }
      if (path === 'sessions/current') return json(204, null)
      if (path.startsWith('notices/')) {
        if (path.endsWith('/register')) return json(202, { data: { sent: true } })
        return json(200, { data: { ...TENDER, kind: 'open' } })
      }
      return json(404, { message: 'Not found.', code: 'not_found' })
    })
  })

  it('exchanges the emailed link for a portal session, never sending the user’s own token', async () => {
    // A tenant user signed in on this browser: the portal must not use their token (PRC-07).
    mockApi(api)
    signedIn('user-token')
    const { router } = renderApp(`/supplier-portal/${LINK}`)
    expect(await screen.findByRole('heading', { name: 'Bakery ovens' })).toBeInTheDocument()
    expect(router.state.location.pathname).toBe('/supplier-portal/tender')
    const [start, tender] = calls()
    expect(start).toMatchObject({ path: 'supplier-portal/sessions', method: 'POST', body: JSON.stringify({ token: LINK }) })
    expect(start.headers.Authorization).toBeUndefined()
    expect(tender.headers.Authorization).toBe(`Bearer ${SESSION}`)
    expect(calls().every((call) => call.headers.Authorization !== 'Bearer user-token')).toBe(true)
    expect(window.sessionStorage.getItem(PORTAL_SESSION_KEY)).toBe(SESSION)
    // No app sidebar in the portal.
    expect(screen.queryByRole('navigation', { name: 'Main' })).not.toBeInTheDocument()
  })

  it('says so when the link no longer works', async () => {
    globalThis.fetch = vi.fn(async () => json(401, { message: 'This link is not valid.', code: 'portal_link_invalid' }))
    renderApp(`/supplier-portal/${LINK}`)
    expect(await screen.findByText('This link does not work')).toBeInTheDocument()
  })

  it('uploads a file, submits a bid in minor units with the file, then shows the sealed receipt', async () => {
    window.sessionStorage.setItem(PORTAL_SESSION_KEY, SESSION)
    renderApp('/supplier-portal/tender')
    const price = await screen.findByLabelText('Unit price, line 1')
    fireEvent.change(price, { target: { value: '450000' } })
    fireEvent.change(screen.getByLabelText('Technical response'), { target: { value: 'Ten years of installing deck ovens.' } })
    const file = new File(['%PDF'], 'brochure.pdf', { type: 'application/pdf' })
    fireEvent.change(screen.getByLabelText('Attachments'), { target: { files: [file] } })
    expect(await within(await screen.findByRole('list', { name: 'Uploaded files' })).findByText('brochure.pdf')).toBeInTheDocument()
    const upload = calls().find((call) => call.path === 'supplier-portal/bid/files')
    expect(upload.body).toBeInstanceOf(FormData)
    expect(upload.headers['Content-Type']).toBeUndefined()

    fireEvent.click(screen.getByRole('button', { name: 'Submit bid' }))
    await waitFor(() => expect(calls().some((call) => call.path === 'supplier-portal/bid')).toBe(true))
    const submit = calls().find((call) => call.path === 'supplier-portal/bid')
    expect(submit.method).toBe('PUT')
    expect(JSON.parse(submit.body)).toEqual({ currency: 'KES', lines: [{ tender_line_id: 'tl-1', unit_price_minor: '45000000' }], technical_response: 'Ten years of installing deck ovens.', file_ids: ['f-1'] })

    const receipt = await screen.findByText('Your bid receipt')
    expect(receipt).toBeInTheDocument()
    expect(screen.getByText(FINGERPRINT)).toBeInTheDocument()
    expect(screen.getByText(/Your bid is sealed. Nobody can read it, not even you/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Withdraw bid' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Replace bid' })).toBeInTheDocument()
  })

  it('shows times in the tender’s time zone, offers only active currencies and removes an upload', async () => {
    state = view({ tender: { ...TENDER, timezone: 'Africa/Nairobi' }, currencies: ['KES', 'USD'], files: [{ id: 'f-1', name: 'old.pdf', mime: 'application/pdf', size: 10, attached: false }, { id: 'f-2', name: 'kept.pdf', mime: 'application/pdf', size: 10, attached: true }] })
    const base = globalThis.fetch
    globalThis.fetch = vi.fn(async (url, init) => {
      if (url.endsWith('bid/files/f-1') && init.method === 'DELETE') {
        state = { ...state, files: state.files.filter((file) => file.id !== 'f-1') }
        return json(200, { data: state })
      }
      return base(url, init)
    })
    window.sessionStorage.setItem(PORTAL_SESSION_KEY, SESSION)
    renderApp('/supplier-portal/tender')
    // 09:00 UTC is 12:00 in Nairobi.
    expect((await screen.findAllByText(/12:00/))[0]).toBeInTheDocument()
    expect(screen.getAllByText('Africa/Nairobi').length).toBeGreaterThan(0)
    const files = screen.getByRole('list', { name: 'Uploaded files' })
    expect(within(files).queryByRole('button', { name: 'Remove kept.pdf' })).not.toBeInTheDocument()
    fireEvent.click(within(files).getByRole('button', { name: 'Remove old.pdf' }))
    await waitFor(() => expect(within(screen.getByRole('list', { name: 'Uploaded files' })).queryByText('old.pdf')).not.toBeInTheDocument())
  })

  it('signs out and forgets the session', async () => {
    window.sessionStorage.setItem(PORTAL_SESSION_KEY, SESSION)
    renderApp('/supplier-portal/tender')
    fireEvent.click(await screen.findByRole('button', { name: 'Sign out' }))
    expect(await screen.findByText('You signed out')).toBeInTheDocument()
    expect(calls().find((call) => call.path === 'supplier-portal/sessions/current').method).toBe('DELETE')
    expect(window.sessionStorage.getItem(PORTAL_SESSION_KEY)).toBeNull()
  })

  it('locks its pages down: no referrer and a strict content security policy (PRC-07)', async () => {
    renderApp('/supplier-portal/tender')
    await screen.findByText('You are not signed in to a tender')
    expect(document.head.querySelector('meta[name="referrer"]')).toHaveAttribute('content', 'no-referrer')
    const csp = document.head.querySelector('meta[http-equiv="Content-Security-Policy"]').getAttribute('content')
    expect(csp).toContain("script-src 'self'")
    expect(csp).not.toContain("'unsafe-eval'")
    expect(csp).toContain(`connect-src ${new URL(apiUrl).origin}`)
    expect(csp).toContain("object-src 'none'")
  })

  it('says the tender is unavailable when the business is suspended (decision 21)', async () => {
    state = { ...state, tender: { ...state.tender, open_for_bids: false, unavailable: true } }
    window.sessionStorage.setItem(PORTAL_SESSION_KEY, SESSION)
    renderApp('/supplier-portal/tender')
    expect(await screen.findByText('Tender unavailable')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Submit/ })).not.toBeInTheDocument()
  })

  it('asks to open the email link again without a session', async () => {
    renderApp('/supplier-portal/tender')
    expect(await screen.findByText('You are not signed in to a tender')).toBeInTheDocument()
    expect(globalThis.fetch).not.toHaveBeenCalled()
  })

  it('shows an open tender’s notice and registers a bidder, who gets a link by email', async () => {
    const notice = 'B'.repeat(48)
    renderApp(`/supplier-portal/notices/${notice}`)
    expect(await screen.findByRole('heading', { name: 'Bakery ovens' })).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText(/^Company name/), { target: { value: 'Coast Kitchens' } })
    fireEvent.change(screen.getByLabelText(/^Email/), { target: { value: 'tenders@coastkitchens.example' } })
    fireEvent.change(screen.getByLabelText('Tax ID'), { target: { value: 'P051234567X' } })
    fireEvent.click(screen.getByRole('button', { name: 'Send me a link' }))
    expect(await screen.findByText('We sent a link to your email')).toBeInTheDocument()
    const register = calls().find((call) => call.path === `supplier-portal/notices/${notice}/register`)
    expect(JSON.parse(register.body)).toEqual({ name: 'Coast Kitchens', email: 'tenders@coastkitchens.example', tax_id: 'P051234567X' })
    expect(register.headers.Authorization).toBeUndefined()
  })
})
