import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, Qty, Status, TextAreaField, When } from './ProcurementParts'
import { procurementKey } from './procurementData'

/**
 * PRC-15: approve an invoice: a matched one at once; one with an
 * exception only with `procurement.invoice.override` and a reason.
 */
function Approval({ invoice, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const exception = invoice.status === 'exception'
  const approve = useMutation({
    mutationFn: () => api.post(`procurement/invoices/${invoice.id}/approve`, exception ? { override_reason: reason.trim() } : {}),
    onSuccess: (response) => onDone(response),
  })
  return (
    <Card title={t('procurement.invoice.approvalTitle')}>
      <div className="flex flex-col gap-4">
        {approve.isError ? <Alert tone="danger" title={errorMessage(approve.error)} /> : null}
        {exception ? (
          <>
            <Alert tone="warning" title={t('procurement.invoice.exceptionTitle')}>
              {t('procurement.invoice.exceptionHelp')}
            </Alert>
            <TextAreaField label={t('procurement.invoice.overrideReason')} value={reason} maxLength={1000} required onChange={(event) => setReason(event.target.value)} />
          </>
        ) : (
          <p className="text-body text-ink-muted">{t('procurement.invoice.matchedHelp')}</p>
        )}
        <div>
          <Button variant="pay" loading={approve.isPending} disabled={exception && !reason.trim()} onClick={() => approve.mutate()}>
            {t(exception ? 'procurement.invoice.approveException' : 'procurement.invoice.approve')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

/**
 * PRC-15: one supplier invoice and its three-way match: per line what was
 * ordered (quantity and price), received and accepted, invoiced before,
 * this invoice's quantity and price, the line's result and its price and
 * quantity variances; totals; Approve (with an override reason for an
 * exception, when the reader may) and Cancel.
 */
export default function InvoiceDetail() {
  const { t } = useTranslation()
  const { invoiceId } = useParams()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const key = [...procurementKey, 'invoice', invoiceId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/invoices/${invoiceId}`) })
  const invoice = query.data?.data
  const back = <Back to="/procurement/invoices">{t('procurement.invoices.back')}</Back>
  if (!invoice) return <DetailState query={query} back={back} notFound={t('procurement.invoice.notFound')} />

  const company = invoice.company?.id
  const variance = (value) => (value && value.amount_minor !== '0' ? <Amount value={value} tone={String(value.amount_minor).startsWith('-') ? undefined : 'danger'} /> : <span className="text-ink-muted">—</span>)
  const qtyVariance = (value) => (value && value !== '0' ? <Qty value={value} className={String(value).startsWith('-') ? undefined : 'text-danger'} /> : <span className="text-ink-muted">—</span>)
  const columns = [
    { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    { key: 'description', label: t('procurement.columns.item'), wrap: true, render: (line) => line.description ?? '' },
    {
      key: 'ordered',
      label: t('procurement.columns.ordered'),
      align: 'end',
      render: (line) => (
        <span className="flex flex-col items-end">
          <Qty value={line.ordered_qty} />
          <span className="text-caption text-ink-muted">
            <Amount value={line.ordered_unit_price} />
          </span>
        </span>
      ),
    },
    {
      key: 'received',
      label: t('procurement.columns.receivedAccepted'),
      align: 'end',
      render: (line) => (
        <span className="whitespace-nowrap">
          <Qty value={line.received_qty} /> <span className="text-ink-muted">/</span> <Qty value={line.accepted_qty} />
        </span>
      ),
    },
    { key: 'invoiced_before', label: t('procurement.columns.invoicedBefore'), align: 'end', render: (line) => <Qty value={line.invoiced_before_qty} /> },
    {
      key: 'this',
      label: t('procurement.columns.thisInvoice'),
      align: 'end',
      render: (line) => (
        <span className="flex flex-col items-end">
          <Qty value={line.qty} />
          <span className="text-caption text-ink-muted">
            <Amount value={line.unit_price} />
          </span>
        </span>
      ),
    },
    { key: 'total', label: t('procurement.columns.lineTotal'), align: 'end', render: (line) => <Amount value={line.total} /> },
    { key: 'match', label: t('procurement.columns.match'), render: (line) => <Status status={line.match_status} /> },
    { key: 'price_variance', label: t('procurement.columns.priceVariance'), align: 'end', render: (line) => variance(line.price_variance) },
    { key: 'qty_variance', label: t('procurement.columns.qtyVariance'), align: 'end', render: (line) => qtyVariance(line.qty_variance) },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.invoice.eyebrow')}
        title={invoice.number ?? t('procurement.draftNumber')}
        description={<Status status={invoice.status} />}
        actions={
          invoice.can?.cancel ? (
            <Button variant="danger" onClick={() => setCancelling(true)}>
              {t('procurement.invoice.cancel')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.supplierReference')}>{invoice.supplier_reference}</Detail>
            <Detail label={t('procurement.fields.supplier')}>{invoice.party?.name}</Detail>
            <Detail label={t('procurement.fields.order')}>
              {invoice.order ? (
                <Link to={`/procurement/orders/${invoice.order.id}`} className="text-primary hover:text-primary-hover">
                  {invoice.order.number}
                </Link>
              ) : (
                '—'
              )}
            </Detail>
            <Detail label={t('procurement.fields.invoiceDate')}>
              <CalendarDate value={invoice.invoice_date} />
            </Detail>
            <Detail label={t('procurement.fields.dueDate')}>
              <CalendarDate value={invoice.due_date} />
            </Detail>
            <Detail label={t('procurement.fields.branch')}>{[invoice.company?.name, invoice.branch?.name].filter(Boolean).join(' · ')}</Detail>
            <Detail label={t('procurement.columns.subtotal')}>
              <Amount value={invoice.subtotal} />
            </Detail>
            <Detail label={t('procurement.columns.tax')}>
              <Amount value={invoice.tax} />
            </Detail>
            <Detail label={t('procurement.columns.total')}>
              <Amount value={invoice.total} />
            </Detail>
            {invoice.approved_at ? (
              <Detail label={t('procurement.invoice.approvedBy')}>
                <span className="flex flex-col">
                  <span>{invoice.approved_by?.name}</span>
                  <span className="text-caption text-ink-muted">
                    <When value={invoice.approved_at} companyId={company} />
                  </span>
                </span>
              </Detail>
            ) : null}
            {invoice.override_reason ? <Detail label={t('procurement.invoice.overrideReason')}>{invoice.override_reason}</Detail> : null}
            {invoice.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={invoice.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{invoice.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('procurement.invoice.matchTitle')} subtitle={t('procurement.invoice.matchHelp')}>
          <DataTable caption={t('procurement.invoice.matchTitle')} columns={columns} rows={invoice.lines ?? []} />
        </Card>
        {invoice.can?.approve ? (
          <Approval
            invoice={invoice}
            onDone={async (response) => {
              if (response?.data) queryClient.setQueryData(key, response)
              toast.success(t('procurement.invoice.approved'))
              await queryClient.invalidateQueries({ queryKey: procurementKey })
            }}
          />
        ) : invoice.status === 'exception' ? (
          <Alert tone="warning" title={t('procurement.invoice.exceptionTitle')}>
            {t('procurement.invoice.exceptionNoOverride')}
          </Alert>
        ) : null}
      </div>
      {invoice.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.invoice.cancelTitle', { number: invoice.number ?? t('procurement.draftNumber') })}
          help={t('procurement.invoice.cancelHelp')}
          path={`procurement/invoices/${invoice.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.invoice.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
