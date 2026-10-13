import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Icon, Money, MoneyInput, RateInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { decimalsOf, minorToDecimal } from '@/lib/money'
import { NoCompany } from './AccountingParts'
import { accountOptions, companyPath, today, totalsOf, useAccountingCompany, useAccounts } from './accountingData'

const emptyLine = (currency) => ({ account_id: '', debit: '', credit: '', currency, rate: '', description: '' })

/**
 * ACC-04: a manual journal drafted line by line (account, debit or credit,
 * currency and rate with Accounting Pro, description); the totals show
 * whether it balances before it is saved. Saved as a draft, then submitted
 * to its flow from the journal's page. Control accounts are not offered
 * (the subledgers own them).
 */
export default function JournalForm() {
  const { t } = useTranslation()
  const { journalId } = useParams()
  const company = useAccountingCompany()
  const existing = useQuery({ queryKey: ['accounting-journal', journalId], queryFn: () => api.get(`accounting/journals/${journalId}`), enabled: Boolean(journalId) })

  if (!company) return <NoCompany />
  if (journalId && !existing.data) return existing.isError ? <Alert tone="danger" title={errorMessage(existing.error)} /> : <p className="text-ink-muted">{t('accounting.loading')}</p>

  return <JournalEditor key={journalId ?? 'new'} company={company} journal={existing.data?.data ?? null} />
}

/** The journal's lines as the form edits them (minor units per line currency). */
function linesOf(journal, base) {
  if (!journal) return [emptyLine(base), emptyLine(base)]
  return journal.lines.map((line) => ({
    account_id: line.account?.id ?? '',
    debit: line.debit?.amount_minor !== '0' ? line.debit.amount_minor : '',
    credit: line.credit?.amount_minor !== '0' ? line.credit.amount_minor : '',
    currency: line.debit?.currency ?? base,
    rate: line.fx && line.fx.base !== line.fx.quote ? line.fx.rate : '',
    description: line.description ?? '',
  }))
}

function JournalEditor({ company, journal }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const journalId = journal?.id
  const accounts = useAccounts(company)
  const currencies = useQuery({ queryKey: ['tenant-currencies'], queryFn: () => api.get('tenant/currencies') })
  const base = company.base_currency ?? 'KES'
  const [date, setDate] = useState(journal?.date ?? today())
  const [memo, setMemo] = useState(journal?.memo ?? '')
  const [reference, setReference] = useState(journal?.reference ?? '')
  const [lines, setLines] = useState(() => linesOf(journal, base))

  const save = useMutation({
    mutationFn: () => {
      const body = {
        date,
        memo: memo || null,
        reference: reference || null,
        lines: lines.map((line) => {
          const places = decimalsOf(line.currency)
          return {
            account_id: line.account_id,
            debit: line.debit ? minorToDecimal(line.debit, places) : null,
            credit: line.credit ? minorToDecimal(line.credit, places) : null,
            currency: line.currency,
            rate: line.currency !== base && line.rate ? line.rate : null,
            description: line.description || null,
          }
        }),
      }
      return journalId ? api.patch(`accounting/journals/${journalId}`, body) : api.post(companyPath(company, 'journals'), body)
    },
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-journals'] })
      navigate(`/accounting/journals/${result.data.id}`)
    },
  })

  const options = accountOptions(accounts.data?.data)
  const currencyOptions = (currencies.data?.data ?? []).filter((c) => c.active !== false).map((c) => ({ value: c.code, label: c.code }))
  const totals = totalsOf(lines, base)
  const update = (index, patch) => setLines((current) => current.map((line, i) => (i === index ? { ...line, ...patch } : line)))

  return (
    <>
      <PageHeader title={journalId ? t('accounting.journalForm.editTitle') : t('accounting.journalForm.title')} description={t('accounting.journalForm.description')} />
      {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-3">
          <TextField type="date" label={t('accounting.columns.date')} value={date} onChange={(event) => setDate(event.target.value)} required />
          <TextField label={t('accounting.columns.reference')} value={reference} onChange={(event) => setReference(event.target.value)} maxLength={80} />
          <TextField label={t('accounting.columns.memo')} value={memo} onChange={(event) => setMemo(event.target.value)} maxLength={1000} />
        </div>
      </Card>
      <Card title={t('accounting.journalForm.lines')}>
        <div className="flex flex-col gap-3" role="list" aria-label={t('accounting.journalForm.lines')}>
          {lines.map((line, index) => (
            <div key={index} role="listitem" className="grid items-end gap-3 border-b border-border pb-3 md:grid-cols-8">
              <Select className="md:col-span-2" label={t('accounting.columns.account')} placeholder={t('accounting.journalForm.chooseAccount')} options={options} value={line.account_id} onChange={(event) => update(index, { account_id: event.target.value })} />
              <MoneyInput label={t('accounting.columns.debit')} currency={line.currency} value={line.debit} onChange={(value) => update(index, { debit: value ?? '', credit: value ? '' : line.credit })} />
              <MoneyInput label={t('accounting.columns.credit')} currency={line.currency} value={line.credit} onChange={(value) => update(index, { credit: value ?? '', debit: value ? '' : line.debit })} />
              <Select label={t('accounting.columns.currency')} options={currencyOptions.length ? currencyOptions : [{ value: base, label: base }]} value={line.currency} onChange={(event) => update(index, { currency: event.target.value })} />
              {line.currency !== base ? (
                <RateInput className="md:col-span-2" label={t('accounting.journalForm.rate', { pair: `${line.currency}/${base}` })} value={line.rate} onChange={(value) => update(index, { rate: value ?? '' })} />
              ) : (
                <TextField className="md:col-span-2" label={t('accounting.columns.description')} value={line.description} onChange={(event) => update(index, { description: event.target.value })} maxLength={255} />
              )}
              <Button variant="ghost" aria-label={t('accounting.journalForm.removeLine', { number: index + 1 })} disabled={lines.length <= 2} onClick={() => setLines((current) => current.filter((_, i) => i !== index))}>
                <Icon name="remove" size={16} />
              </Button>
            </div>
          ))}
          <div>
            <Button icon="plus" onClick={() => setLines((current) => [...current, emptyLine(base)])}>
              {t('accounting.journalForm.addLine')}
            </Button>
          </div>
        </div>
      </Card>
      <div className="flex flex-wrap items-center justify-between gap-4">
        <dl className="flex flex-wrap gap-6" aria-label={t('accounting.journalForm.totals')}>
          <div><dt className="text-caption text-ink-muted">{t('accounting.columns.debit')}</dt><dd><Money amount={totals.debit.toString()} currency={base} /></dd></div>
          <div><dt className="text-caption text-ink-muted">{t('accounting.columns.credit')}</dt><dd><Money amount={totals.credit.toString()} currency={base} /></dd></div>
          <div>
            <dt className="text-caption text-ink-muted">{t('accounting.journalForm.difference')}</dt>
            <dd>{totals.difference === 0n ? <span className="text-success">{t('accounting.journalForm.balanced')}</span> : <Money amount={totals.difference.toString()} currency={base} tone="danger" />}</dd>
          </div>
        </dl>
        <div className="flex gap-2">
          <Button variant="ghost" onClick={() => navigate(-1)}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={lines.some((line) => !line.account_id)} onClick={() => save.mutate()}>
            {t('accounting.journalForm.save')}
          </Button>
        </div>
      </div>
    </>
  )
}
