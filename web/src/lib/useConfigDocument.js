import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef } from 'react'
import { api } from '@/api/client'

const TENANT = { type: 'tenant', id: null }

const sameScope = (document, scope) => document.scope?.type === scope.type && (document.scope?.id ?? null) === (scope.id ?? null)

/**
 * One piece of versioned configuration (LAY-06) for the designers: the
 * document of `kind` with `key` at `scope` ({ type, id }: tenant, company,
 * branch, location, role or user), its published version, draft, history
 * and the problems that block publishing, and the actions on it.
 *
 * - saveDraft(payload, { name }) creates the document when it does not
 *   exist yet (POST config/{kind}), else edits its one draft;
 * - publish(), discardDraft(), rollback(version);
 * - copyTo({ type, id }, from = 'published' | 'draft') puts it into another
 *   company, branch or location as that place's draft.
 *
 * `payload` is what a designer edits: the draft's, else the live one's,
 * else null (nothing saved yet). The API checks every permission again
 * (RBAC-09); the hook never decides who may do what.
 */
export function useConfigDocument(kind, key = 'default', scope = TENANT, { enabled = true } = {}) {
  const queryClient = useQueryClient()
  const where = { type: scope?.type ?? 'tenant', id: scope?.id ?? null }
  const listKey = ['config', kind, 'list', key]

  const list = useQuery({
    queryKey: listKey,
    queryFn: () => api.get(`config/${kind}?key=${encodeURIComponent(key)}&per_page=200`),
    enabled: enabled && Boolean(kind),
  })
  const found = (list.data?.data ?? []).find((document) => sameScope(document, where)) ?? null
  const documentKey = ['config', kind, 'document', found?.id]

  const detail = useQuery({
    queryKey: documentKey,
    queryFn: () => api.get(`config/${kind}/${found.id}`),
    enabled: enabled && Boolean(found?.id),
  })

  // Every write answers the whole document: keep it, and refresh the lists of the kind.
  // A document just created is known at once, so "save, then publish" works in one go.
  const created = useRef({})
  const scopeKey = `${kind}|${key}|${where.type}|${where.id ?? ''}`
  const settle = async (response) => {
    const id = response?.data?.id
    if (id) {
      created.current[`${kind}|${key}|${response.data.scope?.type}|${response.data.scope?.id ?? ''}`] = id
      queryClient.setQueryData(['config', kind, 'document', id], response)
    }
    await queryClient.invalidateQueries({ queryKey: ['config', kind, 'list'] })
    return response
  }

  const documentId = () => {
    const id = found?.id ?? created.current[scopeKey]
    if (!id) throw new Error('No configuration document yet: save a draft first.')
    return id
  }

  const save = useMutation({
    mutationFn: ({ payload, name }) =>
      api.post(`config/${kind}`, { key, scope_type: where.type, scope_id: where.id, payload, ...(name === undefined ? {} : { name }) }),
    onSuccess: settle,
  })
  const publish = useMutation({ mutationFn: () => api.post(`config/${kind}/${documentId()}/publish`), onSuccess: settle })
  const discard = useMutation({ mutationFn: () => api.post(`config/${kind}/${documentId()}/discard-draft`), onSuccess: settle })
  const rollback = useMutation({ mutationFn: (version) => api.post(`config/${kind}/${documentId()}/rollback`, { version }), onSuccess: settle })
  const copy = useMutation({
    mutationFn: ({ target, from }) => api.post(`config/${kind}/${documentId()}/copy`, { scope_type: target.type, scope_id: target.id, from }),
    onSuccess: settle,
  })

  const document = detail.data?.data ?? null
  const published = document?.published ?? null
  const draft = document?.draft ?? null

  return {
    document,
    published,
    draft,
    history: document?.history ?? [],
    problems: detail.data?.meta?.problems ?? [],
    payload: draft?.payload ?? published?.payload ?? null,
    isLoading: list.isLoading || detail.isLoading,
    error: list.error ?? detail.error ?? null,
    saveDraft: (payload, { name } = {}) => save.mutateAsync({ payload, name }),
    publish: () => publish.mutateAsync(),
    discardDraft: () => discard.mutateAsync(),
    rollback: (version) => rollback.mutateAsync(version),
    copyTo: (target, from = 'published') => copy.mutateAsync({ target, from }),
    pending: {
      save: save.isPending,
      publish: publish.isPending,
      discard: discard.isPending,
      rollback: rollback.isPending,
      copy: copy.isPending,
    },
  }
}
