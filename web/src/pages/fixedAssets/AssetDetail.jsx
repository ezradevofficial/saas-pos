import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { Amount, Back, CalendarDate, Detail, DetailState, FaStatus, PrintFrame, ReasonDialog } from './FixedAssetsParts'
import { DISPOSAL_KINDS, faKey, locationLabel, METHODS, useLocations } from './fixedAssetsData'
import { FormDialog } from './FormDialog'

/** Money digits as the API sends them in a schedule row, with the asset's currency. */
const money = (minor, currency) => (minor === null || minor === undefined ? null : { amount_minor: minor, currency })

/** FA-26: posted charges and the projection to the residual value. */
function SchedulePanel({ asset }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...faKey, 'asset', asset.id, 'schedule'], queryFn: () => api.get(`fixed-assets/assets/${asset.id}/schedule`) })
  const data = query.data?.data
  if (!data) return <p className="text-ink-muted">{t('common.loading')}</p>
  const rows = [...data.posted.map((row, index) => ({ ...row, id: `p-${index}`, kind: 'posted' })), ...data.projected.map((row, index) => ({ ...row, id: `f-${index}`, kind: 'projected' }))]
  const columns = [
    { key: 'kind', label: t('fixedAssets.schedule.kind'), render: (row) => (row.kind === 'posted' ? t('fixedAssets.schedule.posted') : t('fixedAssets.schedule.projected')) },
    { key: 'from', label: t('fixedAssets.schedule.from'), render: (row) => <CalendarDate value={row.from} /> },
    { key: 'to', label: t('fixedAssets.schedule.to'), render: (row) => <CalendarDate value={row.to} /> },
    { key: 'amount', label: t('fixedAssets.schedule.charge'), align: 'end', render: (row) => <Amount value={money(row.amount, data.currency)} /> },
    { key: 'accumulated', label: t('fixedAssets.columns.accumulated'), align: 'end', render: (row) => <Amount value={money(row.accumulated, data.currency)} /> },
    { key: 'book_value', label: t('fixedAssets.columns.bookValue'), align: 'end', render: (row) => <Amount value={money(row.book_value, data.currency)} /> },
  ]
  return (
    <div className="flex flex-col gap-3">
      {!data.projectable ? <p className="text-caption text-ink-muted">{t('fixedAssets.schedule.notProjectable')}</p> : null}
      <DataTable caption={t('fixedAssets.tabs.schedule')} columns={columns} rows={rows} emptyText={t('fixedAssets.schedule.empty')} />
    </div>
  )
}

