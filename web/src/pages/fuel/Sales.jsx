import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Checkbox, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, FilterBar, Flags, FuelList, Litres, ProductSelect, StationSelect, When } from './FuelParts'
import { productLabel, useProducts, useStations } from './fuelData'

/** FUEL-18, FUEL-19, FUEL-20: the fuel sales, with the flags that need a look (no shift, price differs, vehicle unknown ...). */
export default function Sales() {
  const { t } = useTranslation()
  const { stations } = useStations()
  const { products } = useProducts()
  const [filters, setFilters] = useState({ location_id: '', product_id: '', from: '', to: '', flagged: '' })
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  const columns = [
    { key: 'sold', label: t('fuel.columns.soldAt'), render: (row) => <span className="font-medium text-ink"><When value={row.sold_at} /></span> },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'nozzle', label: t('fuel.fields.nozzle'), render: (row) => row.nozzle?.label ?? '' },
    { key: 'product', label: t('fuel.fields.product'), render: (row) => productLabel(row.product) },
    { key: 'attendant', label: t('fuel.columns.attendant'), render: (row) => row.attendant?.name ?? '' },
    { key: 'litres', label: t('fuel.columns.litres'), align: 'end', render: (row) => <Litres value={row.litres} /> },
    { key: 'price', label: t('fuel.prices.perLitre'), align: 'end', render: (row) => <Amount minor={row.unit_price_minor} currency={row.currency} /> },
    { key: 'total', label: t('fuel.columns.total'), align: 'end', render: (row) => <Amount minor={row.total_minor} currency={row.currency} className="font-medium text-ink" /> },
    { key: 'customer', label: t('fuel.columns.customer'), render: (row) => [row.customer?.name, row.vehicle_registration].filter(Boolean).join(' · ') },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => (row.voided_at ? <span className="text-ink-muted">{t('fuel.sales.voided')}</span> : <Flags flags={row.flags} />) },
  ]
  return (
    <>
      <PageHeader title={t('fuel.sales.title')} description={t('fuel.sales.description')} />
      <FilterBar>
        <StationSelect all stations={stations} value={filters.location_id} onChange={set('location_id')} className="w-56" />
        <ProductSelect all products={products} value={filters.product_id} onChange={set('product_id')} className="w-56" />
        <TextField type="date" label={t('fuel.filters.from')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
        <Checkbox label={t('fuel.sales.flaggedOnly')} checked={filters.flagged === '1'} onChange={(event) => set('flagged')(event.target.checked ? '1' : '')} />
      </FilterBar>
      <FuelList
        queryKey="sales"
        endpoint="fuel/sales"
        params={filters}
        columns={columns}
        caption={t('fuel.sales.title')}
        emptyText={Object.values(filters).some(Boolean) ? t('fuel.sales.emptyFiltered') : t('fuel.sales.empty')}
      />
    </>
  )
}
