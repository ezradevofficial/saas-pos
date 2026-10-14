import { useTranslation } from 'react-i18next'
import { Amount, CalendarDate, DocumentList, Status, When } from './ProcurementParts'
import { LANDED_COST_CREATE, PO_CREATE, REQUISITION_CREATE, RFQ_CREATE, STATUSES, TENDER_CREATE } from './procurementData'

// PRC-21, ADR 008: the module's lists. Column keys are the API list's
// (Http/Lists), so exports and saved views name the same columns.

/** The columns every document list starts with: number, created, status. */
function useBaseColumns() {
  const { t } = useTranslation()
  return [
    { key: 'number', label: t('procurement.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number ?? t('procurement.draftNumber')}</span> },
    { key: 'created_at', label: t('procurement.columns.createdAt'), sortKey: 'created_at', render: (row) => <When value={row.created_at} companyId={row.company?.id} /> },
    { key: 'status', label: t('procurement.columns.status'), sortKey: 'status', render: (row) => <Status status={row.status} /> },
  ]
}

/** PRC-02: requisitions with status, branch, requester and estimated total. */
export function Requisitions() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'branch', label: t('procurement.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'requested_by', label: t('procurement.columns.requestedBy'), render: (row) => row.requested_by?.name ?? '' },
    { key: 'purpose', label: t('procurement.columns.purpose'), wrap: true, render: (row) => row.purpose ?? '' },
    { key: 'estimated_total', label: t('procurement.columns.estimatedTotal'), align: 'end', render: (row) => <Amount value={row.estimated_total} /> },
  ]
  return <DocumentList id="procurement-requisitions" endpoint="procurement/requisitions" area="requisitions" statuses={STATUSES.requisition} columns={columns} newPath="/procurement/requisitions/new" createPermission={REQUISITION_CREATE} />
}

/** PRC-03: RFQs with title, branch, deadline and the quotes typed in. */
export function Rfqs() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'title', label: t('procurement.columns.title'), wrap: true, render: (row) => row.title },
    { key: 'branch', label: t('procurement.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'deadline_at', label: t('procurement.columns.deadline'), render: (row) => <When value={row.deadline_at} companyId={row.company?.id} /> },
    { key: 'quotes_count', label: t('procurement.columns.quotes'), align: 'end', numeric: true, render: (row) => row.quotes_count ?? 0 },
  ]
  return <DocumentList id="procurement-rfqs" endpoint="procurement/rfqs" area="rfqs" statuses={STATUSES.rfq} columns={columns} newPath="/procurement/rfqs/new" createPermission={RFQ_CREATE} />
}

/** PRC-06: tenders with kind, branch, deadline and opening time. */
export function Tenders() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'title', label: t('procurement.columns.title'), wrap: true, render: (row) => row.title },
    { key: 'kind', label: t('procurement.columns.kind'), render: (row) => t(`procurement.tenderKinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'branch', label: t('procurement.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'deadline_at', label: t('procurement.columns.deadline'), render: (row) => <When value={row.deadline_at} companyId={row.company?.id} /> },
    { key: 'opening_at', label: t('procurement.columns.opening'), render: (row) => <When value={row.opening_at} companyId={row.company?.id} /> },
  ]
  return <DocumentList id="procurement-tenders" endpoint="procurement/tenders" area="tenders" statuses={STATUSES.tender} columns={columns} newPath="/procurement/tenders/new" createPermission={TENDER_CREATE} />
}

/** PRC-13: purchase orders with supplier, branch, dates and totals. */
export function Orders() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'party', label: t('procurement.columns.supplier'), render: (row) => row.party?.name ?? '' },
    { key: 'branch', label: t('procurement.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'order_date', label: t('procurement.columns.orderDate'), render: (row) => <CalendarDate value={row.order_date} /> },
    { key: 'expected_on', label: t('procurement.columns.expectedOn'), render: (row) => <CalendarDate value={row.expected_on} /> },
    { key: 'total', label: t('procurement.columns.total'), align: 'end', render: (row) => <Amount value={row.total} /> },
    { key: 'base_total', label: t('procurement.columns.baseTotal'), align: 'end', defaultHidden: true, render: (row) => <Amount value={row.base_total} /> },
  ]
  return <DocumentList id="procurement-orders" endpoint="procurement/orders" area="orders" statuses={STATUSES.order} columns={columns} newPath="/procurement/orders/new" createPermission={PO_CREATE} party />
}

/** PRC-14: goods received notes with supplier, location, PO, time and value. */
export function Receipts() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'party', label: t('procurement.columns.supplier'), render: (row) => row.party?.name ?? '' },
    { key: 'location', label: t('procurement.columns.location'), render: (row) => row.location?.name ?? '' },
    { key: 'order', label: t('procurement.columns.order'), render: (row) => row.order?.number ?? '' },
    { key: 'received_at', label: t('procurement.columns.receivedAt'), render: (row) => <When value={row.received_at} companyId={row.company?.id} /> },
    { key: 'base_value', label: t('procurement.columns.baseValue'), align: 'end', render: (row) => <Amount value={row.base_value} /> },
  ]
  // A GRN starts from an approved PO (its detail's "Receive goods").
  return <DocumentList id="procurement-receipts" endpoint="procurement/receipts" area="receipts" statuses={STATUSES.receipt} columns={columns} places={['company', 'branch', 'location']} party />
}

/** PRC-15: supplier invoices with their reference, supplier, PO, date and total. */
export function Invoices() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'supplier_reference', label: t('procurement.columns.supplierReference'), render: (row) => row.supplier_reference },
    { key: 'party', label: t('procurement.columns.supplier'), render: (row) => row.party?.name ?? '' },
    { key: 'order', label: t('procurement.columns.order'), render: (row) => row.order?.number ?? '' },
    { key: 'invoice_date', label: t('procurement.columns.invoiceDate'), render: (row) => <CalendarDate value={row.invoice_date} /> },
    { key: 'total', label: t('procurement.columns.total'), align: 'end', render: (row) => <Amount value={row.total} /> },
  ]
  // An invoice starts from a PO (its detail's "Enter invoice").
  return <DocumentList id="procurement-invoices" endpoint="procurement/invoices" area="invoices" statuses={STATUSES.invoice} columns={columns} party />
}

/** PRC-17: landed costs with method, description, branch and base total. */
export function LandedCosts() {
  const { t } = useTranslation()
  const columns = [
    ...useBaseColumns(),
    { key: 'method', label: t('procurement.columns.method'), render: (row) => t(`procurement.methods.${row.method}`, { defaultValue: row.method }) },
    { key: 'description', label: t('procurement.columns.description'), wrap: true, render: (row) => row.description ?? '' },
    { key: 'branch', label: t('procurement.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'base_total', label: t('procurement.columns.baseTotal'), align: 'end', render: (row) => <Amount value={row.base_total} /> },
  ]
  return <DocumentList id="procurement-landed-costs" endpoint="procurement/landed-costs" area="landedCosts" path="landed-costs" statuses={STATUSES.landedCost} columns={columns} newPath="/procurement/landed-costs/new" createPermission={LANDED_COST_CREATE} />
}
