import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Icon, StatusBadge, Switch } from '@/components/ds'
import { HistoryDialog } from '@/components/HistoryDialog'
import { PageHeader } from '@/layouts/PageHeader'
import { useTimeZone } from '@/lib/useTimeZone'
import { cn } from '@/lib/utils'
import { ConfirmDialog } from './ConfirmDialog'
import { Devices } from './organisation/Devices'
import { orgErrorMessage } from './organisation/orgErrors'
import { buildTree, hasArchived, invalidateOrganisation, isArchived, withoutArchived } from './organisation/orgTree'
import { RecordDialog } from './organisation/RecordDialog'

const PATHS = { company: 'companies', branch: 'branches', location: 'locations' }
const LIST = (resource) => `${resource}?status=all&per_page=200`

/** One record of the tree: name, code or type, status, and the actions the user may take. */
function NodeRow({ icon, name, meta, archived, actions, className, heading: Name = 'span' }) {
  const { t } = useTranslation()
  return (
    <div className={cn('flex flex-wrap items-center gap-x-4 gap-y-2 py-3', className)}>
      <div className="flex min-w-0 flex-1 items-start gap-2">
        <Icon name={icon} className="mt-px text-ink-muted" />
        <div className="flex min-w-0 flex-col">
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <Name className="font-medium text-ink">{name}</Name>
            <StatusBadge tone={archived ? 'neutral' : 'success'}>
              {archived ? t('organisation.status.archived') : t('organisation.status.active')}
            </StatusBadge>
          </span>
          {meta ? <span className="text-caption text-ink-muted">{meta}</span> : null}
        </div>
      </div>
      {actions.length ? <div className="flex flex-wrap gap-1">{actions}</div> : null}
    </div>
  )
}

/**
 * TEN-02..TEN-06: the organisation as a tree of companies, branches and
 * locations, with devices per location. Lists come from the API already
 * filtered to the user's scope (RBAC-04); actions show only where the user
 * holds the permission at the record or above it.
 */
