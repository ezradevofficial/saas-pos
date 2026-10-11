// TPL-04: the receipt's outputs on a sale: print in a sandboxed frame, PDF, share link and email.
import { QueryClient } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { AppProviders } from '@/App'
import { api } from '@/api/client'
import { apiError, OWNER, resetSession, signedIn } from '@/test/renderApp'
import { SaleOutputs } from './SaleOutputs'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn(), upload: vi.fn() },
}))

const SALE = { id: 's-1', receipt_number: 'R-0001', customer: { email: 'wanjiru@example.com' } }
const SHARE = { id: 'sh-1', url: 'https://receipts.example.test/r/abc', whatsapp_url: 'https://wa.me/?text=abc', status: 'active', expires_at: '2026-10-18T10:00:00Z', created_at: '2026-10-11T10:00:00Z', access_count: 0 }

function renderOutputs(props = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  const router = createMemoryRouter([{ path: '/', element: <SaleOutputs sale={SALE} canShare when={(value) => value} {...props} /> }], { initialEntries: ['/'] })
  return render(
    <AppProviders queryClient={queryClient}>
      <RouterProvider router={router} />
    </AppProviders>,
  )
}

describe('Sale outputs (TPL-04)', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
    api.get.mockImplementation(async (path) => {
      if (path === 'me') return { data: OWNER }
      if (path === 'me/permissions') return { permissions: [], modules: ['core', 'pos'] }
      if (path === 'pos/sales/s-1/shares') return { data: [] }
      throw apiError(404, 'not_found', 'Not found.')
    })
  })

  it('prints in a frame that may run its print call but never reach this page', async () => {
    api.download.mockResolvedValue({ blob: new Blob(['<html><body><p>Receipt</p><script>alert(1)</script></body></html>'], { type: 'text/html' }), filename: 'R-0001.html' })
    renderOutputs()
    fireEvent.click(await screen.findByRole('button', { name: 'Print' }))

    await waitFor(() => expect(document.querySelector('iframe')).not.toBeNull())
    const frame = document.querySelector('iframe')
    expect(api.download).toHaveBeenCalledWith('pos/sales/s-1/receipt?format=html')
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts allow-modals')
    expect(frame.getAttribute('sandbox')).not.toMatch(/allow-same-origin/)
    expect(frame.getAttribute('srcdoc')).not.toMatch(/alert\(1\)/)
    expect(frame.getAttribute('srcdoc')).toMatch(/window\.print\(\)/)
  })

  it('downloads the PDF under the receipt number', async () => {
    api.download.mockResolvedValue({ blob: new Blob(['%PDF'], { type: 'application/pdf' }), filename: null })
    const created = vi.fn(() => 'blob:receipt')
    const revoked = vi.fn()
    Object.assign(URL, { createObjectURL: created, revokeObjectURL: revoked })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    renderOutputs()
    fireEvent.click(await screen.findByRole('button', { name: 'Download PDF' }))

    await waitFor(() => expect(api.download).toHaveBeenCalledWith('pos/sales/s-1/receipt?format=pdf'))
    await waitFor(() => expect(click).toHaveBeenCalled())
    expect(click.mock.contexts.at(-1).download).toBe('R-0001.pdf')
    click.mockRestore()
  })

  it('opens the WhatsApp window in the click, then sends it to the share link', async () => {
    const opened = { opener: 'page', location: { href: '' }, close: vi.fn() }
    const open = vi.spyOn(window, 'open').mockReturnValue(opened)
    let answer
    api.post.mockImplementation(() => new Promise((resolve) => (answer = () => resolve({ data: SHARE }))))
    renderOutputs()
    fireEvent.click(await screen.findByRole('button', { name: 'Share on WhatsApp' }))

    // Synchronously, before the API answers (Safari blocks a window opened later).
    expect(open).toHaveBeenCalledTimes(1)
    expect(opened.opener).toBeNull()
    answer()
    await waitFor(() => expect(opened.location.href).toBe(SHARE.whatsapp_url))
    expect(api.post).toHaveBeenCalledWith('pos/sales/s-1/share')
    expect(await screen.findByDisplayValue(SHARE.url)).toBeInTheDocument()
    expect(opened.close).not.toHaveBeenCalled()
    open.mockRestore()
  })

  it('closes the blank window when the link cannot be made', async () => {
    const opened = { opener: 'page', location: { href: '' }, close: vi.fn() }
    const open = vi.spyOn(window, 'open').mockReturnValue(opened)
    api.post.mockRejectedValue(apiError(403, 'forbidden', 'You can’t share receipts at this branch.'))
    renderOutputs()
    fireEvent.click(await screen.findByRole('button', { name: 'Share on WhatsApp' }))

    await waitFor(() => expect(opened.close).toHaveBeenCalled())
    expect(api.post).toHaveBeenCalledWith('pos/sales/s-1/share')
    expect(opened.location.href).toBe('')
    open.mockRestore()
  })

  it('shows the API’s reason on the address field when the email is not valid', async () => {
    api.post.mockRejectedValue(Object.assign(apiError(422, 'validation_failed', 'The given data was invalid.'), { errors: { email: ['Type a valid email address.'] } }))
    renderOutputs()
    fireEvent.click(await screen.findByRole('button', { name: 'Email' }))
    const dialog = await screen.findByRole('dialog', { name: 'Email the receipt' })
    fireEvent.change(within(dialog).getByLabelText('Email address'), { target: { value: 'not-an-address' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Send email' }))

    expect(await within(dialog).findByText('Type a valid email address.')).toBeInTheDocument()
    expect(api.post).toHaveBeenCalledWith('pos/sales/s-1/email', { email: 'not-an-address', language: 'en' })
  })
})
