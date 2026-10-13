import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { toMinor } from '@/lib/money'
import { cn } from '@/lib/utils'
import { Amount, NoCompany } from './AccountingParts'
import { accountOptions, companyPath, useAccountingCompany, useAccounts } from './accountingData'

const KINDS = ['bank', 'mobile_money', 'cash']
const MAPPING = ['date', 'description', 'reference', 'amount', 'money_in', 'money_out']
const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y']

function AddBankDialog({ company, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const accounts = useAccounts(company)
  const [form, setForm] = useState({ name: '', kind: 'bank', account_id: '', currency: company.base_currency, bank_name: '', account_number: '' })
  const save = useMutation({
    mutationFn: () => api.post(companyPath(company, 'bank-accounts'), { ...form, bank_name: form.bank_name || null, account_number: form.account_number || null }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-bank-accounts', company.id] })
      onClose()
    },
  })
  const assets = accountOptions((accounts.data?.data ?? []).filter((account) => account.type === 'asset'))
  const set = (patch) => setForm((current) => ({ ...current, ...patch }))

  return (
    <Dialog
      open
      title={t('accounting.bank.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.name || !form.account_id} onClick={() => save.mutate()}>{t('accounting.bank.add')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <TextField label={t('accounting.columns.name')} value={form.name} onChange={(event) => set({ name: event.target.value })} maxLength={120} required />
        <Select label={t('accounting.columns.kind')} options={KINDS.map((value) => ({ value, label: t(`accounting.bankKinds.${value}`) }))} value={form.kind} onChange={(event) => set({ kind: event.target.value })} />
        <Select label={t('accounting.bank.glAccount')} placeholder={t('accounting.journalForm.chooseAccount')} options={assets} value={form.account_id} onChange={(event) => set({ account_id: event.target.value })} />
        <TextField label={t('accounting.columns.currency')} value={form.currency} onChange={(event) => set({ currency: event.target.value.toUpperCase() })} maxLength={3} />
        <TextField label={t('accounting.bank.bankName')} value={form.bank_name} onChange={(event) => set({ bank_name: event.target.value })} maxLength={120} />
        <TextField label={t('accounting.bank.accountNumber')} value={form.account_number} onChange={(event) => set({ account_number: event.target.value })} maxLength={40} />
      </div>
    </Dialog>
  )
}

/** ACC-12: the company's bank, mobile money and cash accounts. */
export default function BankAccounts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const [adding, setAdding] = useState(false)
  const banks = useQuery({ queryKey: ['accounting-bank-accounts', company?.id], queryFn: () => api.get(companyPath(company, 'bank-accounts')), enabled: Boolean(company) })

  if (!company) return <NoCompany />

  return (
    <>
      <PageHeader
        title={t('accounting.bank.title')}
        description={t('accounting.bank.description')}
        actions={can('accounting.bank.manage') ? <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>{t('accounting.bank.add')}</Button> : null}
      />
      <DataTable
        caption={t('accounting.bank.title')}
        emptyText={t('accounting.bank.empty')}
        loading={banks.isLoading}
        rows={banks.data?.data ?? []}
        onRowClick={(row) => navigate(`/accounting/bank/${row.id}`)}
        columns={[
          { key: 'name', label: t('accounting.columns.name'), render: (row) => row.name },
          { key: 'kind', label: t('accounting.columns.kind'), render: (row) => t(`accounting.bankKinds.${row.kind}`) },
          { key: 'currency', label: t('accounting.columns.currency'), render: (row) => row.currency },
          { key: 'bank', label: t('accounting.bank.bankName'), render: (row) => [row.bank_name, row.account_number].filter(Boolean).join(' · ') || '—' },
        ]}
      />
      {adding ? <AddBankDialog company={company} onClose={() => setAdding(false)} /> : null}
    </>
  )
}

function ImportDialog({ bankId, onClose, onDone }) {
  const { t } = useTranslation()
  const [file, setFile] = useState(null)
  const [format, setFormat] = useState('csv')
  const [mapping, setMapping] = useState({ date: 'Date', description: 'Description', reference: 'Reference', amount: 'Amount', money_in: '', money_out: '', date_format: 'Y-m-d' })
  const send = useMutation({
    mutationFn: () => {
      const data = new FormData()
      data.append('file', file)
      data.append('format', format)
      if (format === 'csv') for (const [key, value] of Object.entries(mapping)) if (value) data.append(`mapping[${key}]`, value)
      return api.upload(`accounting/bank-accounts/${bankId}/statements`, data)
    },
    onSuccess: onDone,
  })

  return (
    <Dialog
      open
      title={t('accounting.reconcile.importTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('accounting.cancel')}</Button>
          <Button variant="primary" loading={send.isPending} disabled={!file} onClick={() => send.mutate()}>{t('accounting.reconcile.import')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {send.isError ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        <Select label={t('accounting.reconcile.format')} options={['csv', 'mpesa'].map((value) => ({ value, label: t(`accounting.reconcile.formats.${value}`) }))} value={format} onChange={(event) => setFormat(event.target.value)} />
        <TextField type="file" accept=".csv,text/csv" label={t('accounting.reconcile.file')} onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        {format === 'csv' ? (
          <div className="grid gap-3 sm:grid-cols-2">
            {MAPPING.map((key) => (
              <TextField key={key} label={t(`accounting.reconcile.mapping.${key}`)} value={mapping[key]} onChange={(event) => setMapping((current) => ({ ...current, [key]: event.target.value }))} maxLength={100} />
            ))}
            <Select label={t('accounting.reconcile.mapping.date_format')} options={DATE_FORMATS.map((value) => ({ value, label: value }))} value={mapping.date_format} onChange={(event) => setMapping((current) => ({ ...current, date_format: event.target.value }))} />
          </div>
        ) : (
          <p className="text-caption text-ink-muted">{t('accounting.reconcile.mpesaHelp')}</p>
        )}
      </div>
    </Dialog>
  )
}

/** One selectable row of a pane. */
function PaneRow({ selected, suggested, onClick, children }) {
  return (
    <li>
      <button
        type="button"
        aria-pressed={selected}
        onClick={onClick}
        className={cn(
          'flex w-full items-center justify-between gap-4 rounded-md border px-3 py-2 text-left text-body',
          selected ? 'border-primary bg-primary-tint' : 'border-border bg-surface-200 hover:bg-surface-300',
        )}
      >
        {children}
        {suggested ? <span className="text-caption text-ink-muted">{suggested}</span> : null}
      </button>
    </li>
  )
}

/**
 * ACC-13, ACC-14: reconciliation of one account in two panes: statement
 * lines on the left, the account's unmatched ledger lines on the right.
 * Pick a statement line and the ledger lines of the same total, then
 * match; suggestions are marked. A line with no ledger line (a charge)
 * posts to an account and matches; auto-match takes what is certain.
 */
export function Reconcile() {
  const { t } = useTranslation()
  const { bankId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const company = useAccountingCompany()
  const accounts = useAccounts(company)
  const [statementId, setStatementId] = useState(null)
  const [ledgerIds, setLedgerIds] = useState([])
  const [importing, setImporting] = useState(false)
  const [postAccount, setPostAccount] = useState('')
  const key = ['accounting-reconciliation', bankId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`accounting/bank-accounts/${bankId}/reconciliation`) })
  const refresh = async () => {
    setStatementId(null)
    setLedgerIds([])
    await queryClient.invalidateQueries({ queryKey: key })
  }
  const match = useMutation({ mutationFn: () => api.post(`accounting/bank-statement-lines/${statementId}/match`, { journal_line_ids: ledgerIds }), onSuccess: refresh })
  const post = useMutation({ mutationFn: () => api.post(`accounting/bank-statement-lines/${statementId}/post`, { account_id: postAccount }), onSuccess: refresh })
  const auto = useMutation({ mutationFn: () => api.post(`accounting/bank-accounts/${bankId}/auto-match`), onSuccess: refresh })
  const data = query.data?.data
  const error = match.error ?? post.error ?? auto.error
  const reconcile = can('accounting.bank.reconcile')

  if (!data) return query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : <p className="text-ink-muted">{t('accounting.loading')}</p>

  const statementLine = data.statement_lines.find((line) => line.id === statementId)
  const chosen = data.ledger_lines.filter((line) => ledgerIds.includes(line.id)).reduce((sum, line) => sum + toMinor(line.amount.amount_minor), 0n)
  const suggestedFor = new Set(statementId ? (data.suggestions[statementId] ?? []) : [])
  const toggleLedger = (id) => setLedgerIds((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]))
  const pick = (id) => {
    setStatementId(id)
    setLedgerIds(data.suggestions[id] ?? [])
  }

  return (
    <>
      <PageHeader
        eyebrow={t('accounting.reconcile.eyebrow')}
        title={data.bank_account.name}
        actions={
          reconcile ? (
            <>
              <Button icon="upload" onClick={() => setImporting(true)}>{t('accounting.reconcile.import')}</Button>
              <Button loading={auto.isPending} onClick={() => auto.mutate()}>{t('accounting.reconcile.autoMatch')}</Button>
            </>
          ) : null
        }
      />
      {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
      {auto.isSuccess ? <Alert tone="success" title={t('accounting.reconcile.autoResult', { matched: auto.data.data.matched, ruled: auto.data.data.ruled })} /> : null}
      <Card>
        <dl className="grid gap-4 sm:grid-cols-4" aria-label={t('accounting.reconcile.summary')}>
          {['statement_balance', 'ledger_balance', 'unmatched_statement', 'unmatched_ledger'].map((name) => (
            <div key={name}><dt className="text-caption text-ink-muted">{t(`accounting.reconcile.${name}`)}</dt><dd><Amount value={data.summary[name]} /></dd></div>
          ))}
        </dl>
      </Card>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title={t('accounting.reconcile.statementPane')}>
          {data.statement_lines.length ? (
            <ul className="flex flex-col gap-2" aria-label={t('accounting.reconcile.statementPane')}>
              {data.statement_lines.map((line) => (
                <PaneRow key={line.id} selected={line.id === statementId} onClick={() => pick(line.id)} suggested={data.suggestions[line.id] ? t('accounting.reconcile.hasSuggestion') : null}>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate">{line.description}</span>
                    <span className="text-caption text-ink-muted tabular-nums">{[line.date, line.reference].filter(Boolean).join(' · ')}</span>
                  </span>
                  <Amount value={line.amount} />
                </PaneRow>
              ))}
            </ul>
          ) : <p className="text-ink-muted">{t('accounting.reconcile.noStatementLines')}</p>}
        </Card>
        <Card title={t('accounting.reconcile.ledgerPane')}>
          {data.ledger_lines.length ? (
            <ul className="flex flex-col gap-2" aria-label={t('accounting.reconcile.ledgerPane')}>
              {data.ledger_lines.map((line) => (
                <PaneRow key={line.id} selected={ledgerIds.includes(line.id)} onClick={() => toggleLedger(line.id)} suggested={suggestedFor.has(line.id) ? t('accounting.reconcile.suggested') : null}>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate">{line.description ?? line.journal.number}</span>
                    <span className="text-caption text-ink-muted tabular-nums">{[line.date, line.journal.number, line.reference].filter(Boolean).join(' · ')}</span>
                  </span>
                  <Amount value={line.amount} />
                </PaneRow>
              ))}
            </ul>
          ) : <p className="text-ink-muted">{t('accounting.reconcile.noLedgerLines')}</p>}
        </Card>
      </div>
      {statementLine && reconcile ? (
        <Card title={t('accounting.reconcile.selected', { description: statementLine.description })}>
          <div className="flex flex-wrap items-end justify-between gap-4">
            <p className="text-body text-ink-muted">
              {t('accounting.reconcile.chosenTotal')} <Amount value={{ amount_minor: chosen.toString(), currency: statementLine.amount.currency }} />
            </p>
            <div className="flex flex-wrap items-end gap-2">
              <Select label={t('accounting.reconcile.postTo')} placeholder={t('accounting.journalForm.chooseAccount')} options={accountOptions(accounts.data?.data)} value={postAccount} onChange={(event) => setPostAccount(event.target.value)} />
              <Button loading={post.isPending} disabled={!postAccount} onClick={() => post.mutate()}>{t('accounting.reconcile.postLine')}</Button>
              <Button variant="primary" loading={match.isPending} disabled={!ledgerIds.length || chosen !== toMinor(statementLine.amount.amount_minor)} onClick={() => match.mutate()}>{t('accounting.reconcile.match')}</Button>
            </div>
          </div>
        </Card>
      ) : null}
      {importing ? <ImportDialog bankId={bankId} onClose={() => setImporting(false)} onDone={async () => { setImporting(false); await refresh() }} /> : null}
    </>
  )
}
