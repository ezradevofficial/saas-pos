import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/api/client'

// IMP-01..IMP-03: the import wizard's API calls.

export const IMPORT_PERMISSION = 'core.import.run'
export const RUNNING = ['checking', 'importing', 'undoing']
/** Status dot per import status (a dot and a word, never colour alone). */
export const STATUS_TONE = {
  ready: 'neutral',
  checking: 'info',
  checked: 'info',
  importing: 'info',
  completed: 'success',
  failed: 'danger',
  undoing: 'warning',
  partly_undone: 'warning',
  undone: 'neutral',
}

export const IMPORT_STATUSES = ['ready', 'checking', 'checked', 'importing', 'completed', 'failed', 'undoing', 'partly_undone', 'undone']

/** The importers the user may run, with their columns (labels in the UI language). */
export function useImporters() {
  return useQuery({ queryKey: ['imports', 'importers'], queryFn: () => api.get('imports/importers'), select: (answer) => answer?.data ?? [] })
}

/** How often work in progress (a dry run, an import, an undo, an export build) is checked: every 2 s, then not at all. */
export const PROGRESS_POLL_MS = 2000

/** One import; polled while a dry run, import or undo runs (IMP-03 progress). */
export function useImport(id) {
  return useQuery({
    queryKey: ['imports', 'one', id],
    queryFn: () => api.get(`imports/${id}`),
    enabled: Boolean(id),
    select: (answer) => answer?.data ?? null,
    refetchInterval: (query) => (RUNNING.includes(query.state.data?.data?.status) ? PROGRESS_POLL_MS : false),
  })
}

/** The first 200 rows with their errors (IMP-02), once the mapping is complete. */
export function usePreview(id, enabled) {
  return useQuery({
    queryKey: ['imports', 'preview', id],
    queryFn: () => api.get(`imports/${id}/preview`),
    enabled: Boolean(id) && enabled,
    select: (answer) => answer?.data ?? null,
    retry: false,
  })
}

/** Upload, change, start and undo; each refreshes the import it touched. */
export function useImportActions(id) {
  const queryClient = useQueryClient()
  const settle = (answer) => {
    const importId = answer?.data?.id ?? id
    if (importId) queryClient.setQueryData(['imports', 'one', importId], answer)
    queryClient.invalidateQueries({ queryKey: ['imports', 'list'] })
    queryClient.invalidateQueries({ queryKey: ['imports', 'preview', importId] })
    return answer
  }

  return {
    upload: useMutation({
      mutationFn: ({ importer, file }) => {
        const form = new FormData()
        form.append('importer', importer)
        form.append('file', file)
        return api.upload('imports', form)
      },
      onSuccess: settle,
    }),
    update: useMutation({ mutationFn: (body) => api.patch(`imports/${id}`, body), onSuccess: settle }),
    dryRun: useMutation({ mutationFn: () => api.post(`imports/${id}/dry-run`), onSuccess: settle }),
    run: useMutation({ mutationFn: () => api.post(`imports/${id}/run`), onSuccess: settle }),
    undo: useMutation({ mutationFn: () => api.post(`imports/${id}/undo`), onSuccess: settle }),
  }
}
