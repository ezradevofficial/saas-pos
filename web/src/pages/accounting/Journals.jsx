import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Button, ListView, StatusBadge } from '@/components/ds'
import { usePermissions } from '@/auth/usePermissions'
import { PageHeader } from '@/layouts/PageHeader'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/pos/posFields'
import { Amount, NoCompany } from './AccountingParts'
import { companyPath, JOURNAL_TONES, useAccountingCompany } from './accountingData'

const STATUSES = ['draft', 'pending', 'approved', 'posted', 'rejected', 'cancelled']
const KINDS = ['manual', 'system', 'recurring', 'reversal', 'revaluation', 'closing', 'opening']

function JournalList({ company }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const columns = [
    { key: 'date', label: t('accounting.columns.date'), sortKey: 'date', hideable: false, render: (row) => <span className="tabular-nums">{row.date}</span> },
    { key: 'number', label: t('accounting.columns.number'), sortKey: 'number', render: (row) => row.number ?? <span className="text-ink-muted">{t('accounting.journals.unnumbered')}</span> },
    { key: 'kind', label: t('accounting.columns.kind'), render: (row) => t(`accounting.journalKinds.${row.kind}`) },
    { key: 'status', label: t('accounting.columns.status'), render: (row) => <StatusBadge tone={JOURNAL_TONES[row.status]}>{t(`accounting.journalStatuses.${row.status}`)}</StatusBadge> },
    { key: 'memo', label: t('accounting.columns.memo'), render: (row) => row.memo ?? row.reference ?? '' },
    { key: 'total', label: t('accounting.columns.total'), sortKey: 'total', align: 'end', render: (row) => <Amount value={row.total} /> },
  ]
  const list = useServerList({
    id: 'accounting-journals',
    endpoint: companyPath(company, 'journals'),
    queryKey: ['accounting-journals', company.id],
    filters: { status: '', kind: '', from: '', to: '' },
    defaultSort: '-date',
    columns,
  })

  return (
    <ListView
      list={list}
      title={t('accounting.journals.title')}
      searchLabel={t('accounting.journals.search')}
      filterFields={[
        { name: 'status', label: t('accounting.columns.status'), options: [{ value: '', label: t('accounting.all') }, ...STATUSES.map((value) => ({ value, label: t(`accounting.journalStatuses.${value}`) }))] },
        { name: 'kind', label: t('accounting.columns.kind'), options: [{ value: '', label: t('accounting.all') }, ...KINDS.map((value) => ({ value, label: t(`accounting.journalKinds.${value}`) }))] },
        dateField('from', t('accounting.from')),
        dateField('to', t('accounting.to')),
      ]}
      emptyText={t('accounting.journals.empty')}
      onRowClick={(row) => navigate(`/accounting/journals/${row.id}`)}
    />
  )
}

/** ACC-03, ACC-04: the company's journals, newest first, with a new manual journal for those who may draft one. */
export default function Journals() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useAccountingCompany()

  return (
    <>
      <PageHeader
        title={t('accounting.journals.title')}
        description={t('accounting.journals.description')}
        actions={can('accounting.journal.create') ? <Button variant="primary" icon="plus" onClick={() => navigate('/accounting/journals/new')}>{t('accounting.journals.new')}</Button> : null}
      />
      {company ? <JournalList company={company} /> : <NoCompany />}
    </>
  )
}
