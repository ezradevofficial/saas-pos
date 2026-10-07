import { fireEvent, screen, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { mockApi, renderApp, resetSession, signedIn } from '@/test/renderApp'
import { sampleBrand } from '@/theme/sampleBrand'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const root = document.documentElement

describe('Appearance', () => {
  beforeEach(() => {
    resetSession()
    vi.clearAllMocks()
    signedIn()
    mockApi(api)
  })
  afterEach(() => {
    root.removeAttribute('data-theme')
    root.classList.remove('dark')
    root.removeAttribute('style')
  })

  it('switches data-theme at once for each of the four themes', async () => {
    renderApp('/settings/appearance')
    await screen.findByRole('heading', { level: 1, name: 'Appearance' })
    await waitFor(() => expect(root.dataset.theme).toBe('light'))

    fireEvent.click(screen.getByRole('radio', { name: /Dark/ }))
    await waitFor(() => expect(root.dataset.theme).toBe('dark'))
    expect(root).toHaveClass('dark')

    fireEvent.click(screen.getByRole('radio', { name: /Executive/ }))
    await waitFor(() => expect(root.dataset.theme).toBe('executive'))
    expect(root).not.toHaveClass('dark')

    fireEvent.click(screen.getByRole('radio', { name: /Warm/ }))
    await waitFor(() => expect(root.dataset.theme).toBe('warm'))
    expect(screen.getByRole('radio', { name: /Warm/ })).toBeChecked()
  })

  it('previews a tenant brand and resets to the default look', async () => {
    renderApp('/settings/appearance')
    fireEvent.click(await screen.findByRole('radio', { name: /Dark/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Preview a tenant brand' }))

    const expected = sampleBrand('dark')
    await waitFor(() => expect(root.style.getPropertyValue('--primary')).toBe(expected.primary))
    expect(root.style.getPropertyValue('--accent')).toBe(expected.accent)
    expect(root.style.getPropertyValue('--primary-tint')).toBe(expected['primary-tint'])
    expect(screen.getByText('Previewing a sample brand')).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Reset to default' }))
    await waitFor(() => expect(root.style.getPropertyValue('--primary')).toBe(''))
    expect(root.dataset.theme).toBe('light')
  })

  it('derives darker tints for dark themes', () => {
    expect(sampleBrand('dark')['primary-tint']).not.toBe(sampleBrand('light')['primary-tint'])
  })
})
