import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Money, MoneyInput, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies } from '@/layouts/companySelection'
import { When } from '@/pages/crm/CrmParts'
import { CASHUP_MANAGE, DELIVERY_ASSIGN, DELIVERY_COLUMNS, ecomKey, namedOptions, RIDER_MANAGE, TONES, useEcomAction, useRiders } from './ecommerceData'

/** ECOM-27, ECOM-29: open deliveries by status, with their rider, last report and a rider picker. */
export function Deliveries() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const query = useQuery({ queryKey: [...ecomKey, 'deliveries'], queryFn: () => api.get('ecommerce/deliveries?per_page=200') })
  const riders = useRiders(can(DELIVERY_ASSIGN))
  const action = useEcomAction()
  const [picked, setPicked] = useState({})
  const rows = query.data?.data ?? []
  return (
    <>
      <PageHeader title={t('ecommerce.deliveries.title')} description={t('ecommerce.deliveries.description')} />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <div className="grid gap-4 lg:grid-cols-3">
        {DELIVERY_COLUMNS.map((status) => {
          const cards = rows.filter((row) => row.status === status)
          return (
            <section key={status} aria-label={t(`ecommerce.deliveryStatuses.${status}`)} className="flex flex-col gap-3">
              <h2 className="flex items-center justify-between text-h3 text-ink">
                <StatusBadge tone={TONES[status]}>{t(`ecommerce.deliveryStatuses.${status}`)}</StatusBadge>
                <span className="text-caption text-ink-muted tabular-nums">{cards.length}</span>
              </h2>
              {cards.length === 0 ? <p className="text-body text-ink-muted">{t('ecommerce.deliveries.none')}</p> : null}
              {cards.map((row) => (
                <Card key={row.id} title={<Link className="hover:underline" to={`/ecommerce/orders/${row.order_id}`}>{row.order?.number}</Link>} subtitle={row.order?.contact_name}>
                  <dl className="flex flex-col gap-1 text-body">
                    <dd>{[row.order?.address?.line, row.order?.zone?.name].filter(Boolean).join(' · ')}</dd>
                    {row.cod_due_minor !== '0' ? <dd className="flex gap-2"><span className="text-ink-muted">{t('ecommerce.deliveries.collect')}</span><Money amount={row.cod_due_minor} currency={row.currency} /></dd> : null}
                    {row.rider ? <dd>{row.rider.name}</dd> : null}
                    {row.last_location ? <dd className="text-caption text-ink-muted">{t('ecommerce.deliveries.lastSeen')} <When value={row.last_location.at} /></dd> : null}
                  </dl>
                  {can(DELIVERY_ASSIGN) && status !== 'picked_up' ? (
                    <div className="mt-3 flex items-end gap-2">
                      <Select label={t('ecommerce.deliveries.rider')} placeholder={t('ecommerce.deliveries.chooseRider')} options={namedOptions(riders)} value={picked[row.id] ?? row.rider?.id ?? ''} onChange={(event) => setPicked({ ...picked, [row.id]: event.target.value })} />
                      <Button disabled={!picked[row.id]} onClick={() => action.mutate({ path: `ecommerce/deliveries/${row.id}/assign`, body: { rider_id: picked[row.id] }, done: 'ecommerce.deliveries.assigned' })}>{t('ecommerce.actions.assign')}</Button>
                    </div>
                  ) : null}
                </Card>
              ))}
            </section>
          )
        })}
      </div>
    </>
  )
}

