import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { HistoryDialog } from '@/components/HistoryDialog'
import { Alert, Button, Card, Dialog, ExportMenu, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { perCompany, useSharingModes } from '@/lib/masterData'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useListExport } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { cn } from '@/lib/utils'
import { ConfirmDialog } from '@/pages/settings/ConfirmDialog'
import { categoryTree, useItemCategories } from './catalogueData'

const INDENT = ['', 'ml-4', 'ml-10', 'ml-12', 'ml-12', 'ml-12']

/** A category's id and every id beneath it: none of them can be its parent. */
function selfAndBelow(record, rows) {
  const result = new Set(record ? [record.id] : [])
  let grew = Boolean(record)
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
}

function CategoryDialog({ record, parent, rows, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const { modes } = useSharingModes()
  const { companies } = useCompanies()
  const { company: selected } = useCompanySelection()
  const activeCompanies = companies.filter((company) => !company.archived_at)
  const creating = !record
  const keptPerCompany = creating && perCompany(modes, 'items')
  const [values, setValues] = useState({
    company_id: parent?.company_id ?? selected?.id ?? '',
    name: record?.name ?? '',
    parent_id: record?.parent_id ?? parent?.id ?? '',
  })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const chosenCompany = values.company_id || (activeCompanies.length === 1 ? activeCompanies[0].id : '')
  const scope = creating ? (keptPerCompany ? chosenCompany || null : null) : (record.company_id ?? null)

  const below = useMemo(() => selfAndBelow(record, rows), [record, rows])
  const parents = categoryTree(rows.filter((row) => !row.archived_at && !below.has(row.id) && (row.company_id ?? null) === scope))
  const archivedParent = record?.parent_id ? rows.find((row) => row.id === record.parent_id && row.archived_at) : null

  const mutation = useMutation({
    mutationFn: () => {
      const body = { name: values.name.trim(), parent_id: values.parent_id || null }
      if (creating) return api.post('item-categories', keptPerCompany ? { ...body, company_id: chosenCompany || null } : body)
      // An unchanged parent is not sent: an archived one would be refused.
      if (body.parent_id === (record.parent_id ?? null)) delete body.parent_id
      return api.patch(`item-categories/${record.id}`, body)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['item-categories'] })
      if (record) queryClient.invalidateQueries({ queryKey: ['history', 'item_category', record.id] })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['company_id', 'name', 'parent_id'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={record ? t('categories.editTitle', { name: (record?.name ?? '') }) : t('categories.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {record ? t('common.save') : t('categories.add')}
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
        {keptPerCompany ? (
          <Select
            label={t('categories.fields.company')}
            help={t('categories.fields.companyHelp')}
            options={activeCompanies.map((company) => ({ value: company.id, label: company.name }))}
            placeholder={t('items.form.chooseCompany')}
            value={chosenCompany}
            onChange={(event) => setValues((current) => ({ ...current, company_id: event.target.value, parent_id: '' }))}
            error={errors.fields.company_id}
            required
          />
        ) : null}
        <TextField label={t('categories.fields.name')} value={values.name} onChange={set('name')} maxLength={100} error={errors.fields.name} required />
        <Select
          label={t('categories.fields.parent')}
          options={[
            { value: '', label: t('categories.fields.noParent') },
            ...(archivedParent ? [{ value: archivedParent.id, label: t('dimensions.fields.archivedParent', { name: (archivedParent?.name ?? '') }), disabled: true }] : []),
            ...parents.map(({ row, depth }) => ({ value: row.id, label: `${'— '.repeat(depth)}${(row?.name ?? '')}` })),
          ]}
          help={archivedParent && values.parent_id === archivedParent.id ? t('dimensions.fields.archivedParentHelp') : undefined}
          value={values.parent_id}
          onChange={set('parent_id')}
          error={errors.fields.parent_id}
        />
      </form>
    </Dialog>
  )
}

/**
 * MD-02: item categories as a tree, shared or per company with items
 * (TEN-08); archived, never deleted. The tree stays a tree (paging or
 * sorting would break parents from their children), so it has the Export
 * menu (EXP-01) but no ListView.
 */
export default function Categories() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can, canWithin } = usePermissions()
  const categories = useItemCategories()
  const { companies } = useCompanies()
  const [showArchived, setShowArchived] = useState(false)
  const exporter = useListExport({ id: 'item-categories', endpoint: 'item-categories' })
  const [dialog, setDialog] = useState(null) // { record?, parent? }
  const [archiving, setArchiving] = useState(null)
  const [history, setHistory] = useState(null)
  const timeZone = useTimeZone(history?.company_id)

  const rows = categories.all
  const tree = categoryTree(showArchived ? rows : rows.filter((row) => !row.archived_at))
  const allowed = (name, row) => (row.company_id ? canWithin(name, [{ type: 'company', id: row.company_id }]) : can(name))
  const canCreate = can('core.item_category.create')
  const companyName = (id) => companies.find((company) => company.id === id)?.name

  const overrides = { category_in_use: t('categories.errors.inUse'), parent_archived: t('categories.errors.parentArchived') }
  const archive = useMutation({
    mutationFn: (record) => api.post(`item-categories/${record.id}/archive`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['item-categories'] })
      setArchiving(null)
    },
  })
  const restore = useMutation({
    mutationFn: (record) => api.post(`item-categories/${record.id}/restore`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['item-categories'] }),
  })

  return (
    <>
      <PageHeader
        title={t('catalogue.categories.title')}
        description={t('catalogue.categories.description')}
        actions={
          <>
            <ExportMenu onExport={(format) => exporter.exportTo(format, { status: showArchived ? 'all' : 'active' })} exporting={exporter.exporting} />
            {canCreate ? (
              <Button variant="primary" icon="plus" onClick={() => setDialog({})}>
                {t('categories.add')}
              </Button>
            ) : null}
          </>
        }
      />
      {rows.some((row) => row.archived_at) ? <Switch label={t('categories.showArchived')} checked={showArchived} onChange={setShowArchived} /> : null}
      {categories.isError ? <Alert tone="danger" title={errorMessage(categories.error)} action={<Button onClick={() => categories.refetch()}>{t('common.retry')}</Button>} /> : null}
      {restore.isError ? <Alert tone="danger" title={errorMessage(restore.error, overrides)} /> : null}
      <Card>
        {categories.isPending ? (
          <p className="text-ink-muted">{t('common.loading')}</p>
        ) : tree.length === 0 ? (
          <p className="text-ink-muted">{t('categories.empty')}</p>
        ) : (
          <ul aria-label={t('catalogue.categories.title')} className="-my-3 divide-y divide-border">
            {tree.map(({ row, depth }) => {
              const archived = Boolean(row.archived_at)
              const name = (row?.name ?? '')
              const company = row.company_id ? companyName(row.company_id) : null
              return (
                <li key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                  <div className={cn('flex min-w-0 flex-1 flex-col', depth > 0 && 'border-l border-border pl-4', INDENT[Math.min(depth, INDENT.length - 1)])}>
                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                      <span className="font-medium text-ink">{name}</span>
                      {archived ? <StatusBadge tone="neutral">{t('categories.archived')}</StatusBadge> : null}
                    </span>
                    <span className="text-caption text-ink-muted">{row.company_id ? (company ?? t('categories.oneCompany')) : t('categories.shared')}</span>
                  </div>
                  <div className="flex flex-wrap gap-1">
                    {canCreate && !archived ? (
                      <Button variant="ghost" icon="plus" onClick={() => setDialog({ parent: row })} aria-label={t('categories.addUnder', { name })}>
                        {t('categories.addChild')}
                      </Button>
                    ) : null}
                    {allowed('core.item_category.edit', row) && !archived ? (
                      <Button variant="ghost" icon="edit" onClick={() => setDialog({ record: row })} aria-label={t('categories.editName', { name })}>
                        {t('categories.edit')}
                      </Button>
                    ) : null}
                    <Button variant="ghost" icon="history" onClick={() => setHistory(row)} aria-label={t('history.openFor', { name })}>
                      {t('history.open')}
                    </Button>
                    {allowed('core.item_category.archive', row) ? (
                      archived ? (
                        <Button
                          variant="ghost"
                          icon="restore"
                          loading={restore.isPending && restore.variables?.id === row.id}
                          onClick={() => restore.mutate(row)}
                          aria-label={t('categories.restoreName', { name })}
                        >
                          {t('categories.restore')}
                        </Button>
                      ) : (
                        <Button variant="ghost" icon="archive" onClick={() => setArchiving(row)} aria-label={t('categories.archiveName', { name })}>
                          {t('categories.archive')}
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
        <CategoryDialog key={dialog.record?.id ?? dialog.parent?.id ?? 'new'} record={dialog.record ?? null} parent={dialog.parent ?? null} rows={rows} onClose={() => setDialog(null)} />
      ) : null}
      <HistoryDialog record={history} type="item_category" name={history ? (history?.name ?? '') : ''} timeZone={timeZone} onClose={() => setHistory(null)} />
      <ConfirmDialog
        open={Boolean(archiving)}
        title={archiving ? t('categories.archiveTitle', { name: (archiving?.name ?? '') }) : ''}
        confirmLabel={t('categories.archiveConfirm')}
        cancelLabel={t('categories.keep')}
        pending={archive.isPending}
        error={archive.error ? errorMessage(archive.error, overrides) : null}
        failure={archive.error}
        onConfirm={() => archive.mutate(archiving)}
        onClose={() => {
          setArchiving(null)
          archive.reset()
        }}
      >
        {t('categories.archiveText')}
      </ConfirmDialog>
    </>
  )
}
