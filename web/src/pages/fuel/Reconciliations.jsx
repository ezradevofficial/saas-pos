import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { FilterBar, FuelList, StationSelect, TankSelect } from './FuelParts'
import { RECONCILIATION_STATUSES, useStations, useTanks } from './fuelData'
import { reconciliationColumns } from './reconciliationColumns'

/** FUEL-24, FUEL-26: tank reconciliations, the ones waiting for a decision first (the default filter). */
export default function Reconciliations() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ status: 'pending_approval', location_id: '', tank_id: '', from: '', to: '' })
  const { tanks } = useTanks(filters.location_id)
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  return (
    <>
      <PageHeader title={t('fuel.reconciliations.title')} description={t('fuel.reconciliations.description')} />
      <FilterBar>
        <Select label={t('fuel.columns.status')} options={[{ value: '', label: t('fuel.filters.anyStatus') }, ...RECONCILIATION_STATUSES.map((value) => ({ value, label: t(`fuel.states.${value}`) }))]} value={filters.status} onChange={(event) => set('status')(event.target.value)} className="w-52" />
        <StationSelect all stations={stations} value={filters.location_id} onChange={(value) => setFilters((current) => ({ ...current, location_id: value, tank_id: '' }))} className="w-56" />
        <TankSelect all tanks={tanks} value={filters.tank_id} onChange={set('tank_id')} className="w-56" />
        <TextField type="date" label={t('fuel.filters.from')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
      </FilterBar>
      <FuelList
        queryKey="reconciliations"
        endpoint="fuel/reconciliations"
        params={filters}
        columns={reconciliationColumns(t)}
        caption={t('fuel.reconciliations.title')}
        emptyText={filters.status === 'pending_approval' && !filters.location_id && !filters.tank_id && !filters.from && !filters.to ? t('fuel.reconciliations.emptyPending') : t('fuel.reconciliations.empty')}
        onRowClick={(row) => navigate(`/fuel/reconciliations/${row.id}`)}
      />
    </>
  )
}