/** FA-26: what happened to the asset, newest first. */
function HistoryPanel({ asset }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...faKey, 'asset', asset.id, 'history'], queryFn: () => api.get(`fixed-assets/assets/${asset.id}/history`) })
  const columns = [
    { key: 'happened_on', label: t('fixedAssets.history.date'), render: (row) => <CalendarDate value={row.happened_on} /> },
    { key: 'kind', label: t('fixedAssets.history.what'), render: (row) => t(`fixedAssets.history.kinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'note', label: t('fixedAssets.history.note'), wrap: true, render: (row) => row.note ?? '' },
    { key: 'user', label: t('fixedAssets.history.by'), render: (row) => row.user?.name ?? '' },
  ]
  return <DataTable caption={t('fixedAssets.tabs.history')} columns={columns} rows={query.data?.data ?? []} loading={query.isPending} emptyText={t('fixedAssets.history.empty')} />
}

/** FA-06: the asset's files: upload, download, archive. */
function DocumentsPanel({ asset }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const key = [...faKey, 'asset', asset.id, 'documents']
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`fixed-assets/assets/${asset.id}/documents`) })
  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('file', file)
      return api.upload(`fixed-assets/assets/${asset.id}/documents`, form)
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: key }),
  })
  const archive = useMutation({ mutationFn: (id) => api.post(`fixed-assets/documents/${id}/archive`), onSuccess: () => queryClient.invalidateQueries({ queryKey: key }) })
  const download = async (document) => {
    const { blob } = await api.download(`fixed-assets/documents/${document.id}/download`)
    saveFile(blob, document.name)
  }
  const columns = [
    { key: 'name', label: t('fixedAssets.documents.name'), wrap: true, render: (row) => row.name },
    { key: 'uploaded_by', label: t('fixedAssets.documents.by'), render: (row) => row.uploaded_by?.name ?? '' },
    {
      key: 'actions',
      label: t('fixedAssets.documents.actions'),
      align: 'end',
      render: (row) => (
        <span className="flex justify-end gap-2">
          <Button variant="ghost" icon="download" onClick={() => download(row)}>
            {t('fixedAssets.documents.download')}
          </Button>
          {asset.can?.edit ? (
            <Button variant="ghost" icon="archive" onClick={() => archive.mutate(row.id)}>
              {t('fixedAssets.documents.archive')}
            </Button>
          ) : null}
        </span>
      ),
    },
  ]
  return (
    <div className="flex flex-col gap-3">
      {upload.isError ? <Alert tone="danger" title={errorMessage(upload.error)} /> : null}
      {asset.can?.edit ? (
        <label className="w-fit text-label text-primary hover:text-primary-hover">
          {t('fixedAssets.documents.upload')}
          <input type="file" className="sr-only" onChange={(event) => event.target.files?.[0] && upload.mutate(event.target.files[0])} />
        </label>
      ) : null}
      <DataTable caption={t('fixedAssets.tabs.documents')} columns={columns} rows={query.data?.data ?? []} loading={query.isPending} emptyText={t('fixedAssets.documents.empty')} />
    </div>
  )
}

/** FA-18, FA-19: meter readings, maintenance schedules and work orders of the asset. */
function MaintenancePanel({ asset, onChanged }) {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null)
  const readings = useQuery({ queryKey: [...faKey, 'asset', asset.id, 'readings'], queryFn: () => api.get(`fixed-assets/assets/${asset.id}/readings`) })
  const schedules = useQuery({ queryKey: [...faKey, 'asset', asset.id, 'schedules'], queryFn: () => api.get(`fixed-assets/schedules?asset=${asset.id}`), retry: false })
  const orders = useQuery({ queryKey: [...faKey, 'asset', asset.id, 'orders'], queryFn: () => api.get(`fixed-assets/work-orders?asset=${asset.id}`), retry: false })
  const done = () => {
    setDialog(null)
    onChanged()
  }
  return (
    <div className="flex flex-col gap-5">
      <div className="flex flex-wrap gap-2">
        {asset.can?.edit ? <Button onClick={() => setDialog('reading')}>{t('fixedAssets.readings.add')}</Button> : null}
        {asset.can?.maintain ? <Button onClick={() => setDialog('schedule')}>{t('fixedAssets.maintenance.addSchedule')}</Button> : null}
        {asset.can?.maintain ? <Button onClick={() => setDialog('order')}>{t('fixedAssets.maintenance.addOrder')}</Button> : null}
      </div>
      <DataTable
        caption={t('fixedAssets.readings.title')}
        columns={[
          { key: 'read_on', label: t('fixedAssets.readings.date'), render: (row) => <CalendarDate value={row.read_on} /> },
          { key: 'reading', label: t('fixedAssets.readings.reading'), align: 'end', render: (row) => <span className="tabular-nums">{row.reading}</span> },
          { key: 'note', label: t('fixedAssets.readings.note'), render: (row) => row.note ?? '' },
        ]}
        rows={readings.data?.data ?? []}
        emptyText={t('fixedAssets.readings.empty')}
      />
      <DataTable
        caption={t('fixedAssets.maintenance.schedules')}
        columns={[
          { key: 'name', label: t('fixedAssets.maintenance.name'), render: (row) => row.name },
          { key: 'basis', label: t('fixedAssets.maintenance.basis'), render: (row) => t(`fixedAssets.maintenance.bases.${row.basis}`) },
          { key: 'next', label: t('fixedAssets.maintenance.nextDue'), render: (row) => (row.basis === 'time' ? <CalendarDate value={row.next_due_on} /> : <span className="tabular-nums">{row.next_due_reading}</span>) },
        ]}
        rows={schedules.data?.data ?? []}
        emptyText={t('fixedAssets.maintenance.noSchedules')}
      />
      <DataTable
        caption={t('fixedAssets.maintenance.orders')}
        columns={[
          { key: 'number', label: t('fixedAssets.columns.number'), render: (row) => <span className="tabular-nums">{row.number}</span> },
          { key: 'title', label: t('fixedAssets.maintenance.title'), render: (row) => row.title },
          { key: 'status', label: t('fixedAssets.columns.status'), render: (row) => <FaStatus status={row.status} /> },
          { key: 'due_on', label: t('fixedAssets.maintenance.due'), render: (row) => <CalendarDate value={row.due_on} /> },
          { key: 'cost', label: t('fixedAssets.maintenance.cost'), align: 'end', render: (row) => <Amount value={row.cost} /> },
        ]}
        rows={orders.data?.data ?? []}
        emptyText={t('fixedAssets.maintenance.noOrders')}
      />
      <FormDialog
        open={dialog === 'reading'}
        title={t('fixedAssets.readings.add')}
        confirm={t('fixedAssets.readings.save')}
        path={`fixed-assets/assets/${asset.id}/readings`}
        fields={[
          { name: 'read_on', label: t('fixedAssets.readings.date'), type: 'date', required: true },
          { name: 'reading', label: t('fixedAssets.readings.reading'), type: 'decimal', required: true },
          { name: 'note', label: t('fixedAssets.readings.note') },
        ]}
        onClose={() => setDialog(null)}
        onDone={done}
      />
      <FormDialog
        open={dialog === 'schedule'}
        title={t('fixedAssets.maintenance.addSchedule')}
        confirm={t('fixedAssets.maintenance.saveSchedule')}
        path={`fixed-assets/assets/${asset.id}/schedules`}
        initial={{ basis: 'time' }}
        fields={[
          { name: 'name', label: t('fixedAssets.maintenance.name'), required: true },
          { name: 'basis', label: t('fixedAssets.maintenance.basis'), type: 'select', options: ['time', 'usage'].map((value) => ({ value, label: t(`fixedAssets.maintenance.bases.${value}`) })) },
          { name: 'interval_days', label: t('fixedAssets.maintenance.intervalDays'), type: 'number', hidden: (values) => values.basis !== 'time' },
          { name: 'interval_units', label: t('fixedAssets.maintenance.intervalUnits'), type: 'decimal', hidden: (values) => values.basis !== 'usage' },
          { name: 'warning_days', label: t('fixedAssets.maintenance.warningDays'), type: 'number' },
        ]}
        toBody={(values) => Object.fromEntries(Object.entries(values).filter(([, value]) => value !== '').map(([key, value]) => [key, ['interval_days', 'warning_days'].includes(key) ? Number(value) : value]))}
        onClose={() => setDialog(null)}
        onDone={done}
      />
      <FormDialog
        open={dialog === 'order'}
        title={t('fixedAssets.maintenance.addOrder')}
        confirm={t('fixedAssets.maintenance.saveOrder')}
        path={`fixed-assets/assets/${asset.id}/work-orders`}
        fields={[
          { name: 'title', label: t('fixedAssets.maintenance.title'), required: true },
          { name: 'schedule_id', label: t('fixedAssets.maintenance.schedule'), type: 'select', options: [{ value: '', label: t('fixedAssets.maintenance.noSchedule') }, ...(schedules.data?.data ?? []).map((row) => ({ value: row.id, label: row.name }))] },
          { name: 'due_on', label: t('fixedAssets.maintenance.due'), type: 'date' },
        ]}
        onClose={() => setDialog(null)}
        onDone={done}
      />
    </div>
  )
}

/**
 * FA-04 to FA-06, FA-11, FA-14 to FA-19, FA-25, FA-26: one asset: its
 * place and people, its book, its schedule, history, documents and
 * maintenance, and what the reader may do with it (activate, change its
 * estimates, transfer, revalue or impair, dispose, print its label).
 */
export default function AssetDetail() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { assetId } = useParams()
  const queryClient = useQueryClient()
  const locations = useLocations()
  const [tab, setTab] = useState('schedule')
  const [dialog, setDialog] = useState(null)
  const [printing, setPrinting] = useState(null)
  const key = [...faKey, 'asset', assetId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`fixed-assets/assets/${assetId}`) })
  const custodians = useQuery({
    queryKey: [...faKey, 'custodians', query.data?.data?.company?.id],
    queryFn: () => api.get(`fixed-assets/custodians?company=${query.data.data.company.id}`),
    enabled: Boolean(query.data?.data?.can?.transfer),
    retry: false,
  })
  const label = useMutation({
    mutationFn: async () => {
      const { blob } = await api.download('fixed-assets/labels', { method: 'POST', body: { labels: [{ asset_id: assetId, copies: 1 }], format: 'html' } })
      return blob.text()
    },
    onSuccess: (html) => setPrinting((current) => ({ html, n: (current?.n ?? 0) + 1 })),
  })
  const back = <Back to="/fixed-assets/assets">{t('fixedAssets.assets.back')}</Back>
  const asset = query.data?.data

  if (!asset) return <DetailState query={query} back={back} notFound={t('fixedAssets.detail.notFound')} />

  const refresh = async (message) => {
    setDialog(null)
    if (message) toast.success(message)
    await queryClient.invalidateQueries({ queryKey: faKey })
  }
  const base = asset.base_cost?.currency ?? asset.cost?.currency
  const status = asset.archived_at ? 'archived' : asset.status

  return (
    <>
      {back}
      <PageHeader
        eyebrow={asset.number}
        title={asset.name}
        description={<FaStatus status={status} />}
        actions={
          <div className="flex flex-wrap gap-2">
            {asset.can?.print ? (
              <Button icon="print" loading={label.isPending} onClick={() => label.mutate()}>
                {t('fixedAssets.detail.printLabel')}
              </Button>
            ) : null}
            {asset.status === 'draft' && asset.can?.edit ? <Button onClick={() => navigate(`/fixed-assets/assets/${asset.id}/edit`)}>{t('fixedAssets.detail.edit')}</Button> : null}
            {asset.can?.cancel ? <Button variant="ghost" onClick={() => setDialog('cancel')}>{t('fixedAssets.detail.cancel')}</Button> : null}
            {asset.can?.estimates ? <Button onClick={() => setDialog('estimates')}>{t('fixedAssets.detail.estimates')}</Button> : null}
            {asset.can?.transfer ? <Button onClick={() => setDialog('transfer')}>{t('fixedAssets.detail.transfer')}</Button> : null}
            {asset.can?.revalue ? <Button onClick={() => setDialog('revalue')}>{t('fixedAssets.detail.revalue')}</Button> : null}
            {asset.can?.dispose ? <Button variant="danger" onClick={() => setDialog('dispose')}>{t('fixedAssets.detail.dispose')}</Button> : null}
            {asset.can?.activate ? (
              <Button variant="pay" onClick={() => setDialog('activate')}>
                {t('fixedAssets.detail.activate')}
              </Button>
            ) : null}
          </div>
        }
      />
      {label.isError ? <Alert tone="danger" title={errorMessage(label.error)} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('fixedAssets.detail.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('fixedAssets.fields.category')}>{asset.category ? `${asset.category.code} · ${asset.category.name}` : '—'}</Detail>
            <Detail label={t('fixedAssets.fields.location')}>{asset.location ? `${asset.location.name} · ${asset.branch?.name ?? ''}` : '—'}</Detail>
            <Detail label={t('fixedAssets.fields.custodian')}>{asset.custodian?.name ?? '—'}</Detail>
            <Detail label={t('fixedAssets.fields.costCentre')}>{asset.cost_centre?.name ?? '—'}</Detail>
            <Detail label={t('fixedAssets.fields.serialNumber')}>{asset.serial_number ?? '—'}</Detail>
            <Detail label={t('fixedAssets.fields.supplier')}>{asset.supplier?.name ?? '—'}</Detail>
            <Detail label={t('fixedAssets.fields.acquiredOn')}>
              <CalendarDate value={asset.acquired_on} />
            </Detail>
            <Detail label={t('fixedAssets.fields.inServiceOn')}>
              <CalendarDate value={asset.in_service_on} />
            </Detail>
            <Detail label={t('fixedAssets.fields.warrantyUntil')}>
              <CalendarDate value={asset.warranty_until} />
            </Detail>
            <Detail label={t('fixedAssets.fields.method')}>
              {asset.method ? `${t(`fixedAssets.methods.${asset.method}`)}${asset.useful_life_months ? ` · ${t('fixedAssets.detail.months', { count: asset.useful_life_months })}` : ''}` : '—'}
            </Detail>
            <Detail label={t('fixedAssets.fields.source')}>{t(`fixedAssets.sources.${asset.source}`)}</Detail>
            {asset.description ? <Detail label={t('fixedAssets.fields.description')}>{asset.description}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('fixedAssets.detail.book')} subtitle={asset.depreciated_to ? t('fixedAssets.detail.depreciatedTo', { date: asset.depreciated_to }) : null}>
          <dl className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            <Detail label={t('fixedAssets.fields.cost')}>
              <Amount value={asset.book_cost?.amount_minor !== '0' ? asset.book_cost : asset.cost} />
            </Detail>
            <Detail label={t('fixedAssets.columns.accumulated')}>
              <Amount value={asset.accumulated} />
            </Detail>
            <Detail label={t('fixedAssets.columns.impairment')}>
              <Amount value={asset.impairment} />
            </Detail>
            <Detail label={t('fixedAssets.detail.surplus')}>
              <Amount value={asset.surplus} />
            </Detail>
            <Detail label={t('fixedAssets.fields.residual')}>
              <Amount value={asset.residual} />
            </Detail>
            <Detail label={t('fixedAssets.columns.bookValue')}>
              <Amount value={asset.book_value} className="font-medium" />
            </Detail>
          </dl>
        </Card>
        <Tabs
          value={tab}
          onChange={setTab}
          items={['schedule', 'history', 'documents', 'maintenance'].map((value) => ({ value, label: t(`fixedAssets.tabs.${value}`) }))}
        />
        {tab === 'schedule' ? <SchedulePanel asset={asset} /> : null}
        {tab === 'history' ? <HistoryPanel asset={asset} /> : null}
        {tab === 'documents' ? <DocumentsPanel asset={asset} /> : null}
        {tab === 'maintenance' ? <MaintenancePanel asset={asset} onChanged={() => refresh()} /> : null}
      </div>
      <PrintFrame printing={printing} />
      <FormDialog
        open={dialog === 'activate'}
        title={t('fixedAssets.detail.activateTitle', { number: asset.number })}
        help={t('fixedAssets.detail.activateHelp')}
        confirm={t('fixedAssets.detail.activate')}
        tone="pay"
        path={`fixed-assets/assets/${asset.id}/activate`}
        initial={{ in_service_on: asset.in_service_on ?? asset.acquired_on ?? '' }}
        fields={[{ name: 'in_service_on', label: t('fixedAssets.fields.inServiceOn'), type: 'date' }]}
        onClose={() => setDialog(null)}
        onDone={() => refresh(t('fixedAssets.detail.activated'))}
      />
      <ReasonDialog
        open={dialog === 'cancel'}
        title={t('fixedAssets.detail.cancelTitle', { number: asset.number })}
        help={t('fixedAssets.detail.cancelHelp')}
        confirm={t('fixedAssets.detail.cancel')}
        path={`fixed-assets/assets/${asset.id}/cancel`}
        onClose={() => setDialog(null)}
        onDone={() => refresh(t('fixedAssets.detail.cancelled'))}
      />
      <FormDialog
        open={dialog === 'estimates'}
        title={t('fixedAssets.detail.estimatesTitle')}
        help={t('fixedAssets.detail.estimatesHelp')}
        confirm={t('fixedAssets.detail.saveEstimates')}
        path={`fixed-assets/assets/${asset.id}/estimates`}
        initial={{ method: asset.method ?? '', useful_life_months: asset.useful_life_months ? String(asset.useful_life_months) : '', residual_minor: asset.residual?.amount_minor ?? '', declining_rate: asset.declining_rate ?? '' }}
        fields={[
          { name: 'method', label: t('fixedAssets.fields.method'), type: 'select', options: METHODS.map((value) => ({ value, label: t(`fixedAssets.methods.${value}`) })) },
          { name: 'useful_life_months', label: t('fixedAssets.fields.usefulLife'), type: 'number' },
          { name: 'residual_minor', label: t('fixedAssets.fields.residual'), type: 'money', currencyCode: base },
          { name: 'declining_rate', label: t('fixedAssets.fields.decliningRate'), type: 'decimal', maxDecimals: 4, hidden: (values) => values.method !== 'declining_balance' },
          { name: 'reason', label: t('fixedAssets.fields.reason'), required: true },
        ]}
        toBody={(values) => ({
          method: values.method,
          reason: values.reason,
          ...(values.useful_life_months ? { useful_life_months: Number(values.useful_life_months) } : {}),
          ...(values.residual_minor !== '' ? { residual_minor: Number(values.residual_minor) } : {}),
          ...(values.method === 'declining_balance' && values.declining_rate ? { declining_rate: values.declining_rate } : {}),
        })}
        onClose={() => setDialog(null)}
        onDone={() => refresh(t('fixedAssets.detail.estimatesSaved'))}
      />
      <FormDialog
        open={dialog === 'transfer'}
        title={t('fixedAssets.detail.transferTitle', { number: asset.number })}
        help={t('fixedAssets.detail.transferHelp')}
        confirm={t('fixedAssets.detail.requestTransfer')}
        path={`fixed-assets/assets/${asset.id}/transfers`}
        initial={{ to_location_id: '', to_custodian_user_id: '', transfer_date: '' }}
        fields={[
          {
            name: 'to_location_id',
            label: t('fixedAssets.transfers.toLocation'),
            type: 'select',
            options: [{ value: '', label: t('fixedAssets.transfers.samePlace') }, ...locations.filter((location) => location.id !== asset.location?.id).map((location) => ({ value: location.id, label: locationLabel(location) }))],
          },
          {
            name: 'to_custodian_user_id',
            label: t('fixedAssets.transfers.toCustodian'),
            type: 'select',
            options: [{ value: '', label: t('fixedAssets.transfers.sameCustodian') }, ...(custodians.data?.data ?? []).filter((row) => row.type === 'user').map((row) => ({ value: row.id, label: row.name }))],
          },
          { name: 'transfer_date', label: t('fixedAssets.transfers.date'), type: 'date', required: true },
          { name: 'reason', label: t('fixedAssets.fields.reason'), required: true },
        ]}
        onClose={() => setDialog(null)}
        onDone={(response) => {
          refresh(t('fixedAssets.transfers.requested', { number: response.data.number }))
          navigate(`/fixed-assets/transfers/${response.data.id}`)
        }}
      />
      <FormDialog
        open={dialog === 'revalue'}
        title={t('fixedAssets.detail.revalueTitle', { number: asset.number })}
        help={t('fixedAssets.detail.revalueHelp')}
        confirm={t('fixedAssets.detail.postRevaluation')}
        path={`fixed-assets/assets/${asset.id}/revaluations`}
        initial={{ kind: 'revaluation' }}
        fields={[
          { name: 'kind', label: t('fixedAssets.revaluations.kind'), type: 'select', options: ['revaluation', 'impairment'].map((value) => ({ value, label: t(`fixedAssets.revaluations.kinds.${value}`) })) },
          { name: 'revalued_on', label: t('fixedAssets.revaluations.date'), type: 'date', required: true },
          { name: 'value_minor', label: t('fixedAssets.revaluations.value'), help: t('fixedAssets.revaluations.valueHelp'), type: 'money', currencyCode: base, required: true },
          { name: 'reason', label: t('fixedAssets.fields.reason'), required: true },
        ]}
        toBody={(values) => ({ ...values, value_minor: Number(values.value_minor) })}
        onClose={() => setDialog(null)}
        onDone={() => refresh(t('fixedAssets.revaluations.posted'))}
      />
      <FormDialog
        open={dialog === 'dispose'}
        title={t('fixedAssets.detail.disposeTitle', { number: asset.number })}
        help={t('fixedAssets.detail.disposeHelp')}
        confirm={t('fixedAssets.detail.requestDisposal')}
        tone="danger"
        path={`fixed-assets/assets/${asset.id}/disposals`}
        initial={{ kind: 'sale', currency: base }}
        fields={[
          { name: 'kind', label: t('fixedAssets.disposals.kind'), type: 'select', options: DISPOSAL_KINDS.map((value) => ({ value, label: t(`fixedAssets.disposals.kinds.${value}`) })) },
          { name: 'disposal_date', label: t('fixedAssets.disposals.date'), type: 'date', required: true },
          { name: 'proceeds_minor', label: t('fixedAssets.disposals.proceeds'), type: 'money', currency: (values) => values.currency, hidden: (values) => values.kind !== 'sale', required: true },
          { name: 'reason', label: t('fixedAssets.fields.reason'), required: true },
        ]}
        toBody={(values) => ({
          kind: values.kind,
          disposal_date: values.disposal_date,
          reason: values.reason,
          ...(values.kind === 'sale' ? { proceeds_minor: Number(values.proceeds_minor), currency: values.currency } : {}),
        })}
        onClose={() => setDialog(null)}
        onDone={(response) => {
          refresh(t('fixedAssets.disposals.requested', { number: response.data.number }))
          navigate(`/fixed-assets/disposals/${response.data.id}`)
        }}
      />
    </>
  )
}
