// Helpers the CRM screens share that are not components: a record action,
// file downloads and the list or board toggle.
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { saveFile } from '@/lib/files'
import { crmKey } from './crmData'

/**
 * A record action (`POST path` with an optional body): the answer replaces
 * the cached detail at `key`, a toast says it was done, the module's lists
 * refresh; a refusal shows as a toast (or through `onError`).
 */
export function useCrmAction(key, { onDone, onError } = {}) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ path, body, method = 'post' }) => api[method](path, body ?? {}),
    onSuccess: async (response, variables) => {
      if (key && response?.data && !Array.isArray(response.data) && variables.replace !== false) queryClient.setQueryData(key, response)
      if (variables.done) toast.success(t(variables.done))
      onDone?.(response, variables)
      await queryClient.invalidateQueries({ queryKey: crmKey })
    },
    onError: (error) => (onError ? onError(error) : toast.error(errorMessage(error))),
  })
}

/** Downloads a file of the API with the user's token (a quote's PDF). */
export function useDownload() {
  return useMutation({
    mutationFn: async ({ path, name }) => {
      const { blob, filename } = await api.download(path)
      saveFile(blob, filename ?? name)
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
}

/** List and board share one toggle kept in the URL (`?view=board` or `?view=list`), `fallback` when absent. */
export function useViewToggle(fallback) {
  const [params, setParams] = useSearchParams()
  const asked = params.get('view')
  const view = asked === 'list' || asked === 'board' ? asked : fallback
  const setView = (next) =>
    setParams(
      (current) => {
        const copy = new URLSearchParams(current)
        if (next === fallback) copy.delete('view')
        else copy.set('view', next)
        return copy
      },
      { replace: true },
    )
  return [view, setView]
}
