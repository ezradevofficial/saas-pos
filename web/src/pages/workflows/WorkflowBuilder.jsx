import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ReactFlowProvider } from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, StatusBadge } from '@/components/ds'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { useCompanies } from '@/layouts/companySelection'
import { PHONE_QUERY, useMediaQuery } from '@/lib/useMediaQuery'
import { useLocale } from '@/lib/useLocale'
import { usePageTitle } from '@/lib/usePageTitle'
import { ConfirmDialog } from '@/pages/settings/ConfirmDialog'
import { CopyDialog, PublishDialog, TestDialog, VersionsDialog } from './dialogs'
import { FlowCanvas } from './FlowCanvas'
import { addNode, nextFreePosition, normalizeGraph, pathOf, removeNodes, syncBranches, updateNode } from './graph'
import { Palette } from './Palette'
import { PropertiesPanel } from './PropertiesPanel'
import { useGraphHistory } from './useGraphHistory'
import { useDocumentTypes, useRoleOptions, useUserOptions, useWorkflowRights } from './workflowData'

const SAVE_DELAY = 800
const EMPTY = { nodes: [], edges: [] }
const menuClasses = 'w-max rounded-md border border-border p-1 shadow-lg ring-0'
const menuItemClasses = 'gap-2 rounded-md px-2 py-2 text-body text-ink'

/** Problems the API lists (validate, publish, a refused save): `{ code, message, node }`. */
const problemsOf = (error) => (Array.isArray(error?.data?.problems) ? error.data.problems : null)

const isTyping = (target) => target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))

/**
 * The workflow builder (spec 6.4; WF-02..WF-09, APR-09; BoWorkflow design):
 * a canvas of steps with a palette and the selected step's settings. The
 * draft saves itself as you edit; validation lists what blocks publishing
 * and marks those steps; Publish makes the draft live while documents in
 * progress stay on their version; versions roll back; a flow copies to
 * another company; test with a sample walks the canvas without saving.
 * Read-only on a phone and for people without the edit right.
 */