/** ECOM-26: riders are users of the tenant, at a company. */
export function Riders() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const query = useQuery({ queryKey: [...ecomKey, 'riders', 'all'], queryFn: () => api.get('ecommerce/riders?status=all') })
  const [adding, setAdding] = useState(false)
  const action = useEcomAction()
  const manage = can(RIDER_MANAGE)
  const columns = [
    { key: 'name', label: t('ecommerce.columns.name'), render: (row) => <span className="font-medium text-ink">{row.name}</span> },
    { key: 'phone', label: t('ecommerce.columns.phone'), render: (row) => row.phone ?? '—' },
    { key: 'vehicle', label: t('ecommerce.columns.vehicle'), render: (row) => row.vehicle ?? '—' },
    { key: 'company', label: t('ecommerce.columns.company'), render: (row) => row.company?.name },
    { key: 'status', label: t('ecommerce.columns.status'), render: (row) => <StatusBadge tone={row.active ? 'success' : 'neutral'}>{t(row.active ? 'ecommerce.riders.active' : 'ecommerce.riders.inactive')}</StatusBadge> },
    ...(manage
      ? [{ key: 'actions', label: t('ecommerce.columns.actions'), align: 'end', render: (row) => (row.archived_at ? null : <Button variant="ghost" icon="archive" aria-label={t('ecommerce.riders.archiveNamed', { name: row.name })} onClick={() => action.mutate({ path: `ecommerce/riders/${row.id}/archive`, done: 'ecommerce.riders.archived' })} />) }]
      : []),
  ]
  return (
    <>
      <PageHeader title={t('ecommerce.riders.title')} description={t('ecommerce.riders.description')} actions={manage ? <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>{t('ecommerce.riders.add')}</Button> : null} />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable caption={t('ecommerce.riders.title')} columns={columns} rows={query.data?.data ?? []} loading={query.isPending} emptyText={t('ecommerce.riders.empty')} />
      {adding ? <RiderDialog onClose={() => setAdding(false)} /> : null}
    </>
  )
}

function RiderDialog({ onClose }) {
  const { t } = useTranslation()
  const { companies } = useCompanies()
  const users = useQuery({ queryKey: ['users', 'ecommerce-riders'], queryFn: () => api.get('users?per_page=200') })
  const [values, setValues] = useState({ company_id: companies[0]?.id ?? '', user_id: '', phone: '', vehicle: '' })
  const action = useEcomAction({ onDone: onClose })
  const errors = formErrors(action.error, ['company_id', 'user_id', 'phone', 'vehicle'])
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  return (
    <Dialog
      open
      title={t('ecommerce.riders.add')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button variant="primary" loading={action.isPending} disabled={!values.company_id || !values.user_id} onClick={() => action.mutate({ path: 'ecommerce/riders', body: Object.fromEntries(Object.entries(values).filter(([, v]) => v !== '')), done: 'ecommerce.riders.added' })}>{t('ecommerce.riders.save')}</Button>
        </>
      }
    >
      <div className="grid gap-4 pt-1">
        <Select label={t('ecommerce.fields.company')} options={namedOptions(companies.filter((c) => !c.archived_at))} value={values.company_id} required error={errors.fields.company_id} onChange={(event) => set('company_id', event.target.value)} />
        <Select label={t('ecommerce.fields.user')} placeholder={t('ecommerce.riders.chooseUser')} options={namedOptions(users.data?.data ?? [])} value={values.user_id} required error={errors.fields.user_id} onChange={(event) => set('user_id', event.target.value)} />
        <TextField label={t('ecommerce.fields.phone')} value={values.phone} error={errors.fields.phone} onChange={(event) => set('phone', event.target.value)} />
        <TextField label={t('ecommerce.fields.vehicle')} value={values.vehicle} error={errors.fields.vehicle} onChange={(event) => set('vehicle', event.target.value)} />
      </div>
    </Dialog>
  )
}

