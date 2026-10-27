import { Flags, Litres, RecordStatus, When } from './FuelParts'

/** The columns of a tank reconciliation table (the list and the tank loss and gain report). */
export function reconciliationColumns(t) {
  return [
    { key: 'number', label: t('fuel.columns.number'), render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'created', label: t('fuel.columns.createdAt'), render: (row) => <When value={row.created_at} /> },
    { key: 'tank', label: t('fuel.fields.tank'), render: (row) => (row.tank ? `${row.tank.code} · ${row.tank.name}` : '') },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'expected', label: t('fuel.columns.expectedLitres'), align: 'end', render: (row) => <Litres value={row.expected_litres} /> },
    { key: 'dip', label: t('fuel.columns.dipLitres'), align: 'end', render: (row) => <Litres value={row.dip_litres} /> },
    { key: 'variance', label: t('fuel.columns.varianceLitres'), align: 'end', render: (row) => <Litres value={row.variance_litres} signed className="font-medium" /> },
    { key: 'status', label: t('fuel.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
}
