import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { HistoryDialog } from '@/components/HistoryDialog'
import { Alert, Button, DataTable, Dialog, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog } from '@/pages/settings/ConfirmDialog'
import { UOM_KINDS, useUoms } from './catalogueData'

const STATUSES = ['active', 'archived']

function UnitDialog({ record, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState({ code: record?.code ?? '', name: record?.name ?? '', kind: record?.kind ?? 'count' })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  const mutation = useMutation({
    mutationFn: () => {
      const body = { code: values.code.trim().toUpperCase(), name: values.name.trim(), kind: values.kind }
      return record ? api.patch(`uoms/${record.id}`, body) : api.post('uoms', body)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['uoms'] })
      if (record) queryClient.invalidateQueries({ queryKey: ['history', 'uom', record.id] })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['code', 'name', 'kind'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={record ? t('units.editTitle', { code: record.code }) : t('units.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {record ? t('common.save') : t('units.add')}
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
        <TextField label={t('units.fields.code')} help={t('units.fields.codeHelp')} value={values.code} onChange={set('code')} maxLength={10} autoComplete="off" error={errors.fields.code} required />
        <TextField label={t('units.fields.name')} value={values.name} onChange={set('name')} maxLength={100} error={errors.fields.name} required />
        <Select
          label={t('units.fields.kind')}
          options={UOM_KINDS.map((kind) => ({ value: kind, label: t(`units.kinds.${kind}`) }))}
          value={values.kind}
          onChange={set('kind')}
          error={errors.fields.kind}
          required
        />
      </form>
    </Dialog>
  )
}

/** MD-02: the tenant's units of measure, shared by every company; changed only at tenant scope. */
export default function Units() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { tenantWide } = usePermissions()
  const canEdit = tenantWide('core.uom.edit')
  const uoms = useUoms()
  const timeZone = useTimeZone()
  const [status, setStatus] = useState('active')
  const [dialog, setDialog] = useState(null) // { record? }
  const [archiving, setArchiving] = useState(null)
  const [history, setHistory] = useState(null)

  const overrides = { uom_in_use: t('units.errors.inUse') }
  const archive = useMutation({
    mutationFn: (record) => api.post(`uoms/${record.id}/archive`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['uoms'] })
      setArchiving(null)
    },
  })
  const restore = useMutation({
    mutationFn: (record) => api.post(`uoms/${record.id}/restore`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['uoms'] }),
  })

  const rows = uoms.all.filter((uom) => (status === 'archived' ? uom.archived_at : !uom.archived_at)).sort((a, b) => a.code.localeCompare(b.code))
  const columns = [
    { key: 'code', label: t('units.columns.code'), render: (uom) => <span className="font-mono text-caption text-ink">{uom.code}</span> },
    { key: 'name', label: t('units.columns.name'), render: (uom) => <span className="font-medium text-ink">{(uom?.name ?? '')}</span> },
    { key: 'kind', label: t('units.columns.kind'), render: (uom) => t(`units.kinds.${uom.kind}`, { defaultValue: uom.kind }) },
    {
      key: 'status',
      label: t('units.columns.status'),
      render: (uom) => <StatusBadge tone={uom.archived_at ? 'neutral' : 'success'}>{uom.archived_at ? t('units.archived') : t('units.active')}</StatusBadge>,
    },
    {
      key: 'actions',
      label: <span className="sr-only">{t('units.columns.actions')}</span>,
      align: 'end',
      render: (uom) => (
        <span className="flex flex-wrap justify-end gap-1">
          {canEdit && !uom.archived_at ? (
            <Button variant="ghost" icon="edit" onClick={() => setDialog({ record: uom })} aria-label={t('units.editCode', { code: uom.code })}>
              {t('units.edit')}
            </Button>
          ) : null}
          <Button variant="ghost" icon="history" onClick={() => setHistory(uom)} aria-label={t('history.openFor', { name: uom.code })}>
            {t('history.open')}
          </Button>
          {canEdit ? (
            uom.archived_at ? (
              <Button
                variant="ghost"
                icon="restore"
                loading={restore.isPending && restore.variables?.id === uom.id}
                onClick={() => restore.mutate(uom)}
                aria-label={t('units.restoreCode', { code: uom.code })}
              >
                {t('units.restore')}
              </Button>
            ) : (
              <Button variant="ghost" icon="archive" onClick={() => setArchiving(uom)} aria-label={t('units.archiveCode', { code: uom.code })}>
                {t('units.archive')}
              </Button>
            )
          ) : null}
        </span>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title={t('catalogue.units.title')}
        description={t('catalogue.units.description')}
        actions={
          canEdit ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({})}>
              {t('units.add')}
            </Button>
          ) : null
        }
      />
      <Tabs items={STATUSES.map((value) => ({ value, label: t(`units.tabs.${value}`) }))} value={status} onChange={setStatus} />
      {uoms.isError ? <Alert tone="danger" title={errorMessage(uoms.error)} action={<Button onClick={() => uoms.refetch()}>{t('common.retry')}</Button>} /> : null}
      {restore.isError ? <Alert tone="danger" title={errorMessage(restore.error, overrides)} /> : null}
      <DataTable caption={t('catalogue.units.title')} columns={columns} rows={rows} emptyText={uoms.isPending ? t('common.loading') : t(`units.empty.${status}`)} />
      {dialog ? <UnitDialog key={dialog.record?.id ?? 'new'} record={dialog.record ?? null} onClose={() => setDialog(null)} /> : null}
      <HistoryDialog record={history} type="uom" name={history?.code ?? ''} timeZone={timeZone} onClose={() => setHistory(null)} />
      <ConfirmDialog
        open={Boolean(archiving)}
        title={archiving ? t('units.archiveTitle', { code: archiving.code }) : ''}
        confirmLabel={t('units.archiveConfirm')}
        cancelLabel={t('units.keep')}
        pending={archive.isPending}
        error={archive.error ? errorMessage(archive.error, overrides) : null}
        failure={archive.error}
        onConfirm={() => archive.mutate(archiving)}
        onClose={() => {
          setArchiving(null)
          archive.reset()
        }}
      >
        {t('units.archiveText')}
      </ConfirmDialog>
    </>
  )
}