export default function Organisation() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { canWithin, tenantWide } = usePermissions()
  const [showArchived, setShowArchived] = useState(false)
  const [dialog, setDialog] = useState(null) // { kind: 'create' | 'edit', level, record?, parent? }
  const [archiving, setArchiving] = useState(null) // { level, record }
  const [openDevices, setOpenDevices] = useState(() => new Set())
  const [history, setHistory] = useState(null) // { level, record }

  const historyZone = useTimeZone()
  const companies = useQuery({ queryKey: ['companies', 'organisation'], queryFn: () => api.get(LIST('companies')) })
  const branches = useQuery({ queryKey: ['branches', 'organisation'], queryFn: () => api.get(LIST('branches')) })
  const locations = useQuery({ queryKey: ['locations', 'organisation'], queryFn: () => api.get(LIST('locations')) })
  const queries = [companies, branches, locations]
  const loading = queries.some((query) => query.isPending)
  const failed = queries.find((query) => query.isError)

  const { tree, anyArchived } = useMemo(() => {
    const lists = [companies.data?.data ?? [], branches.data?.data ?? [], locations.data?.data ?? []]
    const full = buildTree(...lists)
    return { tree: showArchived ? full : withoutArchived(full), anyArchived: hasArchived(...lists) }
  }, [companies.data, branches.data, locations.data, showArchived])

  const archive = useMutation({
    mutationFn: ({ level, record }) => api.post(`${PATHS[level]}/${record.id}/archive`),
    onSuccess: async () => {
      await invalidateOrganisation(queryClient)
      setArchiving(null)
    },
  })
  const restore = useMutation({
    mutationFn: ({ level, record }) => api.post(`${PATHS[level]}/${record.id}/restore`),
    onSuccess: () => invalidateOrganisation(queryClient),
  })

  const canAddCompany = tenantWide('core.company.create')
  const addCompany = () => setDialog({ kind: 'create', level: 'company' })

  const toggleDevices = (id) =>
    setOpenDevices((current) => {
      const next = new Set(current)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  /** Edit, archive or restore, when permitted at this record or above; its history (MD-07) for anyone who sees it. */
  const recordActions = (level, record, chain) => {
    const actions = []
    if (!record.visible) return actions
    const archived = isArchived(record)
    actions.push(
      <Button key="history" variant="ghost" icon="history" onClick={() => setHistory({ level, record })} aria-label={t('history.openFor', { name: record.name })}>
        {t('history.open')}
      </Button>,
    )
    if (!archived && canWithin(`core.${level}.edit`, chain)) {
      actions.push(
        <Button key="edit" variant="ghost" icon="edit" onClick={() => setDialog({ kind: 'edit', level, record })} aria-label={t('organisation.editName', { name: record.name })}>
          {t('organisation.edit')}
        </Button>,
      )
    }
    if (canWithin(`core.${level}.archive`, chain)) {
      actions.push(
        archived ? (
          <Button
            key="restore"
            variant="ghost"
            icon="restore"
            loading={restore.isPending && restore.variables?.record.id === record.id}
            onClick={() => restore.mutate({ level, record })}
            aria-label={t('organisation.restoreName', { name: record.name })}
          >
            {t('organisation.restore')}
          </Button>
        ) : (
          <Button key="archive" variant="ghost" icon="archive" onClick={() => setArchiving({ level, record })} aria-label={t('organisation.archiveName', { name: record.name })}>
            {t('organisation.archive')}
          </Button>
        ),
      )
    }
    return actions
  }

  const renderLocation = (location, branch, company) => {
    const chain = [
      { type: 'company', id: company?.id },
      { type: 'branch', id: branch.id },
      { type: 'location', id: location.id },
    ]
    const archived = isArchived(location)
    const open = openDevices.has(location.id)
    const actions = recordActions('location', { ...location, visible: true }, chain)
    if (canWithin('core.device.view', chain)) {
      actions.unshift(
        <Button
          key="devices"
          variant="ghost"
          icon="device"
          aria-expanded={open}
          onClick={() => toggleDevices(location.id)}
          aria-label={t('organisation.devicesOf', { name: location.name })}
        >
          {t('organisation.devices')}
        </Button>,
      )
    }
    return (
      <li key={location.id}>
        <NodeRow icon="organisation" name={location.name} meta={t(`organisation.locationTypes.${location.type}`)} archived={archived} actions={actions} />
        {open ? (
          <div className="pb-3">
            <Devices location={location} chain={chain} archived={archived} />
          </div>
        ) : null}
      </li>
    )
  }

  const renderBranch = (branch, company) => {
    const chain = [
      { type: 'company', id: company?.id },
      { type: 'branch', id: branch.id },
    ]
    const archived = isArchived(branch)
    const canAddLocation = branch.visible && !archived && canWithin('core.location.create', chain)
    const actions = recordActions('branch', branch, chain)
    if (canAddLocation) {
      actions.unshift(
        <Button
          key="add"
          variant="ghost"
          icon="plus"
          onClick={() => setDialog({ kind: 'create', level: 'location', parent: branch })}
          aria-label={t('organisation.location.addIn', { parent: branch.name })}
        >
          {t('organisation.location.add')}
        </Button>,
      )
    }
    return (
      <li key={branch.id}>
        {branch.visible ? (
          <NodeRow icon="organisation" name={branch.name} meta={t('organisation.branch.code', { code: branch.code })} archived={archived} actions={actions} />
        ) : (
          <p className="py-3 font-medium text-ink">{branch.name}</p>
        )}
        {branch.locations.length ? (
          <ul aria-label={t('organisation.location.listOf', { name: branch.name })} className="ml-2 divide-y divide-border border-l border-border pl-4">
            {branch.locations.map((location) => renderLocation(location, branch, company))}
          </ul>
        ) : branch.visible ? (
          <p className="ml-2 border-l border-border pb-3 pl-4 text-ink-muted">{t('organisation.location.empty')}</p>
        ) : null}
      </li>
    )
  }

  const renderCompany = (company) => {
    const chain = [{ type: 'company', id: company.id }]
    const archived = isArchived(company)
    const canAddBranch = company.visible && !archived && canWithin('core.branch.create', chain)
    const meta = company.visible ? [t(`auth.countries.${company.country}`, { defaultValue: company.country }), company.base_currency].filter(Boolean).join(' · ') : null
    return (
      <Card key={company.id}>
        {company.visible ? (
          <NodeRow
            icon="organisation"
            name={<span className="text-h3">{company.name}</span>}
            heading="h2"
            meta={meta}
            archived={archived}
            actions={recordActions('company', company, chain)}
            className="pt-0"
          />
        ) : (
          <h2 className="pb-3 text-h3 text-ink">{company.name}</h2>
        )}
        {company.branches.length ? (
          <ul aria-label={t('organisation.branch.listOf', { name: company.name })} className="divide-y divide-border border-t border-border">
            {company.branches.map((branch) => renderBranch(branch, company))}
          </ul>
        ) : company.visible ? (
          <p className="border-t border-border pt-3 text-ink-muted">{t('organisation.branch.empty')}</p>
        ) : null}
        {canAddBranch ? (
          <div className="border-t border-border pt-3">
            <Button icon="plus" onClick={() => setDialog({ kind: 'create', level: 'branch', parent: company })} aria-label={t('organisation.branch.addIn', { parent: company.name })}>
              {t('organisation.branch.add')}
            </Button>
          </div>
        ) : null}
      </Card>
    )
  }

  const empty = !loading && !failed && tree.companies.length === 0 && tree.orphanBranches.length === 0

  return (
    <>
      <PageHeader
        title={t('settings.organisation.title')}
        description={t('settings.organisation.description')}
        actions={
          canAddCompany ? (
            <Button variant="primary" icon="plus" onClick={addCompany}>
              {t('organisation.company.add')}
            </Button>
          ) : null
        }
      />
      {anyArchived ? <Switch label={t('organisation.showArchived')} checked={showArchived} onChange={setShowArchived} /> : null}
      {failed ? (
        <Alert tone="danger" title={errorMessage(failed.error)} action={<Button onClick={() => queries.forEach((query) => query.refetch())}>{t('common.retry')}</Button>} />
      ) : null}
      {restore.isError ? <Alert tone="danger" title={orgErrorMessage(restore.error, restore.variables?.level)} /> : null}
      {loading ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {empty ? (
        <Card>
          <div className="flex flex-col items-start gap-3 py-2">
            <p className="text-ink-muted">{canAddCompany ? t('organisation.empty') : t('organisation.emptyReadOnly')}</p>
            {canAddCompany ? (
              <Button variant="primary" icon="plus" onClick={addCompany}>
                {t('organisation.company.add')}
              </Button>
            ) : null}
          </div>
        </Card>
      ) : null}
      <div className="flex flex-col gap-5">
        {tree.companies.map(renderCompany)}
        {tree.orphanBranches.length ? (
          <Card>
            <ul className="divide-y divide-border">{tree.orphanBranches.map((branch) => renderBranch(branch, null))}</ul>
          </Card>
        ) : null}
      </div>

      {dialog ? (
        <RecordDialog
          key={`${dialog.kind}-${dialog.level}-${dialog.record?.id ?? dialog.parent?.id ?? 'new'}`}
          level={dialog.level}
          record={dialog.kind === 'edit' ? dialog.record : null}
          parent={dialog.parent}
          onClose={() => setDialog(null)}
        />
      ) : null}
      <HistoryDialog
        record={history?.record ?? null}
        type={history?.level}
        name={history?.record.name ?? ''}
        timeZone={history?.record.timezone ?? historyZone}
        onClose={() => setHistory(null)}
      />
      <ConfirmDialog
        open={Boolean(archiving)}
        title={archiving ? t('organisation.archiveTitle', { name: archiving.record.name }) : ''}
        confirmLabel={archiving ? t(`organisation.${archiving.level}.archive`) : ''}
        cancelLabel={t('organisation.keep')}
        pending={archive.isPending}
        error={archiving ? orgErrorMessage(archive.error, archiving.level) : null}
        failure={archive.error}
        onConfirm={() => archive.mutate(archiving)}
        onClose={() => {
          setArchiving(null)
          archive.reset()
        }}
      >
        {archiving ? t(`organisation.${archiving.level}.archiveText`) : null}
      </ConfirmDialog>
    </>
  )
}
