import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DecimalInput, Icon, MoneyInput, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { decimalsOf, minorToDecimal } from '@/lib/money'
import { NoCompany } from './AccountingParts'
import { accountOptions, companyPath, DOCUMENT_KINDS, today, useAccountingCompany, useAccounts } from './accountingData'

const emptyLine = () => ({ description: '', account_id: '', qty: '1', unit_price: '', tax_code_id: '' })

/**
 * ACC-09: an invoice, credit note, bill or supplier credit, drafted: the
 * contact (customers or suppliers), dates, currency, tax inclusive or
 * not, and lines (account, quantity, unit price, tax code). Tax is
 * computed by the API from the core tax codes; a code waiting for its
 * rate ("Rate needed") is saved but cannot be posted.
 */
export default function DocumentForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [params] = useSearchParams()
  const side = params.get('side') === 'supplier' ? 'supplier' : 'customer'
  const company = useAccountingCompany()
  const accounts = useAccounts(company)
  const parties = useQuery({ queryKey: ['parties', side], queryFn: () => api.get(`parties?role=${side}&per_page=200`) })
  const taxCodes = useQuery({ queryKey: ['tax-codes', company?.id], queryFn: () => api.get(`companies/${company.id}/tax-codes?per_page=200`), enabled: Boolean(company) })
  const invoices = useQuery({
    queryKey: ['accounting-documents', company?.id, 'credit-targets', side],
    queryFn: () => api.get(companyPath(company, `documents?kind=${side}_invoice&status=posted&per_page=200`)),
    enabled: Boolean(company),
  })
  const [kind, setKind] = useState(DOCUMENT_KINDS[side][0])
  const [partyId, setPartyId] = useState('')
  const [issueDate, setIssueDate] = useState(today())
  const [dueDate, setDueDate] = useState('')
  const [currency, setCurrency] = useState(company?.base_currency ?? '')
  const [inclusive, setInclusive] = useState(false)
  const [reference, setReference] = useState('')
  const [credits, setCredits] = useState('')
  const [lines, setLines] = useState([emptyLine()])
  const currencyCode = currency || company?.base_currency || 'KES'

  const save = useMutation({
    mutationFn: () => api.post(companyPath(company, 'documents'), {
      kind,
      party_id: partyId,
      issue_date: issueDate,
      due_date: dueDate || null,
      currency: currencyCode,
      tax_inclusive: inclusive,
      supplier_reference: side === 'supplier' ? reference || null : null,
      credits_document_id: kind.endsWith('_credit') ? credits || null : null,
      lines: lines.map((line) => ({
        description: line.description,
        account_id: line.account_id,
        qty: line.qty,
        unit_price: line.unit_price ? minorToDecimal(line.unit_price, decimalsOf(currencyCode)) : '0',
        tax_code_id: line.tax_code_id || null,
      })),
    }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-documents'] })
      navigate(`/accounting/documents/${result.data.id}`)
    },
  })

  if (!company) return <NoCompany />

  const update = (index, patch) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))
  const accountChoices = accountOptions(accounts.data?.data)
  const taxChoices = [{ value: '', label: t('accounting.documentForm.noTax') }, ...(taxCodes.data?.data ?? []).filter((code) => !code.archived_at).map((code) => ({ value: code.id, label: code.rate_needed ? t('accounting.documentForm.rateNeeded', { name: code.name }) : code.name }))]
  const invoiceChoices = (invoices.data?.data ?? []).filter((doc) => doc.party?.id === partyId && doc.currency === currencyCode).map((doc) => ({ value: doc.id, label: doc.number }))

  return (
    <>
      <PageHeader title={t(`accounting.documents.new.${side}`)} description={t('accounting.documentForm.description')} />
      {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-3">
          <Select label={t('accounting.columns.kind')} options={DOCUMENT_KINDS[side].map((value) => ({ value, label: t(`accounting.documentKinds.${value}`) }))} value={kind} onChange={(event) => setKind(event.target.value)} />
          <Select label={t('accounting.columns.party')} placeholder={t('accounting.documentForm.chooseParty')} options={(parties.data?.data ?? []).map((party) => ({ value: party.id, label: party.name }))} value={partyId} onChange={(event) => setPartyId(event.target.value)} required />
          <TextField label={t('accounting.columns.currency')} value={currencyCode} onChange={(event) => setCurrency(event.target.value.toUpperCase())} maxLength={3} />
          <TextField type="date" label={t('accounting.columns.issueDate')} value={issueDate} onChange={(event) => setIssueDate(event.target.value)} required />
          <TextField type="date" label={t('accounting.columns.dueDate')} help={t('accounting.documentForm.dueHelp')} value={dueDate} onChange={(event) => setDueDate(event.target.value)} />
          {side === 'supplier' ? <TextField label={t('accounting.documentForm.supplierReference')} value={reference} onChange={(event) => setReference(event.target.value)} maxLength={80} /> : null}
          {kind.endsWith('_credit') ? (
            <Select label={t('accounting.documentForm.credits')} placeholder={t('accounting.documentForm.chooseInvoice')} options={invoiceChoices} value={credits} onChange={(event) => setCredits(event.target.value)} required={kind === 'customer_credit'} />
          ) : null}
          <Switch label={t('accounting.documentForm.inclusive')} checked={inclusive} onChange={setInclusive} />
        </div>
      </Card>
      <Card title={t('accounting.journalForm.lines')}>
        <div className="flex flex-col gap-3" role="list" aria-label={t('accounting.journalForm.lines')}>
          {lines.map((line, index) => (
            <div key={index} role="listitem" className="grid items-end gap-3 border-b border-border pb-3 md:grid-cols-[2fr_2fr_6rem_1fr_1.5fr_auto]">
              <TextField label={t('accounting.columns.description')} value={line.description} onChange={(event) => update(index, { description: event.target.value })} maxLength={255} required />
              <Select label={t('accounting.columns.account')} placeholder={t('accounting.journalForm.chooseAccount')} options={accountChoices} value={line.account_id} onChange={(event) => update(index, { account_id: event.target.value })} />
              <DecimalInput maxDecimals={4} label={t('accounting.documentForm.qty')} value={line.qty} onChange={(value) => update(index, { qty: value ?? '' })} />
              <MoneyInput label={t('accounting.documentForm.unitPrice')} currency={currencyCode} value={line.unit_price} onChange={(value) => update(index, { unit_price: value ?? '' })} />
              <Select label={t('accounting.documentForm.tax')} options={taxChoices} value={line.tax_code_id} onChange={(event) => update(index, { tax_code_id: event.target.value })} />
              <Button variant="ghost" aria-label={t('accounting.journalForm.removeLine', { number: index + 1 })} disabled={lines.length <= 1} onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                <Icon name="remove" size={16} />
              </Button>
            </div>
          ))}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, emptyLine()])}>{t('accounting.journalForm.addLine')}</Button>
          </div>
        </div>
      </Card>
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={() => navigate(-1)}>{t('accounting.cancel')}</Button>
        <Button variant="primary" loading={save.isPending} disabled={!partyId || lines.some((line) => !line.account_id || !line.description)} onClick={() => save.mutate()}>
          {t('accounting.documentForm.save')}
        </Button>
      </div>
    </>
  )
}
