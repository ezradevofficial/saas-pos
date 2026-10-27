import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Money, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { cn } from '@/lib/utils'
import { QueryNotes, Status, TextLink, When } from './VanSalesParts'
import { isNonZero, UNLOAD_RECEIVE, vanError, vanSalesKey } from './vansalesData'

/** VAN-23: the warehouse counts what came back and receives it in one step. */
function UnloadDialog({ reconciliation, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const lines = (reconciliation.lines ?? []).filter((line) => isNonZero(line.returned_qty))
  const [received, setReceived] = useState(() => Object.fromEntries(lines.map((line) => [line.item_id, line.returned_qty])))
  const valid = lines.every((line) => /^\d+(\.\d+)?$/.test(String(received[line.item_id] ?? '').trim()))
  const receive = useMutation({
    mutationFn: () => api.post(`van-sales/reconciliations/${reconciliation.id}/unload/receive`, { lines: lines.map((line) => ({ item_id: line.item_id, qty_received: String(received[line.item_id]).trim() })) }),
    onSuccess: () => {
      toast.success(t('vansales.unload.received'))
      queryClient.invalidateQueries({ queryKey: vanSalesKey })
      onClose()
    },
  })
  return (
    <Dialog
      open
      title={t('vansales.unload.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={receive.isPending} disabled={!valid || !lines.length} onClick={() => receive.mutate()}>
            {t('vansales.unload.receive')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('vansales.unload.help')}</p>
        {receive.isError ? <Alert tone="danger" title={vanError(t, receive.error)} /> : null}
        {lines.length === 0 ? <p>{t('vansales.unload.nothing')}</p> : null}
        {lines.map((line) => (
          <TextField
            key={line.item_id}
            label={t('vansales.unload.lineLabel', { name: line.item_name, qty: line.returned_qty })}
            inputMode="decimal"
            value={received[line.item_id] ?? ''}
            onChange={(event) => setReceived((current) => ({ ...current, [line.item_id]: event.target.value }))}
          />
        ))}
      </div>
    </Dialog>
  )
}

/**
 * VAN-19 to VAN-23: one count. Stock per item (load = sold + returned +
 * damaged + variance), cash and mobile money per currency (expected,
 * counted, variance), the status, and the unload. A variance is decided in
 * the approvals inbox, not here (VAN-21).
 */
export default function ReconciliationDetail() {
  const { t } = useTranslation()
  const { reconciliationId } = useParams()
  const { can } = usePermissions()
  const [unloading, setUnloading] = useState(false)
  const query = useQuery({ queryKey: [...vanSalesKey, 'reconciliation', reconciliationId], queryFn: () => api.get(`van-sales/reconciliations/${reconciliationId}`) })
  const reconciliation = query.data?.data
  const unload = reconciliation?.unload
  const varianceClass = (value) => cn('tabular-nums', isNonZero(value) && 'text-danger')
  const stockColumns = [
    { key: 'item', label: t('vansales.reconciliations.item'), render: (line) => line.item_name },
    { key: 'load', label: t('vansales.reconciliations.load'), numeric: true, align: 'end', render: (line) => line.load_qty },
    { key: 'sold', label: t('vansales.reconciliations.sold'), numeric: true, align: 'end', render: (line) => line.sold_qty },
    { key: 'returned', label: t('vansales.reconciliations.returned'), numeric: true, align: 'end', render: (line) => line.returned_qty },
    { key: 'damaged', label: t('vansales.reconciliations.damaged'), numeric: true, align: 'end', render: (line) => line.damaged_qty },
    { key: 'variance', label: t('vansales.reconciliations.variance'), numeric: true, align: 'end', render: (line) => <span className={varianceClass(line.variance_qty)}>{line.variance_qty}</span> },
  ]
  const tenderColumns = [
    { key: 'kind', label: t('vansales.reconciliations.kind'), render: (tender) => t(`vansales.tenderKinds.${tender.kind}`, { defaultValue: tender.kind }) },
    { key: 'currency', label: t('vansales.reconciliations.currency'), render: (tender) => tender.currency },
    { key: 'expected', label: t('vansales.reconciliations.expected'), align: 'end', render: (tender) => <Money amount={tender.expected_minor} currency={tender.currency} /> },
    { key: 'counted', label: t('vansales.reconciliations.counted'), align: 'end', render: (tender) => <Money amount={tender.counted_minor} currency={tender.currency} /> },
    { key: 'variance', label: t('vansales.reconciliations.variance'), align: 'end', render: (tender) => <Money amount={tender.variance_minor} currency={tender.currency} tone={isNonZero(tender.variance_minor) ? 'danger' : undefined} /> },
  ]
  return (
    <>
      <TextLink to="/van-sales/reconciliations">{t('vansales.reconciliations.back')}</TextLink>
      <PageHeader title={reconciliation ? t('vansales.reconciliations.detailTitle', { van: reconciliation.van_name, date: reconciliation.run_date }) : t('vansales.reconciliations.title')} description={reconciliation ? `${reconciliation.rep_name} · ${t('vansales.reconciliations.attemptNumber', { number: reconciliation.attempt })}` : undefined} />
      <div className="flex flex-col gap-6">
        <QueryNotes query={query} />
        {reconciliation ? (
          <>
            <div className="flex flex-wrap items-center gap-x-5 gap-y-1 text-ink-muted">
              <Status status={reconciliation.status} />
              <span>{t('vansales.reconciliations.submittedAt')} <When value={reconciliation.submitted_at} /></span>
              {reconciliation.decided_at ? <span>{t('vansales.reconciliations.decidedBy', { name: reconciliation.decided_by_name ?? '' })} <When value={reconciliation.decided_at} /></span> : null}
              <TextLink to={`/van-sales/runs/${reconciliation.run_id}`}>{t('vansales.reconciliations.openRun')}</TextLink>
            </div>
            {reconciliation.note ? <p className="text-ink">{reconciliation.note}</p> : null}
            {reconciliation.status === 'pending' ? (
              <Alert tone="warning" title={t('vansales.reconciliations.pendingTitle')} action={<TextLink to="/approvals">{t('vansales.reconciliations.openApprovals')}</TextLink>}>
                {t('vansales.reconciliations.pendingHelp')}
              </Alert>
            ) : null}
            {reconciliation.status === 'rejected' ? <Alert tone="danger" title={t('vansales.reconciliations.rejectedTitle')}>{t('vansales.reconciliations.rejectedHelp')}</Alert> : null}
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.reconciliations.stockTitle')}</h2>
              <p className="text-caption text-ink-muted">{t('vansales.reconciliations.equation')}</p>
              <DataTable caption={t('vansales.reconciliations.stockTitle')} columns={stockColumns} rows={(reconciliation.lines ?? []).map((line) => ({ ...line, id: line.item_id }))} emptyText={t('vansales.reconciliations.noLines')} />
            </section>
            <section className="flex flex-col gap-3">
              <h2 className="text-h3 text-ink">{t('vansales.reconciliations.cashTitle')}</h2>
              <p className="text-caption text-ink-muted">{t('vansales.reconciliations.cashHelp')}</p>
              <DataTable caption={t('vansales.reconciliations.cashTitle')} columns={tenderColumns} rows={(reconciliation.tenders ?? []).map((tender) => ({ ...tender, id: `${tender.kind}-${tender.currency}` }))} emptyText={t('vansales.reconciliations.noTenders')} />
            </section>
            <Card title={t('vansales.unload.cardTitle')} subtitle={t('vansales.unload.cardHelp')}>
              <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                {unload ? (
                  <>
                    <span>{t('vansales.unload.number', { number: unload.number })}</span>
                    <Status status={unload.status} />
                  </>
                ) : (
                  <span className="text-ink-muted">{t('vansales.unload.none')}</span>
                )}
                {unload && unload.status !== 'received' && can(UNLOAD_RECEIVE) ? (
                  <Button variant="primary" onClick={() => setUnloading(true)}>
                    {t('vansales.unload.open')}
                  </Button>
                ) : null}
              </div>
            </Card>
          </>
        ) : null}
      </div>
      {unloading && reconciliation ? <UnloadDialog reconciliation={reconciliation} onClose={() => setUnloading(false)} /> : null}
    </>
  )
}
