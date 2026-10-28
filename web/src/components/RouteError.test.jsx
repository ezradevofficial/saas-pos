// NFR-10: page errors reach Sentry and the user sees what to do; a stale build reloads.
import { render, screen } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { RouteError } from './RouteError'
import { reportError } from '@/lib/sentry'
import { reloadForNewBuild } from '@/lib/staleChunks'

vi.mock('@/lib/sentry', () => ({ reportError: vi.fn() }))
vi.mock('@/lib/staleChunks', async (importOriginal) => ({ ...(await importOriginal()), reloadForNewBuild: vi.fn(() => true) }))

function renderFailing(error) {
  const Boom = () => {
    throw error
  }
  const router = createMemoryRouter([{ errorElement: <RouteError />, children: [{ path: '/', element: <Boom /> }] }])
  return render(<RouterProvider router={router} />)
}

describe('RouteError (NFR-10)', () => {
  beforeEach(() => vi.clearAllMocks())

  it('sends a page crash to Sentry and offers a reload', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    renderFailing(new TypeError("Cannot read properties of undefined (reading 'includes')"))
    expect(await screen.findByText('Something went wrong on this page')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Reload page' })).toBeInTheDocument()
    expect(reportError).toHaveBeenCalledWith(expect.any(TypeError), expect.objectContaining({ boundary: 'route' }))
    expect(reloadForNewBuild).not.toHaveBeenCalled()
  })

  it('reloads for the new build when a page file is gone after a deploy, without reporting it', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    renderFailing(new TypeError('Failed to fetch dynamically imported module: https://app.example/assets/Employees-CpTGrhMu.js'))
    expect(await screen.findByText('A new version is available. Loading it…')).toBeInTheDocument()
    expect(reloadForNewBuild).toHaveBeenCalledOnce()
    expect(reportError).not.toHaveBeenCalled()
  })

  it('reports and explains a stale page that is still missing after the reload', async () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    reloadForNewBuild.mockReturnValueOnce(false)
    renderFailing(new TypeError('Failed to fetch dynamically imported module: https://app.example/assets/x.js'))
    expect(await screen.findByText('This page could not load')).toBeInTheDocument()
    expect(reportError).toHaveBeenCalledWith(expect.any(TypeError), expect.objectContaining({ stale_chunk: 'true' }))
  })
})
