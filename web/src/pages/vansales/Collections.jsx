import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Money, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ActionsLabel, Flags, Pager, ReasonDialog, Status, TextLink, When } from './VanSalesParts'
import { COLLECTION_VOID, usePagedList, vanSalesKey } from './vansalesData'

/** VAN-17: money collected on account in the field; a supervisor can void one with a reason. */
export default function Collections() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [filters, setFilters] = useState({ from: '', to: '' })
  const [voiding, setVoiding] = useState(null)
  const { query, rows, meta, page, setPage } = usePagedList('collections', 'van-sales/collections', filters)
  const set = (field) => (event) => setFilters((current) => ({ ...current, [field]: event.target.value }))
  const columns = [
    { key: 'at', label: t('vansales.collections.collectedAt'), render: (row) => <When value={row.collected_at} /> },
    { key: 'customer', label: t('vansales.stops.customer'), render: (row) => row.customer_name },
    { key: 'receipt', label: t('vansales.collections.receipt'), render: (row) => row.receipt_number },
    { key: 'method', label: t('vansales.collections.method'), render: (row) => t(`vansales.methods.${row.method_type}`, { defaultValue: row.method_type }) },
    { key: 'amount', label: t('vansales.collections.amount'), align: 'end', render: (row) => <Money amount={row.amount_minor} currency={row.currency} /> },
    {
      key: 'status',
      label: t('vansales.fields.status'),
      render: (row) => (
        <div className="flex flex-col gap-1">
          <Status status={row.status} />
          {row.void_reason ? <span className="text-caption text-ink-muted">{row.void_reason}</span> : null}
        </div>
      ),
    },
    { key: 'flags', label: t('vansales.runs.flags'), render: (row) => <Flags flags={row.flags} /> },
    { key: 'run', label: t('vansales.collections.run'), render: (row) => <TextLink to={`/van-sales/runs/${row.run_id}`}>{t('vansales.collections.openRun')}</TextLink> },
    {
      key: 'actions',
      label: <ActionsLabel />,
      align: 'end',
      render: (row) =>
        row.status !== 'voided' && can(COLLECTION_VOID) ? (
          <Button variant="ghost" aria-label={t('vansales.collections.voidNamed', { receipt: row.receipt_number })} onClick={() => setVoiding(row)}>
            {t('vansales.collections.void')}
          </Button>
        ) : null,
    },
  ]
  return (
    <>
      <PageHeader title={t('vansales.collections.title')} description={t('vansales.collections.description')} />
      <div className="flex flex-col gap-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('vansales.fields.from')} type="date" value={filters.from} onChange={set('from')} />
          <TextField label={t('vansales.fields.to')} type="date" value={filters.to} onChange={set('to')} />
        </div>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {!query.isError ? <DataTable caption={t('vansales.collections.title')} columns={columns} rows={rows} loading={query.isPending} stale={query.isPlaceholderData} emptyText={t('vansales.collections.empty')} /> : null}
        <Pager meta={meta} page={page} onPage={setPage} />
      </div>
      {voiding ? (
        <ReasonDialog
          title={t('vansales.collections.voidTitle')}
          help={t('vansales.collections.voidHelp', { receipt: voiding.receipt_number })}
          label={t('vansales.collections.voidReason')}
          confirm={t('vansales.collections.voidConfirm')}
          variant="danger"
          path={`van-sales/collections/${voiding.id}/void`}
          onClose={() => setVoiding(null)}
          onDone={() => {
            toast.success(t('vansales.collections.voided'))
            queryClient.invalidateQueries({ queryKey: vanSalesKey })
            setVoiding(null)
          }}
        />
      ) : null}
    </>
  )
}
