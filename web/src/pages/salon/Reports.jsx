import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { DataTable, KpiTile, Money, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { PlaceBar, QueryNotes } from './SalonParts'
import { dayIn, queryString, salonKey, useSalonPlace } from './salonData'

const percent = (value) => (value == null ? '—' : `${value}%`)

/** SAL-27: utilisation, revenue per service, no-shows, commissions and tips, and what the salon owes in prepaid value. */
export default function Reports() {
  const { t } = useTranslation()
  const place = useSalonPlace()
  const companyId = place.company?.id
  const zone = place.company?.timezone
  const [tab, setTab] = useState('utilisation')
  const [range, setRange] = useState(() => ({ from: dayIn(new Date(Date.now() - 29 * 86400000), zone), to: dayIn(new Date(), zone) }))
  const path = { utilisation: 'utilisation', services: 'services', noshows: 'no-shows', commissions: 'commissions', liability: 'liability' }[tab]
  const query = useQuery({
    queryKey: [...salonKey, 'report', tab, companyId, range],
    queryFn: () => api.get(`salon/reports/${path}${queryString({ company_id: companyId, ...(tab === 'liability' ? {} : range) })}`),
    enabled: Boolean(companyId),
  })
  const data = query.data?.data
  const minutes = (value) => t('salon.reports.minutes', { count: value })
  const tables = {
    utilisation: (
      <DataTable
        caption={t('salon.reports.utilisation')}
        columns={[
          { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name },
          { key: 'branch', label: t('salon.fields.branch'), render: (row) => row.branch_name },
          { key: 'available', label: t('salon.reports.available'), align: 'end', render: (row) => minutes(row.available_minutes) },
          { key: 'booked', label: t('salon.reports.booked'), align: 'end', render: (row) => minutes(row.booked_minutes) },
          { key: 'served', label: t('salon.reports.served'), align: 'end', render: (row) => minutes(row.served_minutes) },
          { key: 'rate', label: t('salon.reports.utilisationRate'), align: 'end', render: (row) => percent(row.utilisation_percent) },
        ]}
        rows={data?.staff ?? []}
        emptyText={t('salon.reports.empty')}
      />
    ),
    services: (
      <div className="flex flex-col gap-4">
        {Object.entries(data?.currencies ?? {}).map(([currency, rows]) => (
          <DataTable
            key={currency}
            caption={t('salon.reports.servicesIn', { currency })}
            columns={[
              { key: 'service', label: t('salon.reports.service'), render: (row) => row.service_name },
              { key: 'done', label: t('salon.reports.done'), align: 'end', render: (row) => row.done },
              { key: 'revenue', label: t('salon.reports.revenue'), align: 'end', render: (row) => <Money amount={row.revenue_minor} currency={currency} /> },
              { key: 'list', label: t('salon.reports.listValue'), align: 'end', render: (row) => <Money amount={row.list_minor} currency={currency} /> },
            ]}
            rows={rows}
          />
        ))}
        {data && !Object.keys(data.currencies).length ? <p className="text-ink-muted">{t('salon.reports.empty')}</p> : null}
      </div>
    ),
    noshows: data ? (
      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <KpiTile label={t('salon.reports.kept')} value={String(data.total.kept)} />
          <KpiTile label={t('salon.reports.noShows')} value={String(data.total.no_shows)} />
          <KpiTile label={t('salon.reports.cancellations')} value={String(data.total.cancellations)} />
          <KpiTile label={t('salon.reports.noShowRate')} value={percent(data.total.no_show_rate_percent)} />
        </div>
        <DataTable
          caption={t('salon.reports.noShowsByStaff')}
          columns={[
            { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name },
            { key: 'kept', label: t('salon.reports.kept'), align: 'end', render: (row) => row.kept },
            { key: 'no', label: t('salon.reports.noShows'), align: 'end', render: (row) => row.no_shows },
            { key: 'cancelled', label: t('salon.reports.cancellations'), align: 'end', render: (row) => row.cancellations },
            { key: 'rate', label: t('salon.reports.noShowRate'), align: 'end', render: (row) => percent(row.no_show_rate_percent) },
          ]}
          rows={data.staff}
          emptyText={t('salon.reports.empty')}
        />
      </div>
    ) : null,
    commissions: (
      <DataTable
        caption={t('salon.reports.commissions')}
        columns={[
          { key: 'staff', label: t('salon.commissions.staff'), render: (row) => row.staff_name },
          { key: 'earned', label: t('salon.reports.earned'), align: 'end', render: (row) => <Money amount={row.earned_minor} currency={row.currency} /> },
          { key: 'reversed', label: t('salon.reports.reversed'), align: 'end', render: (row) => <Money amount={row.reversed_minor} currency={row.currency} /> },
          { key: 'net', label: t('salon.reports.net'), align: 'end', render: (row) => <Money amount={row.net_minor} currency={row.currency} /> },
          { key: 'tips', label: t('salon.commissions.tips'), align: 'end', render: (row) => <Money amount={row.tips_minor} currency={row.currency} /> },
        ]}
        rows={data?.staff ?? []}
        emptyText={t('salon.reports.empty')}
      />
    ),
    liability: (
      <div className="flex flex-col gap-4">
        {Object.entries(data?.currencies ?? {}).map(([currency, row]) => (
          <div key={currency} className="grid grid-cols-2 gap-3 md:grid-cols-3">
            <KpiTile label={t('salon.reports.packageValue', { currency })} value={<Money amount={row.value_minor} currency={currency} />} />
            <KpiTile label={t('salon.reports.sessionsLeft')} value={String(row.sessions_left)} />
            <KpiTile label={t('salon.reports.expiringValue')} value={<Money amount={row.expiring_value_minor} currency={currency} />} />
            <KpiTile label={t('salon.reports.expiredValue')} value={<Money amount={row.expired_value_minor} currency={currency} />} />
            <KpiTile label={t('salon.reports.depositsHeld')} value={<Money amount={row.deposits_minor} currency={currency} />} />
          </div>
        ))}
        {data && !Object.keys(data.currencies).length ? <p className="text-ink-muted">{t('salon.reports.empty')}</p> : null}
      </div>
    ),
  }
  return (
    <>
      <PageHeader title={t('salon.reports.title')} description={t('salon.reports.description')} />
      <div className="flex flex-col gap-5">
        <PlaceBar place={place} branch={false} />
        <Tabs
          items={['utilisation', 'services', 'noshows', 'commissions', 'liability'].map((value) => ({ value, label: t(`salon.reports.tabs.${value}`) }))}
          value={tab}
          onChange={setTab}
        />
        {tab !== 'liability' ? (
          <div className="flex flex-wrap items-end gap-4">
            <TextField label={t('salon.reports.from')} type="date" value={range.from} onChange={(event) => setRange((current) => ({ ...current, from: event.target.value }))} />
            <TextField label={t('salon.reports.to')} type="date" value={range.to} onChange={(event) => setRange((current) => ({ ...current, to: event.target.value }))} />
          </div>
        ) : null}
        <QueryNotes query={query} />
        {data ? tables[tab] : null}
      </div>
    </>
  )
}
