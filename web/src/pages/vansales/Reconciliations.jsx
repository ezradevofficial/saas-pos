import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { Alert, DataTable, Money, Select } from '@/components/ds'
import { errorMessage } from '@/api/errorMessage'
import { PageHeader } from '@/layouts/PageHeader'
import { Pager, Status, When } from './VanSalesParts'
import { isNonZero, statusLabel, usePagedList } from './vansalesData'
import { formatCalendarDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'

const STATUSES = ['pending', 'balanced', 'approved', 'rejected']
const rank = (row) => (row.status === 'pending' ? 0 : 1)

/** VAN-19 to VAN-22: the end-of-day counts, those waiting for a decision first. */
export default function Reconciliations() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const [status, setStatus] = useState('')
  const { query, rows, meta, page, setPage } = usePagedList('reconciliations', 'van-sales/reconciliations', { status })
  const sorted = [...rows].sort((a, b) => rank(a) - rank(b))
  const columns = [
    { key: 'date', label: t('vansales.runs.date'), render: (row) => formatCalendarDate(row.run_date, locale) },
    { key: 'van', label: t('vansales.runs.van'), render: (row) => row.van_name },
    { key: 'rep', label: t('vansales.runs.rep'), render: (row) => row.rep_name },
    { key: 'attempt', label: t('vansales.reconciliations.attempt'), numeric: true, render: (row) => row.attempt },
    { key: 'status', label: t('vansales.fields.status'), render: (row) => <Status status={row.status} /> },
    { key: 'stock', label: t('vansales.reconciliations.stockVariance'), numeric: true, align: 'end', render: (row) => row.stock_variance_lines },
    {
      key: 'cash',
      label: t('vansales.reconciliations.cashVariance'),
      align: 'end',
      render: (row) => {
        const off = (row.tenders ?? []).filter((tender) => isNonZero(tender.variance_minor))
        return off.length ? (
          <span className="flex flex-col">
            {off.map((tender) => (
              <Money key={`${tender.kind}-${tender.currency}`} amount={tender.variance_minor} currency={tender.currency} tone="danger" />
            ))}
          </span>
        ) : (
          <span className="text-ink-muted">—</span>
        )
      },
    },
    { key: 'submitted', label: t('vansales.reconciliations.submittedAt'), render: (row) => <When value={row.submitted_at} /> },
  ]
  return (
    <>
      <PageHeader title={t('vansales.reconciliations.title')} description={t('vansales.reconciliations.description')} />
      <div className="flex flex-col gap-5">
        <Select label={t('vansales.fields.status')} options={[{ value: '', label: t('vansales.reconciliations.allStatuses') }, ...STATUSES.map((entry) => ({ value: entry, label: statusLabel(t, entry) }))]} value={status} onChange={(event) => setStatus(event.target.value)} className="max-w-field" />
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.reconciliations.title')} columns={columns} rows={sorted} loading={query.isPending} stale={query.isPlaceholderData} onRowClick={(row) => navigate(`/van-sales/reconciliations/${row.id}`)} emptyText={t('vansales.reconciliations.empty')} /> : null}
        <Pager meta={meta} page={page} onPage={setPage} />
      </div>
    </>
  )
}
