import { act, render, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockRoutes, OWNER, renderApp, resetSession, signedIn, tenantWide } from '@/test/renderApp'
import { ThemeProvider, useTheme } from './ThemeProvider'
import { themePlace } from './useBrand'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn(), download: vi.fn() },
}))

const root = document.documentElement
const BRAND = { theme: { preset: 'warm', colors: { primary: '#7c2d12' }, corners: 'soft' }, assets: {}, hidePlatform: false }

function Probe() {
  const { theme, setTheme, clearTheme } = useTheme()
  return (
    <div>
      <span data-testid="theme">{theme}</span>
      <button onClick={() => setTheme('dark')}>dark</button>
      <button onClick={() => clearTheme()}>clear</button>
    </div>
  )
}

describe('tenant theme at runtime (BR-02, BR-08)', () => {
  beforeEach(() => {
    resetSession()
    root.removeAttribute('style')
    root.removeAttribute('data-theme')
    root.classList.remove('dark')
  })

  it('applies the preset and compiled overrides, and recompiles for the personal dark choice', () => {
    render(<ThemeProvider userId="u1" brand={BRAND}><Probe /></ThemeProvider>)
    expect(root.dataset.theme).toBe('warm')
    expect(root.style.getPropertyValue('--primary')).toBe('#7c2d12')
    expect(root.style.getPropertyValue('--radius-lg')).toBe('16px')

    act(() => screen.getByText('dark').click())
    expect(root).toHaveClass('dark')
    // Dark mode lifts the brand colour so it reads on dark surfaces; corners stay.
    expect(root.style.getPropertyValue('--primary')).not.toBe('#7c2d12')
    expect(root.style.getPropertyValue('--radius-lg')).toBe('16px')

    act(() => screen.getByText('clear').click())
    expect(root.dataset.theme).toBe('warm')
  })

  it('never sets tokens a theme may not change', () => {
    render(<ThemeProvider brand={{ theme: { preset: 'light', colors: { primary: '#0b5d6e', danger: '#00ff00' }, focus: '#ff00ff' } }}><Probe /></ThemeProvider>)
    expect(root.style.getPropertyValue('--danger')).toBe('')
    expect(root.style.getPropertyValue('--focus')).toBe('')
  })

  it('resolves at the chosen company when a role covers it, else a branch', () => {
    const tenant = [{ name: 'core.company.view', scopes: [{ type: 'tenant', id: 't-1' }] }]
    const branch = [{ name: 'pos.sale.view', scopes: [{ type: 'branch', id: 'b-1' }] }]
    expect(themePlace(tenant, 'c-1')).toEqual({ company: 'c-1' })
    expect(themePlace(branch, 'c-1')).toEqual({ branch: 'b-1' })
    expect(themePlace([], null)).toEqual({})
  })

  it('fetches the resolved theme after sign-in and shows the logo and "Powered by"', async () => {
    signedIn()
    mockRoutes(api, [
      [/^config\/theme\/resolved/, { data: { payload: { preset: 'executive', colors: { primary: '#0b5d6e' }, asset_urls: { logo_dark: 'https://files.example/logo-dark.png' } } } }],
    ], { permissions: tenantWide(['core.company.view']) })
    renderApp('/')

    expect(await screen.findByRole('img', { name: OWNER.tenant.name })).toHaveAttribute('src', 'https://files.example/logo-dark.png')
    await waitFor(() => expect(root.style.getPropertyValue('--primary')).toBe('#0b5d6e'))
    expect(screen.getAllByText(/powered by/i).length).toBeGreaterThan(0)
  })

  it('hides "Powered by" when the platform hid it', async () => {
    signedIn()
    mockRoutes(api, [[/^config\/theme\/resolved/, { data: { payload: { preset: 'light', asset_urls: {} } } }]], {
      user: { ...OWNER, tenant: { ...OWNER.tenant, hide_platform: true } },
      permissions: tenantWide(['core.company.view']),
    })
    renderApp('/')
    await screen.findAllByText(OWNER.name)
    expect(screen.queryByText(/powered by/i)).not.toBeInTheDocument()
  })

  it('brands the sign-in page of a tenant host (BR-04)', async () => {
    mockRoutes(api, [
      [/^public\/branding\?host=/, {
        data: {
          tenant_name: 'Amani Retail Group',
          theme: { preset: 'light', colors: { primary: '#7c2d12' } },
          welcome: 'Karibu. Sign in to run the shop.',
          logo_light_url: 'https://files.example/logo.png',
          background_url: 'https://files.example/bg.jpg',
          hide_platform: false,
        },
      }],
    ])
    renderApp('/sign-in')
    expect(await screen.findByText('Karibu. Sign in to run the shop.')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Amani Retail Group' })).toHaveAttribute('src', 'https://files.example/logo.png')
    expect(screen.getByTestId('auth-background')).toHaveAttribute('src', 'https://files.example/bg.jpg')
    expect(screen.getByText(/powered by/i)).toBeInTheDocument()
    await waitFor(() => expect(root.style.getPropertyValue('--primary')).toBe('#7c2d12'))
  })
})

describe('brand fonts (BR-02)', () => {
  it('loads a curated font only when a theme selects it', async () => {
    const { loadBrandFonts } = await import('./brandFonts')
    await expect(loadBrandFonts({ 'font-sans': '"Geist Variable", sans-serif' })).resolves.toEqual([])
    const loaded = await loadBrandFonts({ 'font-sans': '"IBM Plex Sans Variable", sans-serif', 'font-display': '"Newsreader Variable", serif' })
    expect(loaded).toHaveLength(2)
  })
})
