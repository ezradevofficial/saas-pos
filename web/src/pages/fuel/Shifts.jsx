import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, FilterBar, Flags, FuelList, RecordStatus, StationSelect, When } from './FuelParts'
import { currencyAt, SHIFT_STATUSES, useStations } from './fuelData'

/** FUEL-09: the shifts at the stations the user sees, newest first, with station, status and period filters. */
export default function Shifts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ location_id: '', status: '', from: '', to: '' })
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  const columns = [
    { key: 'opened', label: t('fuel.columns.openedAt'), render: (row) => <span className="font-medium text-ink"><When value={row.opened_at} /></span> },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'status', label: t('fuel.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
    { key: 'openedBy', label: t('fuel.columns.openedBy'), render: (row) => row.opened_by?.name ?? '' },
    { key: 'closed', label: t('fuel.columns.closedAt'), render: (row) => <When value={row.closed_at} /> },
    { key: 'count', label: t('fuel.columns.attendants'), align: 'end', numeric: true, render: (row) => row.settlements_count },
    { key: 'variance', label: t('fuel.columns.variance'), align: 'end', render: (row) => (row.settlements_count ? <Amount minor={row.variance_minor} currency={currencyAt(stations, row.location?.id)} signed /> : null) },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
  return (
    <>
      <PageHeader title={t('fuel.shifts.title')} description={t('fuel.shifts.description')} />
      <FilterBar>
        <StationSelect all stations={stations} value={filters.location_id} onChange={set('location_id')} className="w-56" />
        <Select label={t('fuel.columns.status')} options={[{ value: '', label: t('fuel.filters.anyStatus') }, ...SHIFT_STATUSES.map((value) => ({ value, label: t(`fuel.states.${value}`) }))]} value={filters.status} onChange={(event) => set('status')(event.target.value)} className="w-44" />
        <TextField type="date" label={t('fuel.filters.from')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
      </FilterBar>
      <FuelList
        queryKey="shifts"
        endpoint="fuel/shifts"
        params={filters}
        columns={columns}
        caption={t('fuel.shifts.title')}
        emptyText={Object.values(filters).some(Boolean) ? t('fuel.shifts.emptyFiltered') : t('fuel.shifts.empty')}
        onRowClick={(row) => navigate(`/fuel/shifts/${row.id}`)}
      />
    </>
  )
}
