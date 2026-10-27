import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, FilterBar, Flags, FuelList, Litres, RecordStatus, StationSelect, When } from './FuelParts'
import { SETTLEMENT_STATUSES, useStations } from './fuelData'

/** FUEL-13, FUEL-14: attendants' settlements, the ones waiting for a decision first (the default filter). */
export default function Settlements() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ status: 'pending_approval', location_id: '', from: '', to: '' })
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  const columns = [
    { key: 'number', label: t('fuel.columns.number'), render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created', label: t('fuel.columns.createdAt'), render: (row) => <When value={row.created_at} /> },
    { key: 'attendant', label: t('fuel.columns.attendant'), render: (row) => row.attendant?.name ?? '' },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'expected', label: t('fuel.columns.expected'), align: 'end', render: (row) => <Amount minor={row.expected_minor} currency={row.currency} /> },
    { key: 'collected', label: t('fuel.columns.collected'), align: 'end', render: (row) => <Amount minor={row.collected_minor} currency={row.currency} /> },
    { key: 'variance', label: t('fuel.columns.variance'), align: 'end', render: (row) => <Amount minor={row.variance_minor} currency={row.currency} signed className="font-medium" /> },
    { key: 'litres', label: t('fuel.columns.litresVariance'), align: 'end', render: (row) => <Litres value={row.litres_variance} signed /> },
    { key: 'status', label: t('fuel.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
  return (
    <>
      <PageHeader title={t('fuel.settlements.title')} description={t('fuel.settlements.description')} />
      <FilterBar>
        <Select label={t('fuel.columns.status')} options={[{ value: '', label: t('fuel.filters.anyStatus') }, ...SETTLEMENT_STATUSES.map((value) => ({ value, label: t(`fuel.states.${value}`) }))]} value={filters.status} onChange={(event) => set('status')(event.target.value)} className="w-52" />
        <StationSelect all stations={stations} value={filters.location_id} onChange={set('location_id')} className="w-56" />
        <TextField type="date" label={t('fuel.filters.from')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
      </FilterBar>
      <FuelList
        queryKey="settlements"
        endpoint="fuel/settlements"
        params={filters}
        columns={columns}
        caption={t('fuel.settlements.title')}
        emptyText={filters.status === 'pending_approval' && !filters.location_id && !filters.from && !filters.to ? t('fuel.settlements.emptyPending') : t('fuel.settlements.empty')}
        onRowClick={(row) => navigate(`/fuel/settlements/${row.id}`)}
      />
    </>
  )
}
