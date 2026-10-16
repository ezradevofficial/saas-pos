import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Card, DataTable, Money, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ecomKey, namedOptions, useStores } from './ecommerceData'

const today = () => new Date().toISOString().slice(0, 10)
const monthStart = () => `${today().slice(0, 8)}01`

/** ECOM-36: a period of a store (or every store): revenue per currency, channels, payments, top products, deliveries, cash outstanding. */
export default function Reports() {
  const { t } = useTranslation()
  const { stores } = useStores()
  const [filters, setFilters] = useState({ store: '', from: monthStart(), to: today() })
  const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)).toString()
  const query = useQuery({ queryKey: [...ecomKey, 'report', params], queryFn: () => api.get(`ecommerce/reports/summary?${params}`) })
  const data = query.data?.data
  const set = (name, value) => setFilters((current) => ({ ...current, [name]: value }))
  const counted = (group, rows) => (
    <ul className="flex flex-col gap-1 text-body">
      {(rows ?? []).map((row) => (
        <li key={row.key} className="flex justify-between gap-3">
          <span>{t(`ecommerce.${group}.${row.key}`, { defaultValue: row.key })}</span>
          <span className="tabular-nums">{row.orders}</span>
        </li>
      ))}
      {rows?.length ? null : <li className="text-ink-muted">—</li>}
    </ul>
  )
  return (
    <>
      <PageHeader title={t('ecommerce.reports.title')} description={t('ecommerce.reports.description')} />
      <div className="flex flex-wrap items-end gap-4">
        <Select label={t('ecommerce.fields.store')} options={[{ value: '', label: t('ecommerce.filters.all.store') }, ...namedOptions(stores)]} value={filters.store} onChange={(event) => set('store', event.target.value)} />
        <TextField type="date" label={t('ecommerce.fields.from')} value={filters.from} onChange={(event) => set('from', event.target.value)} />
        <TextField type="date" label={t('ecommerce.fields.to')} value={filters.to} onChange={(event) => set('to', event.target.value)} />
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {data ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title={t('ecommerce.reports.revenue')}>
            <DataTable
              caption={t('ecommerce.reports.revenue')}
              rows={data.revenue.map((row) => ({ ...row, id: row.currency }))}
              emptyText={t('ecommerce.reports.empty')}
              columns={[
                { key: 'orders', label: t('ecommerce.columns.orders'), align: 'end', render: (row) => <span className="tabular-nums">{row.orders}</span> },
                { key: 'total', label: t('ecommerce.columns.total'), align: 'end', render: (row) => <Money amount={row.total_minor} currency={row.currency} /> },
                { key: 'average', label: t('ecommerce.columns.average'), align: 'end', render: (row) => <Money amount={row.average_minor} currency={row.currency} /> },
                { key: 'fees', label: t('ecommerce.columns.deliveryFees'), align: 'end', render: (row) => <Money amount={row.delivery_fees_minor} currency={row.currency} /> },
              ]}
            />
          </Card>
          <Card title={t('ecommerce.reports.deliveries')}>
            <dl className="grid grid-cols-3 gap-4 text-body">
              {['delivered', 'failed', 'open'].map((key) => (
                <div key={key}>
                  <dt className="text-ink-muted">{t(`ecommerce.reports.deliveryCounts.${key}`)}</dt>
                  <dd className="text-h2 tabular-nums">{data.deliveries[key]}</dd>
                </div>
              ))}
            </dl>
          </Card>
          <Card title={t('ecommerce.reports.byChannel')}>{counted('channels', data.by_channel)}</Card>
          <Card title={t('ecommerce.reports.byPayment')}>{counted('paymentKinds', data.by_payment)}</Card>
          <Card title={t('ecommerce.reports.topProducts')}>
            <ul className="flex flex-col gap-1 text-body">
              {data.top_products.map((row) => (
                <li key={row.item_id} className="flex justify-between gap-3">
                  <span>{row.name}</span>
                  <span className="tabular-nums">{row.qty.replace(/\.?0+$/, '')}</span>
                </li>
              ))}
              {data.top_products.length ? null : <li className="text-ink-muted">—</li>}
            </ul>
          </Card>
          <Card title={t('ecommerce.reports.codOutstanding')}>
            <ul className="flex flex-col gap-1 text-body">
              {data.cod_outstanding.map((row) => (
                <li key={`${row.rider.id}-${row.currency}`} className="flex justify-between gap-3">
                  <span>{row.rider.name}</span>
                  <Money amount={row.due_minor} currency={row.currency} />
                </li>
              ))}
              {data.cod_outstanding.length ? null : <li className="text-ink-muted">—</li>}
            </ul>
          </Card>
        </div>
      ) : null}
    </>
  )
}
