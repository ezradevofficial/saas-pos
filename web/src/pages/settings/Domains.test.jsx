import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const SETTINGS = {
  data: { slug: 'amani', host: 'amani.example.app', base_domain: 'example.app', email_from_name: null, email_from_address: null, sms_sender_id: null, hide_platform: false, email_sender_active: false },
  meta: { mail: { spf_include: 'spf.mail.example', dkim_selector: 'pm', dkim_target: 'pm.dkim.example' } },
}
const DOMAIN = {
  id: 'd-1', host: 'erp.company.co.ke', status: 'pending', failure: null, checked_at: null, verified_at: null,
  record: { type: 'TXT', name: '_platform-verify.erp.company.co.ke', value: 'platform-verify=abc123' },
}

describe('Domains page (BR-04, BR-05, BR-06)', () => {
  beforeEach(() => {
    resetSession()
    signedIn()
    mockRoutes(api, [
      ['branding/settings', SETTINGS],
      ['branding/domains', { data: [DOMAIN], meta: { cname_target: 'edge.example.app' } }],
    ], { permissions: tenantWide(['core.domain.manage']) })
  })

  it('shows the TXT record to create, SPF and DKIM guidance, and adds a domain', async () => {
    api.post.mockResolvedValue({ data: { ...DOMAIN, id: 'd-2', host: 'shop.example.org' } })
    renderApp('/settings/domains')

    expect(await screen.findByText('_platform-verify.erp.company.co.ke')).toBeInTheDocument()
    expect(screen.getByText('platform-verify=abc123')).toBeInTheDocument()
    expect(screen.getByText('Waiting for DNS')).toBeInTheDocument()
    expect(screen.getByText('v=spf1 include:spf.mail.example ~all')).toBeInTheDocument()
    expect(screen.getByText(/amani\.example\.app/)).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Domain'), { target: { value: 'shop.example.org' } })
    fireEvent.click(screen.getByRole('button', { name: 'Add domain' }))
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('branding/domains', { host: 'shop.example.org' }))
  })
})
