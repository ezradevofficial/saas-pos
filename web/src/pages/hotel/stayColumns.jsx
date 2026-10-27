import { Amount, Day, Stamp, StateBadge } from './HotelParts'

/**
 * The columns of the stay lists (reservations, arrivals, departures, in-house,
 * no-shows). Keys are the API list's column keys, so an export carries them
 * (HOT-31, EXP-01).
 */
export function stayColumns(t) {
  return [
    { key: 'reservation', label: t('hotel.columns.reservation'), hideable: false, render: (row) => <span className="font-mono">{row.reservation_number}</span> },
    { key: 'guest', label: t('hotel.columns.guest'), render: (row) => <span className="font-medium text-ink">{row.guest?.name}</span> },
    { key: 'room', label: t('hotel.columns.room'), render: (row) => row.room?.number ?? <span className="text-ink-muted">—</span> },
    { key: 'room_type', label: t('hotel.columns.room_type'), render: (row) => row.room_type?.name ?? '' },
    { key: 'arrival_on', label: t('hotel.columns.arrival_on'), sortKey: 'arrival_on', render: (row) => <Day value={row.arrival_on} /> },
    { key: 'departure_on', label: t('hotel.columns.departure_on'), sortKey: 'departure_on', render: (row) => <Day value={row.departure_on} /> },
    { key: 'nights', label: t('hotel.columns.nights'), align: 'end', render: (row) => <span className="tabular-nums">{row.nights}</span> },
    { key: 'party', label: t('hotel.columns.party'), render: (row) => <span className="tabular-nums">{`${row.adults}+${row.children}`}</span> },
    { key: 'board', label: t('hotel.columns.board'), render: (row) => t(`hotel.boards.${row.board}`) },
    { key: 'status', label: t('hotel.columns.status'), sortKey: 'status', render: (row) => <StateBadge status={row.status} /> },
    { key: 'total_minor', label: t('hotel.columns.total_minor'), align: 'end', sortKey: 'total', exportKey: 'total_minor', render: (row) => <Amount minor={row.total_minor} currency={row.currency} /> },
  ]
}

/** The columns of the folio lists (folios, folio balances report). */
export function folioColumns(t) {
  return [
    { key: 'number', label: t('hotel.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-mono">{row.number}</span> },
    { key: 'reservation', label: t('hotel.columns.reservation'), render: (row) => row.reservation_number ?? '' },
    { key: 'guest', label: t('hotel.columns.guest'), render: (row) => <span className="font-medium text-ink">{row.guest?.name}</span> },
    { key: 'room', label: t('hotel.columns.room'), render: (row) => row.room_number ?? '' },
    { key: 'kind', label: t('hotel.columns.kind'), render: (row) => t(`hotel.kinds.${row.kind}`) },
    { key: 'status', label: t('hotel.columns.status'), sortKey: 'status', render: (row) => <StateBadge status={row.status} /> },
    { key: 'charges_minor', label: t('hotel.columns.charges_minor'), align: 'end', render: (row) => <Amount minor={row.charges_minor} currency={row.currency} /> },
    { key: 'payments_minor', label: t('hotel.columns.payments_minor'), align: 'end', render: (row) => <Amount minor={row.payments_minor} currency={row.currency} /> },
    { key: 'balance_minor', label: t('hotel.columns.balance_minor'), align: 'end', render: (row) => <Amount minor={row.balance_minor} currency={row.currency} tone={BigInt(row.balance_minor) > 0n ? 'danger' : undefined} /> },
    { key: 'invoice_number', label: t('hotel.columns.invoice_number'), render: (row) => row.invoice_number ?? '' },
  ]
}

/** The columns of the housekeeping task lists (the housekeeping report). */
export function taskColumns(t) {
  return [
    { key: 'business_date', label: t('hotel.columns.business_date'), sortKey: 'business_date', hideable: false, render: (row) => <Day value={row.business_date} /> },
    { key: 'room', label: t('hotel.columns.room'), render: (row) => <span className="font-medium text-ink">{row.room?.number}</span> },
    { key: 'floor', label: t('hotel.columns.floor'), render: (row) => row.room?.floor ?? '' },
    { key: 'kind', label: t('hotel.columns.kind'), sortKey: 'kind', render: (row) => t(`hotel.taskKinds.${row.kind}`) },
    { key: 'status', label: t('hotel.columns.status'), sortKey: 'status', render: (row) => <StateBadge status={row.status} /> },
    { key: 'assigned_to', label: t('hotel.columns.assigned_to'), render: (row) => row.assigned_to?.name ?? <span className="text-ink-muted">—</span> },
    { key: 'started_at', label: t('hotel.columns.started_at'), render: (row) => <Stamp value={row.started_at} /> },
    { key: 'finished_at', label: t('hotel.columns.finished_at'), sortKey: 'finished_at', render: (row) => <Stamp value={row.finished_at} /> },
  ]
}
