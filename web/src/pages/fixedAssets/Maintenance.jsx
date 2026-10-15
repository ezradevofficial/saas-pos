import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { Card, DataTable, ListView, Tabs } from '@/components/ds'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { Amount, CalendarDate, FaStatus } from './FixedAssetsParts'
import { faKey, statusLabel, WORK_ORDER_STATUSES } from './fixedAssetsData'
import { FormDialog } from './FormDialog'

/**
 * FA-19: maintenance across the assets the user may maintain: work orders
 * (opened from an asset; updated and completed here, with the reading and
 * the cost) and the schedules by their next due date.
 */
export default function Maintenance() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const money = useMoneyDefaults()
  const [tab, setTab] = useState('orders')
  const [order, setOrder] = useState(null)
  const schedules = useQuery({ queryKey: [...faKey, 'schedules'], queryFn: () => api.get('fixed-assets/schedules?per_page=200'), enabled: tab === 'schedules' })
  const asset = (row) => (
    <Link to={`/fixed-assets/assets/${row.asset?.id}`} className="text-primary hover:text-primary-hover" onClick={(event) => event.stopPropagation()}>
      {row.asset?.number} · {row.asset?.name}
    </Link>
  )
  const columns = [
    { key: 'number', label: t('fixedAssets.columns.number'), hideable: false, render: (row) => <span className="font-medium tabular-nums">{row.number}</span> },
    { key: 'title', label: t('fixedAssets.maintenance.title'), render: (row) => row.title },
    { key: 'asset', label: t('fixedAssets.columns.asset'), render: asset },
    { key: 'status', label: t('fixedAssets.columns.status'), render: (row) => <FaStatus status={row.status} /> },
    { key: 'due_on', label: t('fixedAssets.maintenance.due'), render: (row) => <CalendarDate value={row.due_on} /> },
    { key: 'cost', label: t('fixedAssets.maintenance.cost'), align: 'end', render: (row) => <Amount value={row.cost} /> },
  ]
  const list = useServerList({ id: 'fixed-assets-work-orders', endpoint: 'fixed-assets/work-orders', queryKey: [...faKey, 'work-orders'], filters: { status: '' }, columns })

  return (
    <>
      <PageHeader title={t('fixedAssets.maintenance.pageTitle')} description={t('fixedAssets.maintenance.description')} />
      <div className="flex flex-col gap-5">
        <Tabs value={tab} onChange={setTab} items={['orders', 'schedules'].map((value) => ({ value, label: t(`fixedAssets.maintenance.tabs.${value}`) }))} />
        {tab === 'orders' ? (
          <ListView
            list={list}
            title={t('fixedAssets.maintenance.orders')}
            searchLabel={t('fixedAssets.maintenance.search')}
            filterFields={[{ name: 'status', label: t('fixedAssets.filters.status'), options: [{ value: '', label: t('fixedAssets.filters.allStatuses') }, ...WORK_ORDER_STATUSES.map((value) => ({ value, label: statusLabel(t, value) }))] }]}
            emptyText={t('fixedAssets.maintenance.noOrders')}
            onRowClick={(row) => (row.can?.edit ? setOrder(row) : null)}
          />
        ) : (
          <Card title={t('fixedAssets.maintenance.schedules')}>
            <DataTable
              caption={t('fixedAssets.maintenance.schedules')}
              columns={[
                { key: 'asset', label: t('fixedAssets.columns.asset'), render: asset },
                { key: 'name', label: t('fixedAssets.maintenance.name'), render: (row) => row.name },
                { key: 'basis', label: t('fixedAssets.maintenance.basis'), render: (row) => t(`fixedAssets.maintenance.bases.${row.basis}`) },
                { key: 'next', label: t('fixedAssets.maintenance.nextDue'), render: (row) => (row.basis === 'time' ? <CalendarDate value={row.next_due_on} /> : <span className="tabular-nums">{row.next_due_reading}</span>) },
              ]}
              rows={schedules.data?.data ?? []}
              loading={schedules.isPending}
              emptyText={t('fixedAssets.maintenance.noSchedules')}
            />
          </Card>
        )}
      </div>
      {order ? (
        <FormDialog
          open
          title={t('fixedAssets.maintenance.updateTitle', { number: order.number })}
          confirm={t('fixedAssets.maintenance.saveOrder')}
          method="patch"
          path={`fixed-assets/work-orders/${order.id}`}
          initial={{ status: order.status, completed_on: '', reading: '', cost_minor: '', currency: money.currency }}
          fields={[
            { name: 'status', label: t('fixedAssets.columns.status'), type: 'select', options: WORK_ORDER_STATUSES.map((value) => ({ value, label: statusLabel(t, value) })) },
            { name: 'completed_on', label: t('fixedAssets.maintenance.completedOn'), type: 'date', hidden: (values) => values.status !== 'done' },
            { name: 'reading', label: t('fixedAssets.readings.reading'), type: 'decimal', hidden: (values) => values.status !== 'done' },
            { name: 'currency', label: t('fixedAssets.fields.currency'), type: 'select', options: money.options.map((code) => ({ value: code, label: code })) },
            { name: 'cost_minor', label: t('fixedAssets.maintenance.cost'), type: 'money', currency: (values) => values.currency },
          ]}
          toBody={(values) => ({
            status: values.status,
            ...(values.status === 'done' && values.completed_on ? { completed_on: values.completed_on } : {}),
            ...(values.status === 'done' && values.reading ? { reading: values.reading } : {}),
            ...(values.cost_minor !== '' ? { cost_minor: String(values.cost_minor), currency: values.currency } : {}),
          })}
          onClose={() => setOrder(null)}
          onDone={async () => {
            setOrder(null)
            toast.success(t('fixedAssets.maintenance.saved'))
            await queryClient.invalidateQueries({ queryKey: faKey })
          }}
        />
      ) : null}
    </>
  )
}
