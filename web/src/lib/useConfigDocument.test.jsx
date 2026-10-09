import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { api } from '@/api/client'
import { useConfigDocument } from './useConfigDocument'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const BRANCH = { type: 'branch', id: 'b1' }
const LISTED = [
  { id: 'd-tenant', key: 'items', scope: { type: 'tenant', id: null } },
  { id: 'd-branch', key: 'items', scope: BRANCH },
]
const DOCUMENT = {
  data: {
    id: 'd-branch',
    scope: BRANCH,
    published: { version: 1, status: 'published', payload: { columns: [{ id: 'name' }] } },
    draft: { version: 2, status: 'draft', payload: { columns: [{ id: 'code' }] } },
    history: [{ version: 2 }, { version: 1 }],
  },
  meta: { problems: [{ path: 'columns.0.width', code: 'max', message: 'Too wide.' }] },
}

function wrapper({ children }) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useConfigDocument (LAY-06)', () => {
  beforeEach(() => {
    vi.mocked(api.get).mockReset()
    vi.mocked(api.post).mockReset()
  })

  it('finds the document of the key at the scope and reads its versions', async () => {
    vi.mocked(api.get).mockImplementation(async (path) => (path.startsWith('config/list_view?') ? { data: LISTED } : DOCUMENT))

    const { result } = renderHook(() => useConfigDocument('list_view', 'items', BRANCH), { wrapper })

    await waitFor(() => expect(result.current.document?.id).toBe('d-branch'))
    expect(api.get).toHaveBeenCalledWith('config/list_view?key=items&per_page=200')
    expect(api.get).toHaveBeenCalledWith('config/list_view/d-branch')
    expect(result.current.payload).toEqual({ columns: [{ id: 'code' }] })
    expect(result.current.problems).toHaveLength(1)
    expect(result.current.history).toHaveLength(2)

    vi.mocked(api.post).mockResolvedValue(DOCUMENT)
    await act(() => result.current.publish())
    await act(() => result.current.rollback(1))
    await act(() => result.current.copyTo({ type: 'branch', id: 'b2' }, 'draft'))
    expect(api.post).toHaveBeenCalledWith('config/list_view/d-branch/publish')
    expect(api.post).toHaveBeenCalledWith('config/list_view/d-branch/rollback', { version: 1 })
    expect(api.post).toHaveBeenCalledWith('config/list_view/d-branch/copy', { scope_type: 'branch', scope_id: 'b2', from: 'draft' })
  })

  it('saves the first draft for a key and scope with nothing saved yet', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [] })
    vi.mocked(api.post).mockResolvedValue(DOCUMENT)

    const { result } = renderHook(() => useConfigDocument('list_view', 'items', BRANCH), { wrapper })

    await waitFor(() => expect(result.current.isLoading).toBe(false))
    expect(result.current.document).toBeNull()
    expect(result.current.payload).toBeNull()

    await act(() => result.current.saveDraft({ columns: [] }, { name: 'Items' }))
    expect(api.post).toHaveBeenCalledWith('config/list_view', { key: 'items', scope_type: 'branch', scope_id: 'b1', payload: { columns: [] }, name: 'Items' })
  })
})
