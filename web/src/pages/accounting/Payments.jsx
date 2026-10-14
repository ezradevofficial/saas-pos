import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, ListView, MoneyInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { decimalsOf, minorToDecimal } from '@/lib/money'
import { useServerList } from '@/lib/useServerList'
import { dateField } from '@/pages/pos/posFields'
import { Amount, CalendarDate, Detail, NoCompany } from './AccountingParts'
import { companyPath, today, useAccountingCompany } from './accountingData'

/**
 * ACC-10: a receipt or payment into or out of a bank account, in its
 * currency, allocated to the contact's open documents of that currency;
 * what is not allocated stays on account.
 */
function PaymentDialog({ company, onClose }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [kind, setKind] = useState('receipt')
  const [partyId, setPartyId] = useState('')
  const [bankId, setBankId] = useState('')
  const [date, setDate] = useState(today())
  const [amount, setAmount] = useState('')
  const [reference, setReference] = useState('')
  const [allocations, setAllocations] = useState({})
  const side = kind === 'receipt' ? 'customer' : 'supplier'
  const parties = useQuery({ queryKey: ['parties', side], queryFn: () => api.get(`parties?role=${side}&per_page=200`) })
  const banks = useQuery({ queryKey: ['accounting-bank-accounts', company.id], queryFn: () => api.get(companyPath(company, 'bank-accounts')) })
  const bank = (banks.data?.data ?? []).find((b) => b.id === bankId)
  const open = useQuery({
    queryKey: ['accounting-documents', 'open', partyId, side],
    queryFn: () => api.get(companyPath(company, `documents?kind=${side}_invoice&status=open&party=${partyId}&per_page=200`)),
    enabled: Boolean(partyId),
  })
  const currency = bank?.currency ?? company.base_currency
  const save = useMutation({
    mutationFn: () => api.post(companyPath(company, 'payments'), {
      kind, party_id: partyId, bank_account_id: bankId, date, reference: reference || null,
      amount: minorToDecimal(amount, decimalsOf(currency)),
      allocations: Object.entries(allocations).filter(([, minor]) => minor).map(([id, minor]) => ({ document_id: id, amount: minorToDecimal(minor, decimalsOf(currency)) })),
    }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-payments'] })
      await queryClient.invalidateQueries({ queryKey: ['accounting-documents'] })
      navigate(`/accounting/payments/${result.data.id}`)
    },
  })
  const openRows = (open.data?.data ?? []).filter((doc) => doc.currency === currency)

  return (
    <Dialog
      open
      size="lg"
      title={t('accounting.payments.newTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!partyId || !bankId || !amount} onClick={() => save.mutate()}>{t(`accounting.payments.record.${kind}`)}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label={t('accounting.columns.kind')} options={['receipt', 'payment'].map((value) => ({ value, label: t(`accounting.paymentKinds.${value}`) }))} value={kind} onChange={(event) => { setKind(event.target.value); setPartyId(''); setAllocations({}) }} />
          <Select label={t('accounting.columns.party')} placeholder={t('accounting.documentForm.chooseParty')} options={(parties.data?.data ?? []).map((p) => ({ value: p.id, label: p.name }))} value={partyId} onChange={(event) => { setPartyId(event.target.value); setAllocations({}) }} />
          <Select label={t('accounting.columns.bankAccount')} placeholder={t('accounting.payments.chooseBank')} options={(banks.data?.data ?? []).map((b) => ({ value: b.id, label: `${b.name} · ${b.currency}` }))} value={bankId} onChange={(event) => setBankId(event.target.value)} />
          <TextField type="date" label={t('accounting.columns.date')} value={date} onChange={(event) => setDate(event.target.value)} />
          <MoneyInput label={t('accounting.columns.amount')} currency={currency} value={amount} onChange={(value) => setAmount(value ?? '')} />
          <TextField label={t('accounting.columns.reference')} help={t('accounting.payments.referenceHelp')} value={reference} onChange={(event) => setReference(event.target.value)} maxLength={80} />
        </div>
        {partyId ? (
          <DataTable
            caption={t('accounting.documentDetail.openInvoices')}
            emptyText={t('accounting.documentDetail.noOpenInvoices')}
            rows={openRows}
            columns={[
              { key: 'number', label: t('accounting.columns.number'), render: (row) => row.number },
              { key: 'due', label: t('accounting.columns.dueDate'), render: (row) => <CalendarDate value={row.due_date} /> },
              { key: 'open', label: t('accounting.columns.open'), align: 'end', render: (row) => <Amount value={row.open} /> },
              { key: 'allocate', label: t('accounting.payments.allocate'), render: (row) => <MoneyInput aria-label={t('accounting.documentDetail.amountFor', { number: row.number })} currency={row.currency} value={allocations[row.id] ?? ''} onChange={(value) => setAllocations((current) => ({ ...current, [row.id]: value ?? '' }))} /> },
            ]}
          />
        ) : null}
      </div>
    </Dialog>
  )
}

/** ACC-10: receipts and payments of the company, newest first. */
export default function Payments() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const [adding, setAdding] = useState(false)

  return (
    <>
      <PageHeader
        title={t('accounting.payments.title')}
        description={t('accounting.payments.description')}
        actions={company && can('accounting.payment.create') ? <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>{t('accounting.payments.new')}</Button> : null}
      />
      {company ? <PaymentList company={company} onOpen={(row) => navigate(`/accounting/payments/${row.id}`)} /> : <NoCompany />}
      {adding ? <PaymentDialog company={company} onClose={() => setAdding(false)} /> : null}
    </>
  )
}

function PaymentList({ company, onOpen }) {
  const { t } = useTranslation()
  const columns = [
    { key: 'date', label: t('accounting.columns.date'), sortKey: 'date', hideable: false, render: (row) => <CalendarDate value={row.date} /> },
    { key: 'number', label: t('accounting.columns.number'), sortKey: 'number', render: (row) => row.number },
    { key: 'kind', label: t('accounting.columns.kind'), render: (row) => t(`accounting.paymentKinds.${row.kind}`) },
    { key: 'party', label: t('accounting.columns.party'), render: (row) => row.party?.name ?? '' },
    { key: 'bank_account', label: t('accounting.columns.bankAccount'), render: (row) => row.bank_account?.name ?? '' },
    { key: 'amount', label: t('accounting.columns.amount'), sortKey: 'amount', align: 'end', render: (row) => <Amount value={row.amount} /> },
    { key: 'unallocated', label: t('accounting.columns.unallocated'), align: 'end', render: (row) => <Amount value={row.unallocated} /> },
  ]
  const list = useServerList({ id: 'accounting-payments', endpoint: companyPath(company, 'payments'), queryKey: ['accounting-payments', company.id], filters: { kind: '', from: '', to: '' }, defaultSort: '-date', columns })

  return (
    <ListView
      list={list}
      title={t('accounting.payments.title')}
      searchLabel={t('accounting.payments.search')}
      filterFields={[
        { name: 'kind', label: t('accounting.columns.kind'), options: [{ value: '', label: t('accounting.all') }, ...['receipt', 'payment'].map((value) => ({ value, label: t(`accounting.paymentKinds.${value}`) }))] },
        dateField('from', t('accounting.from')),
        dateField('to', t('accounting.to')),
      ]}
      emptyText={t('accounting.payments.empty')}
      onRowClick={onOpen}
    />
  )
}

/** ACC-10: one receipt or payment and what it settled. */
export function PaymentDetail() {
  const { t } = useTranslation()
  const { paymentId } = useParams()
  const query = useQuery({ queryKey: ['accounting-payment', paymentId], queryFn: () => api.get(`accounting/payments/${paymentId}`) })
  const payment = query.data?.data

  if (!payment) return query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : <p className="text-ink-muted">{t('accounting.loading')}</p>

  return (
    <>
      <PageHeader eyebrow={t(`accounting.paymentKinds.${payment.kind}`)} title={payment.number} />
      <Card>
        <dl className="grid gap-4 sm:grid-cols-4">
          <Detail label={t('accounting.columns.party')}>{payment.party?.name}</Detail>
          <Detail label={t('accounting.columns.date')}><CalendarDate value={payment.date} /></Detail>
          <Detail label={t('accounting.columns.amount')}><Amount value={payment.amount} /></Detail>
          <Detail label={t('accounting.columns.unallocated')}><Amount value={payment.unallocated} /></Detail>
          <Detail label={t('accounting.columns.bankAccount')}>{payment.bank_account?.name}</Detail>
          <Detail label={t('accounting.columns.reference')}>{payment.reference ?? '—'}</Detail>
          <Detail label={t('accounting.journalDetail.base')}><Amount value={payment.base_amount} /></Detail>
          <Detail label={t('accounting.documentDetail.journal')}><Link to={`/accounting/journals/${payment.journal_id}`} className="text-primary hover:text-primary-hover">{t('accounting.documentDetail.openJournal')}</Link></Detail>
        </dl>
      </Card>
      <DataTable
        caption={t('accounting.documentDetail.settlements')}
        emptyText={t('accounting.payments.noAllocations')}
        rows={payment.allocations}
        columns={[
          { key: 'date', label: t('accounting.columns.date'), render: (row) => <CalendarDate value={row.date} /> },
          { key: 'document', label: t('accounting.columns.number'), render: (row) => <Link to={`/accounting/documents/${row.document.id}`} className="text-primary">{row.document.number}</Link> },
          { key: 'amount', label: t('accounting.columns.amount'), align: 'end', render: (row) => <Amount value={row.amount} /> },
          { key: 'fx', label: t('accounting.documentDetail.fxDifference'), align: 'end', render: (row) => (row.fx_difference.amount_minor !== '0' ? <Amount value={row.fx_difference} /> : '—') },
        ]}
      />
    </>
  )
}