/** ECOM-30: cash collected on delivery and not handed in yet, per rider and currency; closed cash-ups. */
export function Cashups() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const pending = useQuery({ queryKey: [...ecomKey, 'cashups', 'pending'], queryFn: () => api.get('ecommerce/cashups/pending') })
  const closed = useQuery({ queryKey: [...ecomKey, 'cashups'], queryFn: () => api.get('ecommerce/cashups?per_page=50') })
  const [closing, setClosing] = useState(null)
  const pendingColumns = [
    { key: 'rider', label: t('ecommerce.columns.rider'), render: (row) => <span className="font-medium text-ink">{row.rider?.name}</span> },
    { key: 'deliveries', label: t('ecommerce.columns.deliveries'), align: 'end', render: (row) => <span className="tabular-nums">{row.deliveries}</span> },
    { key: 'expected', label: t('ecommerce.columns.expected'), align: 'end', render: (row) => <Money amount={row.expected_minor} currency={row.currency} /> },
    ...(can(CASHUP_MANAGE) ? [{ key: 'actions', label: t('ecommerce.columns.actions'), align: 'end', render: (row) => <Button onClick={() => setClosing(row)}>{t('ecommerce.cashups.close')}</Button> }] : []),
  ]
  const closedColumns = [
    { key: 'rider', label: t('ecommerce.columns.rider'), render: (row) => row.rider?.name },
    { key: 'closed_at', label: t('ecommerce.columns.closedAt'), render: (row) => <When value={row.closed_at} companyId={row.company?.id} /> },
    { key: 'expected', label: t('ecommerce.columns.expected'), align: 'end', render: (row) => <Money amount={row.expected_minor} currency={row.currency} /> },
    { key: 'counted', label: t('ecommerce.columns.counted'), align: 'end', render: (row) => <Money amount={row.counted_minor} currency={row.currency} /> },
    { key: 'variance', label: t('ecommerce.columns.variance'), align: 'end', render: (row) => <Money amount={row.variance_minor} currency={row.currency} tone={row.variance_minor.startsWith('-') ? 'danger' : undefined} /> },
  ]
  return (
    <>
      <PageHeader title={t('ecommerce.cashups.title')} description={t('ecommerce.cashups.description')} />
      <div className="flex flex-col gap-6">
        <Card title={t('ecommerce.cashups.pending')}>
          {pending.isError ? <Alert tone="danger" title={errorMessage(pending.error)} /> : null}
          <DataTable caption={t('ecommerce.cashups.pending')} columns={pendingColumns} rows={(pending.data?.data ?? []).map((row) => ({ ...row, id: `${row.rider_id}-${row.currency}` }))} loading={pending.isPending} emptyText={t('ecommerce.cashups.nonePending')} />
        </Card>
        <Card title={t('ecommerce.cashups.closed')}>
          <DataTable caption={t('ecommerce.cashups.closed')} columns={closedColumns} rows={closed.data?.data ?? []} loading={closed.isPending} emptyText={t('ecommerce.cashups.noneClosed')} />
        </Card>
      </div>
      {closing ? <CashupDialog row={closing} onClose={() => setClosing(null)} /> : null}
    </>
  )
}

function CashupDialog({ row, onClose }) {
  const { t } = useTranslation()
  const [counted, setCounted] = useState(row.expected_minor)
  const [note, setNote] = useState('')
  const action = useEcomAction({ onDone: onClose })
  const errors = formErrors(action.error, ['counted_minor', 'note'])
  return (
    <Dialog
      open
      title={t('ecommerce.cashups.closeTitle', { name: row.rider?.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('ecommerce.actions.back')}</Button>
          <Button variant="pay" loading={action.isPending} disabled={counted === '' || counted === null} onClick={() => action.mutate({ path: 'ecommerce/cashups', body: { rider_id: row.rider_id, currency: row.currency, counted_minor: counted, note: note || undefined }, done: 'ecommerce.cashups.closedDone' })}>{t('ecommerce.cashups.confirm')}</Button>
        </>
      }
    >
      <div className="grid gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <p className="flex gap-2 text-body"><span className="text-ink-muted">{t('ecommerce.columns.expected')}</span><Money amount={row.expected_minor} currency={row.currency} /></p>
        <MoneyInput label={t('ecommerce.cashups.counted')} currency={row.currency} value={counted} required error={errors.fields.counted_minor} onChange={setCounted} />
        <TextField label={t('ecommerce.fields.note')} value={note} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} />
      </div>
    </Dialog>
  )
}
