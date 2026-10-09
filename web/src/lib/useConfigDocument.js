import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/api/client'

const TENANT = { type: 'tenant', id: null }

const sameScope = (document, scope) => document.scope?.type === scope.type && (document.scope?.id ?? null) === (scope.id ?? null)

/** The list filter of exactly one scope (the tenant scope has no id). */
const scopeQuery = (scope) =>
  scope.type === 'tenant' ? 'scope_type=tenant' : `scope_type=${encodeURIComponent(scope.type)}&scope_id=${encodeURIComponent(scope.id ?? '')}`

/**
 * One piece of versioned configuration (LAY-06) for the designers: the
 * document of `kind` with `key` at `scope` ({ type, id }: tenant, company,
 * branch, location, role or user), its published version, draft, history
 * and the problems that block publishing, and the actions on it.
 *
 * - saveDraft(payload, { name }) creates the document when it does not
 *   exist yet (POST config/{kind}), else edits its one draft; saves run
 *   one after another;
 * - publish(), discardDraft(), rollback(version);
 * - copyTo({ type, id }, from = 'published' | 'draft', { replace }) puts it
 *   into another company, branch or location as that place's draft; a
 *   draft already there is refused (code config_draft_exists) unless
 *   replace is true.
 *
 * Concurrency: saves, publish and discard send `revision`, the draft
 * revision this editor works from (taken when the document first loads,
 * after each of its own writes, and on reload(); never from a background
 * refetch). When someone else changed the draft, the API answers 409
 * config_changed and `conflict` is set ({ code, document }: the document
 * as it is now); the designer says "Someone changed this draft" and offers
 * reload(), which adopts that document and clears the conflict.
 *
 * `payload` is what a designer edits: the draft's, else the live one's,
 * else null (nothing saved yet). The API checks every permission again
 * (RBAC-09); the hook never decides who may do what.
 */
export function useConfigDocument(kind, key = 'default', scope = TENANT, { enabled = true } = {}) {
  const queryClient = useQueryClient()
  const where = { type: scope?.type ?? 'tenant', id: scope?.type === 'tenant' ? null : (scope?.id ?? null) }
  const listKey = ['config', kind, 'list', key, where.type, where.id]

  const list = useQuery({
    queryKey: listKey,
    queryFn: () => api.get(`config/${kind}?key=${encodeURIComponent(key)}&${scopeQuery(where)}&per_page=1`),
    enabled: enabled && Boolean(kind) && (where.type === 'tenant' || Boolean(where.id)),
  })
  const found = (list.data?.data ?? []).find((document) => sameScope(document, where)) ?? null
  const documentKey = ['config', kind, 'document', found?.id]

  const detail = useQuery({
    queryKey: documentKey,
    queryFn: () => api.get(`config/${kind}/${found.id}`),
    enabled: enabled && Boolean(found?.id),
  })

  // The draft revision this editor works from (null: it saw no draft).
  // (a ref for the requests, mirrored in state for the designer).
  const base = useRef({ id: null, revision: null })
  const [revision, setRevision] = useState(null)
  const adopt = (document) => {
    base.current = { id: document?.id ?? null, revision: document?.draft?.revision ?? null }
    setRevision(base.current.revision)
  }
  const loaded = detail.data?.data ?? null
  useEffect(() => {
    // First sight of this document only: a background refetch never moves the base.
    if (loaded?.id && base.current.id !== loaded.id) adopt(loaded)
  }, [loaded])

  const [conflict, setConflict] = useState(null)
  const saves = useRef(Promise.resolve())

  // Every write answers the whole document: keep it, and refresh the lists of the kind.
  // A save, publish or discard leaves the draft as this editor made it, so its revision becomes the base.
  const settle = async (response, { adoptDraft = true } = {}) => {
    const id = response?.data?.id
    if (id) {
      if (adoptDraft) adopt(response.data)
      queryClient.setQueryData(['config', kind, 'document', id], response)
    }
    await queryClient.invalidateQueries({ queryKey: ['config', kind, 'list'] })
    return response
  }

  const onError = (error) => {
    if (error?.status === 409 && error.code === 'config_changed') setConflict({ code: error.code, document: error.data?.data ?? null, meta: error.data?.meta ?? null })
  }

  const documentId = () => {
    if (!found?.id) throw new Error('No configuration document yet: save a draft first.')
    return found.id
  }

  const save = useMutation({
    mutationFn: ({ payload, name }) =>
      api.post(`config/${kind}`, {
        key,
        scope_type: where.type,
        scope_id: where.id,
        revision: base.current.revision,
        payload,
        ...(name === undefined ? {} : { name }),
      }),
    onSuccess: (response) => settle(response),
    onError,
  })
  const publish = useMutation({
    mutationFn: () => api.post(`config/${kind}/${documentId()}/publish`, { revision: base.current.revision }),
    onSuccess: (response) => settle(response),
    onError,
  })
  const discard = useMutation({
    mutationFn: () => api.post(`config/${kind}/${documentId()}/discard-draft`, { revision: base.current.revision }),
    onSuccess: (response) => settle(response),
    onError,
  })
  // Roll back leaves the draft alone; whatever draft the answer shows is not this editor's base.
  const rollback = useMutation({
    mutationFn: (version) => api.post(`config/${kind}/${documentId()}/rollback`, { version }),
    onSuccess: (response) => settle(response, { adoptDraft: false }),
  })
  const copy = useMutation({
    mutationFn: ({ target, from, replace }) =>
      api.post(`config/${kind}/${documentId()}/copy`, { scope_type: target.type, scope_id: target.id, from, ...(replace ? { replace: true } : {}) }),
    // The copy lands elsewhere: this editor's base stays as it is.
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['config', kind] })
      return response
    },
  })

  // One save at a time, so this editor's own saves never conflict with each other.
  const saveDraft = (payload, { name } = {}) => {
    const run = saves.current.then(() => save.mutateAsync({ payload, name }))
    saves.current = run.catch(() => {})
    return run
  }

  const reload = async () => {
    const current = conflict?.document
    setConflict(null)
    if (current?.id) {
      adopt(current)
      queryClient.setQueryData(['config', kind, 'document', current.id], { data: current, meta: conflict.meta ?? { problems: [] } })
    } else {
      adopt(null)
    }
    await queryClient.invalidateQueries({ queryKey: ['config', kind] })
    return current ?? null
  }

  const document = loaded
  const published = document?.published ?? null
  const draft = document?.draft ?? null

  return {
    document,
    published,
    draft,
    history: document?.history ?? [],
    problems: detail.data?.meta?.problems ?? [],
    payload: draft?.payload ?? published?.payload ?? null,
    revision,
    conflict,
    reload,
    isLoading: list.isLoading || detail.isLoading,
    error: list.error ?? detail.error ?? null,
    saveDraft,
    publish: () => publish.mutateAsync(),
    discardDraft: () => discard.mutateAsync(),
    rollback: (version) => rollback.mutateAsync(version),
    copyTo: (target, from = 'published', { replace = false } = {}) => copy.mutateAsync({ target, from, replace }),
    pending: {
      save: save.isPending,
      publish: publish.isPending,
      discard: discard.isPending,
      rollback: rollback.isPending,
      copy: copy.isPending,
    },
  }
}
