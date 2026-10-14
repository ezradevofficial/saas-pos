import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { usePermissions } from '@/auth/usePermissions'
import { Button, ListView, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/pos/posFields'
import { Amount, CalendarDate, NoCompany } from './AccountingParts'
import { companyPath, DOCUMENT_TONES, useAccountingCompany } from './accountingData'

const STATUSES = ['draft', 'posted', 'open', 'cancelled']

function DocumentList({ company, side }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const columns = [
    { key: 'issue_date', label: t('accounting.columns.issueDate'), sortKey: 'issue_date', hideable: false, render: (row) => <CalendarDate value={row.issue_date} /> },
    { key: 'number', label: t('accounting.columns.number'), sortKey: 'number', render: (row) => row.number ?? <span className="text-ink-muted">{t('accounting.documents.unnumbered')}</span> },
    { key: 'kind', label: t('accounting.columns.kind'), render: (row) => t(`accounting.documentKinds.${row.kind}`) },
    { key: 'party', label: t('accounting.columns.party'), render: (row) => row.party?.name ?? '' },
    { key: 'due_date', label: t('accounting.columns.dueDate'), sortKey: 'due_date', render: (row) => <CalendarDate value={row.due_date} /> },
    { key: 'status', label: t('accounting.columns.status'), render: (row) => <StatusBadge tone={DOCUMENT_TONES[row.status]}>{t(`accounting.documentStatuses.${row.status}`)}</StatusBadge> },
    { key: 'total', label: t('accounting.columns.total'), sortKey: 'total', align: 'end', render: (row) => <Amount value={row.total} /> },
    { key: 'open', label: t('accounting.columns.open'), align: 'end', render: (row) => (row.status === 'posted' ? <Amount value={row.open} /> : null) },
  ]
  const list = useServerList({
    id: 'accounting-documents',
    endpoint: companyPath(company, 'documents'),
    params: { kind: side },
    queryKey: ['accounting-documents', company.id, side],
    filters: { status: '', from: '', to: '' },
    defaultSort: '-issue_date',
    columns,
  })

  return (
    <ListView
      list={list}
      title={t(`accounting.documents.${side}Title`)}
      searchLabel={t('accounting.documents.search')}
      filterFields={[
        { name: 'status', label: t('accounting.columns.status'), options: [{ value: '', label: t('accounting.all') }, ...STATUSES.map((value) => ({ value, label: t(`accounting.documentStatuses.${value}`) }))] },
        dateField('from', t('accounting.from')),
        dateField('to', t('accounting.to')),
      ]}
      emptyText={t('accounting.documents.empty')}
      onRowClick={(row) => navigate(`/accounting/documents/${row.id}`)}
    />
  )
}

/** ACC-09: customer invoices and credit notes, supplier bills and credits, of the company. */
export default function Documents() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [params, setParams] = useSearchParams()
  const company = useAccountingCompany()
  const side = params.get('side') === 'supplier' ? 'supplier' : 'customer'

  return (
    <>
      <PageHeader
        title={t('accounting.documents.title')}
        description={t('accounting.documents.description')}
        actions={can('accounting.invoice.create') ? <Button variant="primary" icon="plus" onClick={() => navigate(`/accounting/documents/new?side=${side}`)}>{t(`accounting.documents.new.${side}`)}</Button> : null}
      />
      <Tabs
        value={side}
        onChange={(next) => setParams({ side: next })}
        items={[{ value: 'customer', label: t('accounting.documents.customerTitle') }, { value: 'supplier', label: t('accounting.documents.supplierTitle') }]}
      />
      {company ? <DocumentList key={side} company={company} side={side} /> : <NoCompany />}
    </>
  )
}