function Builder({ workflow, type, refetch }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { tenantWide } = usePermissions()
  const { canEdit, canPublish } = useWorkflowRights(workflow)
  const phone = useMediaQuery(PHONE_QUERY)
  const readOnly = phone || !canEdit
  const { actionHandlers, approverTypes, types } = useDocumentTypes()
  const roles = useRoleOptions()
  const users = useUserOptions(canEdit)
  const { companies } = useCompanies()

  const history = useGraphHistory(normalizeGraph((workflow.draft ?? workflow.published)?.graph ?? EMPTY))
  const { graph, commit, preview, settle, undo, redo, reset, version, edited } = history
  const [selectedId, setSelectedId] = useState(null)
  const [issues, setIssues] = useState(null) // list of problems once validated
  const [tested, setTested] = useState(null) // { result, version }: a test describes the graph it ran on
  const [dialog, setDialog] = useState(null) // publish | versions | copy | restore | test
  const [saveState, setSaveState] = useState({ status: 'idle', error: null, version: 0, attempted: 0 })

  const versions = useQuery({ queryKey: ['workflows', workflow.id, 'versions'], queryFn: () => api.get(`workflows/${workflow.id}/versions`) })
  const live = (versions.data?.data ?? []).find((one) => one.status === 'published') ?? (workflow.published ? { ...workflow.published, in_progress: 0 } : null)
  const draft = workflow.draft

  // Autosave: the draft is PUT once editing pauses; the answer lists what still blocks publishing.
  // Dirty: edited since the last save attempt (a failed save waits for the next edit, or Publish).
  const dirty = edited && !readOnly && saveState.version !== version && saveState.attempted !== version
  const latest = useRef({ graph, version, dirty, showIssues: false })
  useEffect(() => {
    latest.current = { graph, version, dirty, showIssues: issues !== null }
  })
  const savePromise = useRef(null)

  const save = useCallback(async () => {
    const { graph: body, version: saving, showIssues } = latest.current
    latest.current = { ...latest.current, dirty: false }
    setSaveState((current) => ({ ...current, status: 'saving', error: null, attempted: saving }))
    try {
      const response = await api.put(`workflows/${workflow.id}/draft`, { graph: body })
      setSaveState((current) => ({ ...current, status: current.attempted === saving ? 'saved' : current.status, error: null, version: saving }))
      if (showIssues && Array.isArray(response?.meta?.problems)) setIssues(response.meta.problems)
      if (response?.data) {
        queryClient.setQueryData(['workflows', workflow.id], (current) =>
          current?.data ? { ...current, data: { ...current.data, draft: { ...response.data, graph: undefined } } } : current,
        )
      }
      return true
    } catch (error) {
      setSaveState((current) => ({ ...current, status: 'error', error }))
      const problems = problemsOf(error)
      if (problems) setIssues(problems)
      return false
    }
  }, [workflow.id, queryClient])

  useEffect(() => {
    if (!dirty) return undefined
    const timer = setTimeout(() => {
      savePromise.current = save()
    }, SAVE_DELAY)
    return () => clearTimeout(timer)
  }, [dirty, version, save])

  /** Saves now if an edit is waiting, and waits for any save on its way. */
  const flush = async () => {
    if (latest.current.dirty || saveState.status === 'error') savePromise.current = save()
    return savePromise.current ? savePromise.current : true
  }

  const testResult = tested && tested.version === version ? tested.result : null

  // Undo and redo from the keyboard (⌘Z / Ctrl+Z, ⇧⌘Z / Ctrl+Y), unless typing in a field.
  useEffect(() => {
    if (readOnly) return undefined
    const onKey = (event) => {
      if (!(event.metaKey || event.ctrlKey) || isTyping(event.target)) return
      const key = event.key.toLowerCase()
      if (key === 'z' && !event.shiftKey) {
        event.preventDefault()
        undo()
      } else if ((key === 'z' && event.shiftKey) || key === 'y') {
        event.preventDefault()
        redo()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [readOnly, undo, redo])

  const validate = useMutation({
    mutationFn: async () => {
      await flush()
      return api.post(`workflows/${workflow.id}/validate`, { graph: latest.current.graph })
    },
    onSuccess: (response) => {
      const problems = response?.data?.problems ?? []
      setIssues(problems)
      if (problems.length === 0) toast.success(t('workflows.issues.none'))
    },
  })

  const publish = useMutation({
    mutationFn: async () => {
      const saved = await flush()
      if (!saved) throw new Error(t('workflows.save.failedBeforePublish'))
      return api.post(`workflows/${workflow.id}/publish`)
    },
    onSuccess: async () => {
      setDialog(null)
      setIssues(null)
      toast.success(t('workflows.publish.done', { version: draft?.version }))
      await queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
    onError: (error) => {
      const problems = problemsOf(error)
      if (problems) {
        setIssues(problems)
        setDialog(null)
      }
    },
  })

  const restore = useMutation({
    mutationFn: () => api.post(`workflows/${workflow.id}/restore-default`),
    onSuccess: async (response) => {
      setDialog(null)
      const next = response?.data ?? null
      if (next) queryClient.setQueryData(['workflows', workflow.id], { data: next })
      reset(normalizeGraph((next?.draft ?? next?.published)?.graph ?? EMPTY))
      setSelectedId(null)
      setIssues(null)
      await queryClient.invalidateQueries({ queryKey: ['workflows'] })
    },
  })

  const fields = useMemo(() => type?.fields ?? [], [type])
  const context = useMemo(
    () => ({ fields, nextDocuments: type?.next_documents ?? [], types, roles, users, approverTypes, actionHandlers, locale }),
    [fields, type, types, roles, users, approverTypes, actionHandlers, locale],
  )
  const problemNodes = useMemo(() => new Set((issues ?? []).map((problem) => problem.node).filter(Boolean)), [issues])
  const path = useMemo(() => (testResult?.valid ? pathOf(testResult) : null), [testResult])
  const selected = graph.nodes.find((node) => node.id === selectedId) ?? null

  const names = {
    stage: t('workflows.defaults.stage'),
    approval: t('workflows.defaults.approval'),
    condition: t('workflows.defaults.condition'),
    notify: t('workflows.defaults.notify'),
    create_document: t('workflows.defaults.createDocument'),
  }
  const add = (kind, position = nextFreePosition(graph)) => {
    // Computed from the graph as it is now, so the new id is known for selection.
    const { graph: next, id } = addNode(graph, kind, position, names)
    commit(next)
    setSelectedId(id)
  }

  const companyName = workflow.company_name ?? t('workflows.allCompanies')
  const title = `${workflow.document_type_label} · ${companyName}`
  usePageTitle(title)

  const saveStatus = saveState.status === 'saving' || saveState.status === 'error' ? saveState.status : dirty ? 'pending' : saveState.status
  const saveText = {
    idle: null,
    pending: t('workflows.save.pending'),
    saving: t('workflows.save.saving'),
    saved: t('workflows.save.saved'),
    error: t('workflows.save.failed'),
  }[saveStatus]

  return (
    <div className="flex min-w-0 flex-col gap-4">
      <header className="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-border pb-4">
        <nav aria-label={t('workflows.breadcrumb')} className="flex min-w-0 flex-wrap items-center gap-2">
          <Link to="/settings/workflows" className="text-body text-ink-muted hover:text-ink">
            {t('workflows.title')}
          </Link>
          <span aria-hidden="true" className="text-border-strong">
            /
          </span>
          <h1 className="min-w-0 text-h3 text-ink">{title}</h1>
        </nav>
        {draft ? (
          <StatusBadge tone="info">{t('workflows.header.draft', { version: draft.version })}</StatusBadge>
        ) : live ? (
          <StatusBadge tone="success">{t('workflows.header.live', { version: live.version })}</StatusBadge>
        ) : null}
        {draft && live ? (
          <span className="text-caption text-ink-muted">
            {live.in_progress > 0
              ? t('workflows.header.liveWithProgress', { version: live.version, count: live.in_progress })
              : t('workflows.header.liveOnly', { version: live.version })}
          </span>
        ) : null}
        <div className="flex-1" />
        {saveText ? (
          <span aria-live="polite" className="text-caption text-ink-muted" data-save-state={saveStatus}>
            {saveText}
          </span>
        ) : null}
        <div className="flex flex-wrap items-center gap-2">
          {!readOnly ? (
            <>
              <Button variant="ghost" icon="undo" disabled={!history.canUndo} onClick={undo} aria-keyshortcuts="Meta+Z Control+Z">
                {t('workflows.actions.undo')}
              </Button>
              <Button variant="ghost" icon="redo" disabled={!history.canRedo} onClick={redo} aria-keyshortcuts="Meta+Shift+Z Control+Y">
                {t('workflows.actions.redo')}
              </Button>
              <Button variant="ghost" loading={validate.isPending} onClick={() => validate.mutate()}>
                {t('workflows.actions.validate')}
              </Button>
            </>
          ) : null}
          {!phone ? <Button onClick={() => setDialog('test')}>{t('workflows.actions.test')}</Button> : null}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" icon="chevron">
                {t('workflows.actions.more')}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className={menuClasses}>
              <DropdownMenuItem className={menuItemClasses} onSelect={() => setDialog('versions')}>
                {t('workflows.actions.versions')}
              </DropdownMenuItem>
              {!phone ? (
                <DropdownMenuItem className={menuItemClasses} onSelect={() => setDialog('copy')}>
                  {t('workflows.actions.copy')}
                </DropdownMenuItem>
              ) : null}
              {canEdit && !phone ? (
                <DropdownMenuItem className={menuItemClasses} onSelect={() => setDialog('restore')}>
                  {t('workflows.actions.restoreDefault')}
                </DropdownMenuItem>
              ) : null}
            </DropdownMenuContent>
          </DropdownMenu>
          {canPublish && !phone ? (
            <Button variant="pay" disabled={!draft && !edited} onClick={() => setDialog('publish')}>
              {t('workflows.actions.publish', { version: draft?.version ?? (live ? live.version + 1 : 1) })}
            </Button>
          ) : null}
        </div>
      </header>

      {phone ? <Alert tone="info" title={t('workflows.readOnly.phone')} /> : !canEdit ? <Alert tone="info" title={t('workflows.readOnly.noRight')} /> : null}
      {saveState.status === 'error' ? <Alert tone="danger" title={t('workflows.save.failed')}>{errorMessage(saveState.error)}</Alert> : null}
      {restore.isError ? <Alert tone="danger" title={errorMessage(restore.error)} /> : null}
      {validate.isError ? <Alert tone="danger" title={errorMessage(validate.error)} /> : null}

      {issues !== null && issues.length > 0 ? (
        <section aria-label={t('workflows.issues.title')} className="flex flex-col gap-2 rounded-md border border-border bg-surface-200 px-4 py-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-label text-ink">{t('workflows.issues.count', { count: issues.length })}</h2>
            <Button variant="ghost" onClick={() => setIssues(null)}>
              {t('workflows.issues.hide')}
            </Button>
          </div>
          <ul className="flex flex-col gap-1">
            {issues.map((problem, index) => (
              <li key={`${problem.code}-${index}`} className="flex flex-wrap items-center gap-2 text-body text-ink">
                <span className="size-dot shrink-0 rounded-pill bg-danger" aria-hidden="true" />
                {problem.node && graph.nodes.some((node) => node.id === problem.node) ? (
                  <button type="button" className="text-left text-ink underline-offset-2 hover:underline" onClick={() => setSelectedId(problem.node)}>
                    {problem.message}
                  </button>
                ) : (
                  <span>{problem.message}</span>
                )}
              </li>
            ))}
          </ul>
        </section>
      ) : null}

      {testResult ? (
        <div role="status" className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-surface-200 px-4 py-2">
          <span className="text-body text-ink">
            {testResult.valid
              ? testResult.blocked
                ? t('workflows.test.bannerBlocked', { name: testResult.blocked.name })
                : t('workflows.test.bannerEnds', { outcome: t(`workflows.outcomes.${testResult.outcome}`, { defaultValue: testResult.outcome ?? '' }) })
              : t('workflows.test.invalid')}
          </span>
          <span className="flex gap-2">
            <Button variant="ghost" onClick={() => setDialog('test')}>
              {t('workflows.test.details')}
            </Button>
            <Button variant="ghost" onClick={() => setTested(null)}>
              {t('workflows.test.clear')}
            </Button>
          </span>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-col gap-4 lg:flex-row">
        {!readOnly ? (
          <aside aria-label={t('workflows.palette.label')} className="rounded-lg border border-border bg-surface-200 p-4 lg:w-56 lg:shrink-0">
            <Palette onAdd={(kind) => add(kind)} hasStart={graph.nodes.some((node) => node.type === 'start')} />
          </aside>
        ) : null}

        <section aria-label={t('workflows.canvas.label')} className="h-flow min-w-0 flex-1 overflow-hidden rounded-lg border border-border bg-surface-100">
          <FlowCanvas
            label={t('workflows.canvas.label')}
            graph={graph}
            readOnly={readOnly}
            selectedId={selectedId}
            onSelect={setSelectedId}
            commit={commit}
            preview={preview}
            settle={settle}
            problems={problemNodes}
            path={path}
            context={context}
            onDropStep={(kind, position) => add(kind, position)}
          />
        </section>

        {!phone ? (
          <aside aria-label={t('workflows.panel.label')} className="rounded-lg border border-border bg-surface-200 p-5 lg:max-h-flow lg:w-80 lg:shrink-0 lg:overflow-auto">
            <PropertiesPanel
              graph={graph}
              node={selected}
              readOnly={readOnly}
              context={context}
              onChange={(id, changes) => commit((current) => updateNode(current, id, changes))}
              onChangeBranches={(id, changes) => commit((current) => syncBranches(updateNode(current, id, changes), id))}
              onDelete={(id) => {
                commit((current) => removeNodes(current, [id]))
                setSelectedId(null)
              }}
            />
          </aside>
        ) : null}
      </div>

      <PublishDialog
        open={dialog === 'publish'}
        draftVersion={draft?.version ?? (live ? live.version + 1 : 1)}
        live={live}
        pending={publish.isPending}
        error={publish.error && !problemsOf(publish.error) ? errorMessage(publish.error) || publish.error.message : null}
        onConfirm={() => publish.mutate()}
        onClose={() => {
          setDialog(null)
          publish.reset()
        }}
      />
      <VersionsDialog
        open={dialog === 'versions'}
        workflowId={workflow.id}
        canPublish={canPublish && !phone}
        onClose={() => setDialog(null)}
        onRolledBack={async () => {
          toast.success(t('workflows.versions.rolledBack'))
          const fresh = await refetch()
          const next = fresh?.data?.data
          if (next && !next.draft) reset(normalizeGraph(next.published?.graph ?? EMPTY))
        }}
      />
      {dialog === 'copy' ? (
        <CopyDialog
          open
          workflow={workflow}
          companies={companies}
          canCopyToAll={tenantWide('core.workflow.edit')}
          onClose={() => setDialog(null)}
          onCopied={async (copy) => {
            setDialog(null)
            toast.success(t('workflows.copy.done', { company: copy?.company_name ?? t('workflows.allCompanies') }))
            await queryClient.invalidateQueries({ queryKey: ['workflows'] })
            if (copy?.id) navigate(`/settings/workflows/${copy.id}`)
          }}
        />
      ) : null}
      <ConfirmDialog
        open={dialog === 'restore'}
        title={t('workflows.restore.title')}
        confirmLabel={t('workflows.restore.confirm')}
        cancelLabel={t('workflows.restore.keep')}
        pending={restore.isPending}
        error={restore.error ? errorMessage(restore.error) : null}
        failure={restore.error}
        onConfirm={() => restore.mutate()}
        onClose={() => {
          setDialog(null)
          restore.reset()
        }}
      >
        {t('workflows.restore.body')}
      </ConfirmDialog>
      {dialog === 'test' ? (
        <TestDialog
          open
          workflowId={workflow.id}
          graph={graph}
          fields={fields}
          result={testResult}
          onResult={(result) => setTested({ result, version })}
          onClose={() => setDialog(null)}
        />
      ) : null}
    </div>
  )
}

export default function WorkflowBuilder() {
  const { t } = useTranslation()
  const { workflowId } = useParams()
  const query = useQuery({ queryKey: ['workflows', workflowId], queryFn: () => api.get(`workflows/${workflowId}`) })
  const types = useDocumentTypes()
  const workflow = query.data?.data

  if (query.isError) {
    return <Alert tone="danger" title={errorMessage(query.error)} action={<Button onClick={() => query.refetch()}>{t('common.retry')}</Button>} />
  }
  if (!workflow || types.isPending) return <p className="text-body text-ink-muted">{t('common.loading')}</p>

  return (
    <ReactFlowProvider>
      <Builder key={workflow.id} workflow={workflow} type={types.types.find((one) => one.key === workflow.document_type)} refetch={query.refetch} />
    </ReactFlowProvider>
  )
}
