import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, Select, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { cn } from '@/lib/utils'
import { ConfirmDialog } from './ConfirmDialog'
import { companyScope, useSettingsCompany } from './finance/useSettingsCompany'
import { useScopes } from './users/assignments'

const KINDS = [
  { value: 'department', path: 'departments' },
  { value: 'cost_centre', path: 'cost-centres' },
  { value: 'project', path: 'projects' },
]

const listKey = (companyId, kind) => ['dimensions', companyId, kind]

/** Rows as a tree by `parent_id`, sorted by code; a row whose parent is not listed starts a branch. */
function buildTree(rows) {
  const ids = new Set(rows.map((row) => row.id))
  const children = new Map()
  for (const row of rows) {
    const parent = row.parent_id && ids.has(row.parent_id) ? row.parent_id : null
    if (!children.has(parent)) children.set(parent, [])
    children.get(parent).push(row)
  }
  const walk = (parent, depth) =>
    (children.get(parent) ?? []).sort((a, b) => a.code.localeCompare(b.code)).flatMap((row) => [{ row, depth }, ...walk(row.id, depth + 1)])
  return walk(null, 0)
}

/**
 * APR-02: owners are active users who can view the company. The client
 * keeps users with a role at the tenant, the company, or one of its
 * branches or locations; the API checks again (`owner_no_access`).
 */
function useOwnerOptions(company) {
  const { can } = usePermissions()
  const allowed = can('core.user.view')
  const users = useQuery({ queryKey: ['users', 'owners'], queryFn: () => api.get('users?status=active&per_page=200'), enabled: allowed })
  const scopes = useScopes()
  return useMemo(() => {
    const branchIds = new Set(scopes.branch.filter((branch) => branch.company_id === company.id).map((branch) => branch.id))
    const locationIds = new Set(scopes.location.filter((location) => branchIds.has(location.branch_id)).map((location) => location.id))
    const reaches = (scope) =>
      scope.type === 'tenant' ||
      (scope.type === 'company' && scope.id === company.id) ||
      (scope.type === 'branch' && branchIds.has(scope.id)) ||
      (scope.type === 'location' && locationIds.has(scope.id))
    const all = users.data?.data ?? []
    return {
      allowed,
      all,
      options: all.filter((user) => (user.roles ?? []).some((assignment) => reaches(assignment.scope))),
    }
  }, [users.data, scopes.branch, scopes.location, company.id, allowed])
}

function DimensionDialog({ company, kind, record, rows, owners, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState({
    code: record?.code ?? '',
    name: record?.name ?? '',
    parent_id: record?.parent_id ?? '',
    owner_user_id: record?.owner_user_id ?? '',
  })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const path = KINDS.find((entry) => entry.value === kind).path

  // A row cannot sit under itself or beneath itself.
  const below = useMemo(() => {
    if (!record) return new Set()
    const result = new Set([record.id])
    let grew = true
    while (grew) {
      grew = false
      for (const row of rows) {
        if (row.parent_id && result.has(row.parent_id) && !result.has(row.id)) {
          result.add(row.id)
          grew = true
        }
      }
    }
    return result
  }, [record, rows])
  const parents = rows.filter((row) => !row.archived_at && !below.has(row.id))

  const mutation = useMutation({
    mutationFn: () => {
      const body = {
        code: values.code.trim(),
        name: values.name.trim(),
        parent_id: values.parent_id || null,
        owner_user_id: values.owner_user_id || null,
      }
      return record ? api.patch(`${path}/${record.id}`, body) : api.post(`companies/${company.id}/${path}`, body)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: listKey(company.id, kind) })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['code', 'name', 'parent_id', 'owner_user_id'])
  useErrorFocus(formRef, alertRef, mutation.error)

  const ownerChoices = owners.options.some((user) => user.id === values.owner_user_id) || !values.owner_user_id
    ? owners.options
    : [...owners.options, ...owners.all.filter((user) => user.id === values.owner_user_id)]

  return (
    <Dialog
      open
      title={record ? t('dimensions.editTitle', { name: record.name }) : t(`dimensions.addTitle.${kind}`)}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {record ? t('common.save') : t(`dimensions.add.${kind}`)}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(mutation.error)} />
          </div>
        ) : null}
        <TextField
          label={t('dimensions.fields.code')}
          help={t('dimensions.fields.codeHelp')}
          value={values.code}
          onChange={set('code')}
          maxLength={30}
          error={errors.fields.code}
          required
        />
        <TextField label={t('dimensions.fields.name')} value={values.name} onChange={set('name')} error={errors.fields.name} required />
        <Select
          label={t('dimensions.fields.parent')}
          options={[{ value: '', label: t('dimensions.fields.noParent') }, ...parents.map((row) => ({ value: row.id, label: `${row.code} · ${row.name}` }))]}
          value={values.parent_id}
          onChange={set('parent_id')}
          error={errors.fields.parent_id}
        />
        {owners.allowed ? (
          <Select
            label={t('dimensions.fields.owner')}
            help={t('dimensions.fields.ownerHelp', { name: company.name })}
            options={[{ value: '', label: t('dimensions.fields.noOwner') }, ...ownerChoices.map((user) => ({ value: user.id, label: user.name }))]}
            value={values.owner_user_id}
            onChange={set('owner_user_id')}
            error={errors.fields.owner_user_id}
          />
        ) : null}
      </form>
    </Dialog>
  )
}

