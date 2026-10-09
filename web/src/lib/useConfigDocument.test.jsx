import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { ApiError, api } from '@/api/client'
import { useConfigDocument } from './useConfigDocument'

vi.mock('@/api/client', async (importOriginal) => ({
  ...(await importOriginal()),
  api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const BRANCH = { type: 'branch', id: 'b1' }
const LISTED = [{ id: 'd-branch', key: 'items', scope: BRANCH }]
const DOCUMENT = {
  data: {
    id: 'd-branch',
    scope: BRANCH,
    published: { version: 1, status: 'published', payload: { columns: [{ id: 'name' }] } },
    draft: { version: 2, revision: 5, status: 'draft', payload: { columns: [{ id: 'code' }] } },
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
    // Exactly the scope it edits.
    expect(api.get).toHaveBeenCalledWith('config/list_view?key=items&scope_type=branch&scope_id=b1&per_page=1')
    expect(api.get).toHaveBeenCalledWith('config/list_view/d-branch')
    expect(result.current.payload).toEqual({ columns: [{ id: 'code' }] })
    expect(result.current.problems).toHaveLength(1)
    expect(result.current.history).toHaveLength(2)

    vi.mocked(api.post).mockResolvedValue(DOCUMENT)
    await act(() => result.current.publish())
    await act(() => result.current.rollback(1))
    await act(() => result.current.copyTo({ type: 'branch', id: 'b2' }, 'draft'))
    expect(api.post).toHaveBeenCalledWith('config/list_view/d-branch/publish', { revision: 5 })
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
    expect(api.post).toHaveBeenCalledWith('config/list_view', { key: 'items', scope_type: 'branch', scope_id: 'b1', revision: null, payload: { columns: [] }, name: 'Items' })
  })

  it('queries the tenant scope without an id', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: [] })

    const { result } = renderHook(() => useConfigDocument('list_view', 'items'), { wrapper })

    await waitFor(() => expect(result.current.isLoading).toBe(false))
    expect(api.get).toHaveBeenCalledWith('config/list_view?key=items&scope_type=tenant&per_page=1')
  })

  it('sends the revision it edits, one save at a time, and moves it with its own saves', async () => {
    vi.mocked(api.get).mockImplementation(async (path) => (path.startsWith('config/list_view?') ? { data: LISTED } : DOCUMENT))
    const answer = (revision) => ({ ...DOCUMENT, data: { ...DOCUMENT.data, draft: { ...DOCUMENT.data.draft, revision } } })
    let release
    vi.mocked(api.post)
      .mockImplementationOnce(() => new Promise((resolve) => (release = () => resolve(answer(6)))))
      .mockResolvedValueOnce(answer(7))

    const { result } = renderHook(() => useConfigDocument('list_view', 'items', BRANCH), { wrapper })
    await waitFor(() => expect(result.current.document?.id).toBe('d-branch'))

    let first
    let second
    act(() => {
      first = result.current.saveDraft({ columns: [{ id: 'a' }] })
      second = result.current.saveDraft({ columns: [{ id: 'b' }] })
    })
    // The second save waits for the first, then names the revision the first produced.
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(1))
    expect(api.post).toHaveBeenLastCalledWith('config/list_view', expect.objectContaining({ revision: 5, payload: { columns: [{ id: 'a' }] } }))
    await act(async () => {
      release()
      await first
      await second
    })
    expect(api.post).toHaveBeenCalledTimes(2)
    expect(api.post).toHaveBeenLastCalledWith('config/list_view', expect.objectContaining({ revision: 6, payload: { columns: [{ id: 'b' }] } }))
    expect(result.current.revision).toBe(7)
  })

  it('reports a draft someone else changed and adopts it on reload', async () => {
    vi.mocked(api.get).mockImplementation(async (path) => (path.startsWith('config/list_view?') ? { data: LISTED } : DOCUMENT))
    const theirs = { ...DOCUMENT.data, draft: { ...DOCUMENT.data.draft, revision: 9, payload: { columns: [{ id: 'price' }] } } }
    vi.mocked(api.post).mockRejectedValueOnce(
      new ApiError({ status: 409, code: 'config_changed', message: 'Someone changed this draft.', data: { data: theirs, meta: { problems: [] } } }),
    )

    const { result } = renderHook(() => useConfigDocument('list_view', 'items', BRANCH), { wrapper })
    await waitFor(() => expect(result.current.document?.id).toBe('d-branch'))

    await act(async () => {
      await expect(result.current.saveDraft({ columns: [] })).rejects.toMatchObject({ code: 'config_changed' })
    })
    await waitFor(() => expect(result.current.conflict?.code).toBe('config_changed'))
    expect(result.current.conflict.document.draft.revision).toBe(9)

    // Reload adopts their draft; the next publish names their revision.
    vi.mocked(api.get).mockImplementation(async (path) => (path.startsWith('config/list_view?') ? { data: LISTED } : { data: theirs, meta: { problems: [] } }))
    await act(() => result.current.reload())
    expect(result.current.conflict).toBeNull()
    await waitFor(() => expect(result.current.payload).toEqual({ columns: [{ id: 'price' }] }))
    vi.mocked(api.post).mockResolvedValueOnce({ data: theirs, meta: { problems: [] } })
    await act(() => result.current.publish())
    expect(api.post).toHaveBeenLastCalledWith('config/list_view/d-branch/publish', { revision: 9 })
  })

  it('asks the API to replace a draft at the copy target only when told to', async () => {
    vi.mocked(api.get).mockImplementation(async (path) => (path.startsWith('config/list_view?') ? { data: LISTED } : DOCUMENT))
    vi.mocked(api.post).mockResolvedValue(DOCUMENT)

    const { result } = renderHook(() => useConfigDocument('list_view', 'items', BRANCH), { wrapper })
    await waitFor(() => expect(result.current.document?.id).toBe('d-branch'))

    await act(() => result.current.copyTo({ type: 'branch', id: 'b2' }, 'draft', { replace: true }))
    expect(api.post).toHaveBeenLastCalledWith('config/list_view/d-branch/copy', { scope_type: 'branch', scope_id: 'b2', from: 'draft', replace: true })
  })
})
