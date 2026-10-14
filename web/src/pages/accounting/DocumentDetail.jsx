import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, MoneyInput, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { saveFile } from '@/lib/files'
import { decimalsOf, minorToDecimal } from '@/lib/money'
import { Amount, CalendarDate, Detail } from './AccountingParts'
import { companyPath, DOCUMENT_TONES } from './accountingData'

/** ACC-10: a posted credit note settles open invoices of its contact, in its currency. */
function AllocateDialog({ document, onClose, onDone }) {
  const { t } = useTranslation()
  const side = document.kind.startsWith('customer') ? 'customer' : 'supplier'
  const invoices = useQuery({
    queryKey: ['accounting-documents', 'open', document.party?.id, side],
    queryFn: () => api.get(companyPath({ id: document.company_id }, `documents?kind=${side}_invoice&status=open&party=${document.party.id}&per_page=200`)),
  })
  const [amounts, setAmounts] = useState({})
  const allocate = useMutation({
    mutationFn: () => api.post(`accounting/credit-notes/${document.id}/allocate`, {
      allocations: Object.entries(amounts).filter(([, minor]) => minor).map(([id, minor]) => ({ document_id: id, amount: minorToDecimal(minor, decimalsOf(document.currency)) })),
    }),
    onSuccess: onDone,
  })
  const rows = (invoices.data?.data ?? []).filter((invoice) => invoice.currency === document.currency)

  return (
    <Dialog
      open
      size="lg"
      title={t('accounting.documentDetail.allocateTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={allocate.isPending} onClick={() => allocate.mutate()}>{t('accounting.documentDetail.allocate')}</Button>
        </>
      }
    >
      {allocate.isError ? <Alert tone="danger" title={errorMessage(allocate.error)} /> : null}
      <DataTable
        caption={t('accounting.documentDetail.openInvoices')}
        emptyText={t('accounting.documentDetail.noOpenInvoices')}
        rows={rows}
        columns={[
          { key: 'number', label: t('accounting.columns.number'), render: (row) => row.number },
          { key: 'open', label: t('accounting.columns.open'), align: 'end', render: (row) => <Amount value={row.open} /> },
          { key: 'amount', label: t('accounting.columns.amount'), render: (row) => <MoneyInput aria-label={t('accounting.documentDetail.amountFor', { number: row.number })} currency={row.currency} value={amounts[row.id] ?? ''} onChange={(value) => setAmounts((current) => ({ ...current, [row.id]: value ?? '' }))} /> },
        ]}
      />
    </Dialog>
  )
}

/**
 * ACC-09, ACC-19: one invoice or credit note: its lines with the tax as
 * applied, totals in its currency and in base, what settled it, the tax
 * authority's answer for customer documents, post, print and credit.
 */
export default function DocumentDetail() {
  const { t } = useTranslation()
  const { documentId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [allocating, setAllocating] = useState(false)
  const key = ['accounting-document', documentId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`accounting/documents/${documentId}`) })
  const done = async (result) => {
    if (result?.data) queryClient.setQueryData(key, result)
    await queryClient.invalidateQueries({ queryKey: ['accounting-documents'] })
  }
  const post = useMutation({ mutationFn: () => api.post(`accounting/documents/${documentId}/post`), onSuccess: done })
  const cancel = useMutation({ mutationFn: () => api.post(`accounting/documents/${documentId}/cancel`), onSuccess: done })
  const print = useMutation({
    mutationFn: () => api.download(`accounting/documents/${documentId}/pdf`),
    onSuccess: ({ blob, filename }) => saveFile(blob, filename ?? 'document.pdf'),
  })
  const document = query.data?.data
  const error = post.error ?? cancel.error ?? print.error

  if (!document) return query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : <p className="text-ink-muted">{t('accounting.loading')}</p>

  const columns = [
    { key: 'description', label: t('accounting.columns.description'), render: (line) => line.description },
    { key: 'account', label: t('accounting.columns.account'), render: (line) => (line.account ? `${line.account.code} · ${line.account.name}` : '') },
    { key: 'qty', label: t('accounting.documentForm.qty'), align: 'end', render: (line) => <span className="tabular-nums">{line.qty}</span> },
    { key: 'unit_price', label: t('accounting.documentForm.unitPrice'), align: 'end', render: (line) => <Amount value={line.unit_price} /> },
    {
      key: 'tax',
      label: t('accounting.documentForm.tax'),
      render: (line) => (line.rate_needed ? <StatusBadge tone="warning">{t('accounting.documentDetail.rateNeeded')}</StatusBadge> : line.tax_code ? `${line.tax_code.name}${line.tax_rate ? ` ${line.tax_rate}%` : ''}` : '—'),
    },
    { key: 'total', label: t('accounting.columns.total'), align: 'end', render: (line) => <Amount value={line.total} /> },
  ]

  return (
    <>
      <PageHeader
        eyebrow={t(`accounting.documentKinds.${document.kind}`)}
        title={document.number ?? t('accounting.documentDetail.draftTitle')}
        actions={
          <>
            <Button icon="print" loading={print.isPending} onClick={() => print.mutate()}>{t('accounting.documentDetail.print')}</Button>
            {document.status === 'draft' && can('accounting.invoice.create') ? <Button variant="ghost" loading={cancel.isPending} onClick={() => cancel.mutate()}>{t('accounting.documentDetail.cancel')}</Button> : null}
            {document.status === 'draft' && can('accounting.invoice.post') ? (
              <Button variant="pay" loading={post.isPending} disabled={document.rate_needed} onClick={() => post.mutate()}>{t('accounting.documentDetail.post')}</Button>
            ) : null}
            {document.status === 'posted' && document.kind.endsWith('_credit') && document.open.amount_minor !== '0' && can('accounting.payment.create') ? (
              <Button onClick={() => setAllocating(true)}>{t('accounting.documentDetail.allocate')}</Button>
            ) : null}
          </>
        }
      />
      {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
      {document.rate_needed ? <Alert tone="warning" title={t('accounting.documentDetail.rateNeededTitle')}>{t('accounting.documentDetail.rateNeededText')}</Alert> : null}
      <Card>
        <dl className="grid gap-4 sm:grid-cols-4">
          <Detail label={t('accounting.columns.status')}><StatusBadge tone={DOCUMENT_TONES[document.status]}>{t(`accounting.documentStatuses.${document.status}`)}</StatusBadge></Detail>
          <Detail label={t('accounting.columns.party')}>{document.party?.name}</Detail>
          <Detail label={t('accounting.columns.issueDate')}><CalendarDate value={document.issue_date} /></Detail>
          <Detail label={t('accounting.columns.dueDate')}><CalendarDate value={document.due_date} /></Detail>
          <Detail label={t('accounting.documentDetail.subtotal')}><Amount value={document.subtotal} /></Detail>
          <Detail label={t('accounting.documentForm.tax')}><Amount value={document.tax} /></Detail>
          <Detail label={t('accounting.columns.total')}><Amount value={document.total} /></Detail>
          <Detail label={t('accounting.columns.open')}>{document.status === 'posted' ? <Amount value={document.open} /> : '—'}</Detail>
          {document.base_total && document.base_total.currency !== document.currency ? <Detail label={t('accounting.journalDetail.base')}><Amount value={document.base_total} /></Detail> : null}
          {document.journal_id ? <Detail label={t('accounting.documentDetail.journal')}><Link to={`/accounting/journals/${document.journal_id}`} className="text-primary hover:text-primary-hover">{t('accounting.documentDetail.openJournal')}</Link></Detail> : null}
          {document.fiscal ? <Detail label={t('accounting.documentDetail.fiscal')}>{t(`accounting.fiscalStatuses.${document.fiscal.status}`, { defaultValue: document.fiscal.status })}</Detail> : null}
        </dl>
      </Card>
      <DataTable caption={t('accounting.journalForm.lines')} columns={columns} rows={document.lines} />
      {document.allocations?.length ? (
        <DataTable
          caption={t('accounting.documentDetail.settlements')}
          rows={document.allocations}
          columns={[
            { key: 'date', label: t('accounting.columns.date'), render: (row) => <CalendarDate value={row.date} /> },
            { key: 'by', label: t('accounting.documentDetail.settledBy'), render: (row) => (row.payment_id ? <Link to={`/accounting/payments/${row.payment_id}`} className="text-primary">{t('accounting.documentDetail.payment')}</Link> : t('accounting.documentDetail.creditNote')) },
            { key: 'amount', label: t('accounting.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
            { key: 'fx', label: t('accounting.documentDetail.fxDifference'), align: 'end', render: (row) => (row.fx_difference.amount_minor !== '0' ? <Amount value={row.fx_difference} /> : '—') },
          ]}
        />
      ) : null}
      {allocating ? <AllocateDialog document={document} onClose={() => setAllocating(false)} onDone={async (result) => { await done(result); setAllocating(false) }} /> : null}
    </>
  )
}
