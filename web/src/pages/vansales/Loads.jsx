import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ItemPicker } from '@/pages/inventory/InventoryParts'
import { isPositive, locationOptions, usePlaces } from '@/pages/inventory/inventoryData'
import { ActionsLabel, QueryNotes, ReasonDialog, Status, When } from './VanSalesParts'
import { LOAD_CREATE, LOAD_RELEASE, LOAD_STATUSES, statusLabel, useVans, vanError, vanSalesKey } from './vansalesData'

let lineKey = 0
const newLine = () => ({ key: ++lineKey, item: null, qty: '' })

/** VAN-07: ask for stock from a warehouse to a van. */
function RequestDialog({ onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { active: vans } = useVans()
  const places = usePlaces()
  const [values, setValues] = useState({ van_id: '', from_location_id: '', note: '' })
  const [lines, setLines] = useState(() => [newLine()])
  const setLine = (key, patch) => setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  const filled = lines.filter((line) => line.item && isPositive(line.qty))
  const save = useMutation({
    mutationFn: () => api.post('van-sales/loads', { van_id: values.van_id, from_location_id: values.from_location_id, ...(values.note.trim() ? { note: values.note.trim() } : {}), lines: filled.map((line) => ({ item_id: line.item.id, qty: line.qty.trim() })) }),
    onSuccess: () => {
      toast.success(t('vansales.loads.requested'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['van_id', 'from_location_id', 'note', 'lines'])
  const vanLocations = new Set(vans.map((van) => van.location_id))
  const warehouses = places.activeLocations.filter((location) => !vanLocations.has(location.id))
  return (
    <Dialog
      open
      size="lg"
      title={t('vansales.loads.requestTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!values.van_id || !values.from_location_id || !filled.length} onClick={() => save.mutate()}>
            {t('vansales.loads.request')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={vanError(t, save.error)} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label={t('vansales.loads.van')} placeholder={t('vansales.loads.pickVan')} options={vans.map((van) => ({ value: van.id, label: van.name }))} value={values.van_id} error={errors.fields.van_id} onChange={(event) => setValues((current) => ({ ...current, van_id: event.target.value }))} />
          <Select label={t('vansales.loads.from')} placeholder={t('vansales.loads.pickWarehouse')} options={locationOptions(warehouses)} value={values.from_location_id} error={errors.fields.from_location_id} onChange={(event) => setValues((current) => ({ ...current, from_location_id: event.target.value }))} />
        </div>
        <TextField label={t('vansales.loads.note')} value={values.note} error={errors.fields.note} onChange={(event) => setValues((current) => ({ ...current, note: event.target.value }))} />
        <div className="flex flex-col gap-3">
          <span className="text-label text-ink">{t('vansales.loads.lines')}</span>
          {errors.fields.lines ? <p className="text-caption text-danger">{errors.fields.lines}</p> : null}
          {lines.map((line, index) => (
            <div key={line.key} className="flex flex-wrap items-end gap-3">
              <ItemPicker label={t('vansales.loads.item', { number: index + 1 })} value={line.item} onChange={(item) => setLine(line.key, { item })} className="min-w-56 flex-1" />
              <TextField label={t('vansales.loads.qty', { number: index + 1 })} inputMode="decimal" value={line.qty} onChange={(event) => setLine(line.key, { qty: event.target.value })} className="w-32" />
              {lines.length > 1 ? (
                <Button variant="ghost" aria-label={t('vansales.loads.removeLine', { number: index + 1 })} onClick={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}>
                  {t('vansales.loads.remove')}
                </Button>
              ) : null}
            </div>
          ))}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, newLine()])}>
              {t('vansales.loads.addLine')}
            </Button>
          </div>
        </div>
      </div>
    </Dialog>
  )
}

/** One load sheet with its lines and what the user may do to it. */
function LoadDetail({ loadId, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [cancelling, setCancelling] = useState(false)
  const query = useQuery({ queryKey: [...vanSalesKey, 'load', loadId], queryFn: () => api.get(`van-sales/loads/${loadId}`) })
  const load = query.data?.data
  const done = () => {
    queryClient.invalidateQueries({ queryKey: vanSalesKey })
    setCancelling(false)
  }
  const release = useMutation({
    mutationFn: () => api.post(`van-sales/loads/${loadId}/release`, {}),
    onSuccess: () => {
      toast.success(t('vansales.loads.released'))
      done()
    },
  })
  const canRelease = load?.status === 'approved' && can(LOAD_RELEASE)
  const canCancel = ['awaiting_approval', 'approved'].includes(load?.status) && can([LOAD_CREATE, LOAD_RELEASE])
  const lineColumns = [
    { key: 'item', label: t('vansales.loads.itemColumn'), render: (line) => line.item_name },
    { key: 'qty', label: t('vansales.loads.requestedQty'), numeric: true, align: 'end', render: (line) => line.qty },
    { key: 'confirmed', label: t('vansales.loads.confirmedQty'), numeric: true, align: 'end', render: (line) => line.qty_confirmed ?? '' },
  ]
  return (
    <>
      <Dialog
        open
        size="lg"
        title={load ? t('vansales.loads.detailTitle', { number: load.number }) : t('vansales.loads.title')}
        onClose={onClose}
        footer={
          <>
            <Button onClick={onClose}>{t('vansales.loads.close')}</Button>
            {canCancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('vansales.loads.cancel')}
              </Button>
            ) : null}
            {canRelease ? (
              <Button variant="primary" loading={release.isPending} onClick={() => release.mutate()}>
                {t('vansales.loads.release')}
              </Button>
            ) : null}
          </>
        }
      >
        <div className="flex flex-col gap-4 text-ink">
          <QueryNotes query={query} />
          {release.isError ? <Alert tone="danger" title={vanError(t, release.error)} /> : null}
          {load ? (
            <>
              <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-ink-muted">
                <Status status={load.status} />
                <span>{load.van_name}</span>
                <span>{t('vansales.loads.fromNamed', { name: load.from_location_name })}</span>
                <span>{t('vansales.loads.requestedBy', { name: load.requested_by_name ?? '' })}</span>
              </div>
              {load.note ? <p>{load.note}</p> : null}
              <DataTable caption={t('vansales.loads.lines')} columns={lineColumns} rows={load.lines ?? []} />
              <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-ink-muted">
                <dt>{t('vansales.loads.requestedAt')}</dt>
                <dd><When value={load.requested_at} /></dd>
                <dt>{t('vansales.loads.releasedAt')}</dt>
                <dd><When value={load.released_at} /></dd>
                <dt>{t('vansales.loads.confirmedAt')}</dt>
                <dd><When value={load.confirmed_at} /></dd>
              </dl>
            </>
          ) : null}
        </div>
      </Dialog>
      {cancelling ? (
        <ReasonDialog title={t('vansales.loads.cancelTitle')} help={t('vansales.loads.cancelHelp')} label={t('vansales.loads.cancelReason')} confirm={t('vansales.loads.cancelConfirm')} variant="danger" path={`van-sales/loads/${loadId}/cancel`} onClose={() => setCancelling(false)} onDone={() => { toast.success(t('vansales.loads.cancelled')); done() }} />
      ) : null}
    </>
  )
}

/** VAN-07, VAN-08: load sheets from warehouse to van; request, release and cancel. */
export default function Loads() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [status, setStatus] = useState('')
  const [requesting, setRequesting] = useState(false)
  const [selected, setSelected] = useState(null)
  const query = useQuery({ queryKey: [...vanSalesKey, 'loads'], queryFn: () => api.get('van-sales/loads') })
  const loads = (query.data?.data ?? []).filter((load) => !status || load.status === status)
  const columns = [
    { key: 'number', label: t('vansales.loads.number'), render: (load) => load.number },
    { key: 'van', label: t('vansales.loads.van'), render: (load) => load.van_name },
    { key: 'from', label: t('vansales.loads.from'), render: (load) => load.from_location_name },
    { key: 'status', label: t('vansales.fields.status'), render: (load) => <Status status={load.status} /> },
    { key: 'requested', label: t('vansales.loads.requestedAt'), render: (load) => <When value={load.requested_at} /> },
    { key: 'lines', label: t('vansales.loads.lineCount'), numeric: true, align: 'end', render: (load) => (load.lines ?? []).length },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (load) => (
        <Button variant="ghost" aria-label={t('vansales.loads.openNamed', { number: load.number })} onClick={() => setSelected(load.id)}>
          {t('vansales.loads.open')}
        </Button>
      ),
    },
  ]
  return (
    <>
      <PageHeader
        title={t('vansales.loads.title')}
        description={t('vansales.loads.description')}
        actions={
          can(LOAD_CREATE) ? (
            <Button variant="primary" icon="plus" onClick={() => setRequesting(true)}>
              {t('vansales.loads.request')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <Select label={t('vansales.fields.status')} options={[{ value: '', label: t('vansales.loads.allStatuses') }, ...LOAD_STATUSES.map((entry) => ({ value: entry, label: statusLabel(t, entry) }))]} value={status} onChange={(event) => setStatus(event.target.value)} className="max-w-field" />
        {query.isError ? <QueryNotes query={query} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.loads.title')} columns={columns} rows={loads} loading={query.isPending} emptyText={t('vansales.loads.empty')} /> : null}
      </div>
      {requesting ? <RequestDialog onClose={() => setRequesting(false)} /> : null}
      {selected ? <LoadDetail key={selected} loadId={selected} onClose={() => setSelected(null)} /> : null}
    </>
  )
}