function DimensionList({ company, kind }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const scope = companyScope(company)
  const canCreate = can('core.dimension.create', scope)
  const canEdit = can('core.dimension.edit', scope)
  const canArchive = can('core.dimension.archive', scope)
  const path = KINDS.find((entry) => entry.value === kind).path
  const owners = useOwnerOptions(company)
  const [showArchived, setShowArchived] = useState(false)
  const [dialog, setDialog] = useState(null) // { record? }
  const [archiving, setArchiving] = useState(null)

  const list = useQuery({ queryKey: listKey(company.id, kind), queryFn: () => api.get(`companies/${company.id}/${path}?status=all&per_page=200`) })
  const rows = list.data?.data ?? []
  const visible = showArchived ? rows : rows.filter((row) => !row.archived_at)
  const tree = buildTree(visible)
  const ownerName = (id) => owners.all.find((user) => user.id === id)?.name

  const overrides = { dimension_in_use: t('dimensions.errors.inUse'), parent_archived: t('dimensions.errors.parentArchived') }
  const archive = useMutation({
    mutationFn: (record) => api.post(`${path}/${record.id}/archive`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: listKey(company.id, kind) })
      setArchiving(null)
    },
  })
  const restore = useMutation({
    mutationFn: (record) => api.post(`${path}/${record.id}/restore`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey(company.id, kind) }),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-ink-muted">{t(`dimensions.intro.${kind}`)}</p>
        {canCreate ? (
          <Button variant="primary" icon="plus" onClick={() => setDialog({})}>
            {t(`dimensions.add.${kind}`)}
          </Button>
        ) : null}
      </div>
      {rows.some((row) => row.archived_at) ? <Switch label={t('dimensions.showArchived')} checked={showArchived} onChange={setShowArchived} /> : null}
      {list.isError ? <Alert tone="danger" title={errorMessage(list.error)} action={<Button onClick={() => list.refetch()}>{t('common.retry')}</Button>} /> : null}
      {restore.isError ? <Alert tone="danger" title={errorMessage(restore.error, overrides)} /> : null}
      <Card>
        {list.isPending ? (
          <p className="text-ink-muted">{t('common.loading')}</p>
        ) : tree.length === 0 ? (
          <p className="text-ink-muted">{t(`dimensions.empty.${kind}`)}</p>
        ) : (
          <ul aria-label={t(`dimensions.tabs.${kind}`)} className="-my-3 divide-y divide-border">
            {tree.map(({ row, depth }) => {
              const archived = Boolean(row.archived_at)
              const owner = row.owner_user_id ? ownerName(row.owner_user_id) : null
              return (
                <li key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                  <div className={cn('flex min-w-0 flex-1 flex-col', depth > 0 && 'border-l border-border pl-4', depth === 1 && 'ml-4', depth === 2 && 'ml-10', depth > 2 && 'ml-12')}>
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                      <span className="font-mono text-caption text-ink-muted">{row.code}</span>
                      <span className="font-medium text-ink">{row.name}</span>
                      {archived ? <StatusBadge tone="neutral">{t('dimensions.archived')}</StatusBadge> : null}
                    </span>
                    <span className="text-caption text-ink-muted">
                      {row.owner_user_id ? t('dimensions.owner', { name: owner ?? t('dimensions.ownerHidden') }) : t('dimensions.noOwner')}
                    </span>
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {canEdit && !archived ? (
                      <Button variant="ghost" icon="edit" onClick={() => setDialog({ record: row })} aria-label={t('dimensions.editName', { name: row.name })}>
                        {t('dimensions.edit')}
                      </Button>
                    ) : null}
                    {canArchive ? (
                      archived ? (
                        <Button
                          variant="ghost"
                          icon="restore"
                          loading={restore.isPending && restore.variables?.id === row.id}
                          onClick={() => restore.mutate(row)}
                          aria-label={t('dimensions.restoreName', { name: row.name })}
                        >
                          {t('dimensions.restore')}
                        </Button>
                      ) : (
                        <Button variant="ghost" icon="archive" onClick={() => setArchiving(row)} aria-label={t('dimensions.archiveName', { name: row.name })}>
                          {t('dimensions.archive')}
                        </Button>
                      )
                    ) : null}
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </Card>
      {dialog ? (
        <DimensionDialog
          key={dialog.record?.id ?? 'new'}
          company={company}
          kind={kind}
          record={dialog.record ?? null}
          rows={rows}
          owners={owners}
          onClose={() => setDialog(null)}
        />
      ) : null}
      <ConfirmDialog
        open={Boolean(archiving)}
        title={archiving ? t('dimensions.archiveTitle', { name: archiving.name }) : ''}
        confirmLabel={t(`dimensions.archiveConfirm.${kind}`)}
        cancelLabel={t('dimensions.keep')}
        pending={archive.isPending}
        error={archive.error ? errorMessage(archive.error, overrides) : null}
        failure={archive.error}
        onConfirm={() => archive.mutate(archiving)}
        onClose={() => {
          setArchiving(null)
          archive.reset()
        }}
      >
        {t('dimensions.archiveText')}
      </ConfirmDialog>
    </div>
  )
}

/** MD-05: a company's departments, cost centres and projects, each a tree with an owner (APR-02). */
export default function Dimensions() {
  const { t } = useTranslation()
  const { company, picker, ready } = useSettingsCompany()
  const [kind, setKind] = useState('department')

  return (
    <>
      <PageHeader title={t('settings.dimensions.title')} description={t('settings.dimensions.description')} />
      {picker}
      {!ready ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {ready && !company ? <Alert tone="info" title={t('finance.company.none')} /> : null}
      {company ? (
        <div className="flex flex-col gap-5">
          <Tabs items={KINDS.map((entry) => ({ value: entry.value, label: t(`dimensions.tabs.${entry.value}`) }))} value={kind} onChange={setKind} />
          <DimensionList key={`${company.id}:${kind}`} company={company} kind={kind} />
        </div>
      ) : null}
    </>
  )
}
