import { CalendarDate, LotState, Qty } from './PharmacyParts'

/**
 * The columns of the stock-by-lot lists. `report` picks the slow-mover
 * variant (last sale and days since it).
 */
export function lotColumns(t, report = 'lots') {
  const item = { key: 'item', label: t('pharmacy.columns.item'), hideable: false, sortKey: 'item', wrap: true, render: (row) => (
    <span className="flex flex-col">
      <span className="text-ink">{row.item?.name}</span>
      <span className="font-mono text-caption text-ink-muted">{row.item?.code}</span>
    </span>
  ) }
  const batch = { key: 'batch_number', label: t('pharmacy.columns.batch_number'), render: (row) => <span className="font-medium text-ink">{row.batch_number}</span> }
  const expires = { key: 'expires_on', label: t('pharmacy.columns.expires_on'), sortKey: 'expires_on', render: (row) => <CalendarDate value={row.expires_on} /> }
  const qty = { key: 'qty', label: t('pharmacy.columns.qty'), align: 'end', sortKey: 'qty', render: (row) => <Qty value={row.qty} /> }
  const location = { key: 'location', label: t('pharmacy.columns.location'), render: (row) => row.location?.name ?? '' }
  if (report === 'slow') {
    return [
      item,
      batch,
      expires,
      qty,
      { key: 'last_sold_at', label: t('pharmacy.columns.last_sold_at'), sortKey: 'last_sold_at', render: (row) => (row.last_sold_at ? <CalendarDate value={row.last_sold_at.slice(0, 10)} /> : <span className="text-ink-muted">{t('pharmacy.neverSold')}</span>) },
      { key: 'days_since_sale', label: t('pharmacy.columns.days_since_sale'), align: 'end', render: (row) => <Qty value={row.days_since_sale === null || row.days_since_sale === undefined ? null : String(row.days_since_sale)} /> },
      location,
    ]
  }
  return [
    item,
    batch,
    expires,
    { key: 'days_left', label: t('pharmacy.columns.days_left'), align: 'end', render: (row) => <Qty value={String(row.days_left)} /> },
    { key: 'state', label: t('pharmacy.columns.state'), render: (row) => <LotState state={row.state} days={row.days_left} /> },
    qty,
    location,
  ]
}
