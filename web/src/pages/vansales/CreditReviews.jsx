import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Money, Select } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ActionsLabel, Flags, Pager, ReasonDialog, Status, When } from './VanSalesParts'
import { CREDIT_REVIEW, usePagedList, vanSalesKey } from './vansalesData'

/** VAN-11, VAN-12: flagged credit sales waiting for a look; reviewing changes no money. */
export default function CreditReviews() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [status, setStatus] = useState('pending')
  const [reviewing, setReviewing] = useState(null)
  const { query, rows, meta, page, setPage } = usePagedList('credit-reviews', 'van-sales/credit-reviews', { review_status: status })
  const columns = [
    { key: 'sold', label: t('vansales.runs.soldAt'), render: (row) => <When value={row.sold_at} /> },
    { key: 'customer', label: t('vansales.stops.customer'), render: (row) => row.customer_name ?? t('vansales.runs.walkIn') },
    { key: 'van', label: t('vansales.runs.van'), render: (row) => `${row.van_name} · ${row.rep_name}` },
    { key: 'credit', label: t('vansales.runs.onCredit'), align: 'end', render: (row) => <Money amount={row.credit_minor} currency={row.currency} /> },
    { key: 'limit', label: t('vansales.credit.limit'), align: 'end', render: (row) => (row.limit_minor == null ? '' : <Money amount={row.limit_minor} currency={row.currency} />) },
    { key: 'before', label: t('vansales.credit.balanceBefore'), align: 'end', render: (row) => (row.balance_before_minor == null ? '' : <Money amount={row.balance_before_minor} currency={row.currency} />) },
    { key: 'flags', label: t('vansales.runs.flags'), render: (row) => <Flags flags={row.flags} /> },
    {
      key: 'review',
      label: t('vansales.credit.review'),
      render: (row) =>
        row.review_status === 'reviewed' ? (
          <div className="flex flex-col gap-1">
            <Status status="reviewed" />
            <span className="text-caption text-ink-muted">{row.reviewed_by_name}</span>
            {row.review_note ? <span className="text-caption text-ink-muted">{row.review_note}</span> : null}
          </div>
        ) : (
          <Status status="pending" />
        ),
    },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        row.review_status === 'pending' && can(CREDIT_REVIEW) ? (
          <Button variant="primary" aria-label={t('vansales.credit.reviewNamed', { name: row.customer_name ?? t('vansales.runs.walkIn') })} onClick={() => setReviewing(row)}>
            {t('vansales.credit.reviewAction')}
          </Button>
        ) : null,
    },
  ]
  return (
    <>
      <PageHeader title={t('vansales.credit.reviewsTitle')} description={t('vansales.credit.reviewsDescription')} />
      <div className="flex flex-col gap-5">
        <Select
          label={t('vansales.credit.reviewStatus')}
          options={[
            { value: 'pending', label: t('vansales.statuses.pending') },
            { value: 'reviewed', label: t('vansales.statuses.reviewed') },
            { value: '', label: t('vansales.credit.allReviews') },
          ]}
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          className="max-w-field"
        />
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.credit.reviewsTitle')} columns={columns} rows={rows} loading={query.isPending} stale={query.isPlaceholderData} emptyText={t('vansales.credit.reviewsEmpty')} /> : null}
        <Pager meta={meta} page={page} onPage={setPage} />
      </div>
      {reviewing ? (
        <ReasonDialog
          title={t('vansales.credit.reviewTitle')}
          help={t('vansales.credit.reviewHelp')}
          label={t('vansales.credit.note')}
          field="note"
          required={false}
          confirm={t('vansales.credit.reviewConfirm')}
          path={`van-sales/credit-reviews/${reviewing.id}/review`}
          onClose={() => setReviewing(null)}
          onDone={() => {
            toast.success(t('vansales.credit.reviewed'))
            queryClient.invalidateQueries({ queryKey: vanSalesKey })
            setReviewing(null)
          }}
        />
      ) : null}
    </>
  )
}
