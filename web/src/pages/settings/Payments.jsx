import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, ListView, Select, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { actionsColumn } from '@/lib/listColumns'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { Amount } from '@/pages/pos/PosParts'
import { useAmountText } from '@/pages/pos/useAmountText'
import { companyScope, useSettingsCompany } from './finance/useSettingsCompany'

const INTENT_STATUSES = ['pending', 'unknown', 'succeeded', 'failed', 'cancelled', 'timeout']
const INTENT_TONES = { pending: 'info', unknown: 'warning', succeeded: 'success', failed: 'danger', cancelled: 'neutral', timeout: 'warning' }
const VERIFICATION_TONES = { unverified: 'warning', verified: 'success', mismatch: 'danger' }
const RECEIPT_FILTERS = ['unmatched', 'matched', 'all']
/** A receipt can settle a code typed at the till still to verify, or a push that never got its answer. */
const matchable = (intent) => intent.verification === 'unverified' || ['pending', 'unknown', 'timeout'].includes(intent.status)

/**
 * Match money received (a C2B receipt) to one of the company's payment
 * requests (`core.payment.match`): a code typed at the till still to
 * verify, or a phone prompt that never got its answer.
 */
function MatchDialog({ receipt, company, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const amount = useAmountText()
  const queryClient = useQueryClient()
  const [intentId, setIntentId] = useState('')
  const candidates = useQuery({
    queryKey: ['payment-intents', company.id, 'matchable'],
    queryFn: () => api.get(`companies/${company.id}/payment-intents?status=all&sort=-created_at&per_page=100`),
  })
  const options = (candidates.data?.data ?? []).filter(matchable).map((intent) => ({
    value: intent.id,
    label: [amount(intent.amount), intent.reference ?? intent.receipt ?? intent.account_reference, formatCompanyTime(intent.created_at, locale, company)].filter(Boolean).join(' · '),
  }))
  const match = useMutation({
    mutationFn: () => api.post(`payment-receipts/${receipt.id}/match`, { payment_intent_id: intentId }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['payment-receipts'] })
      await queryClient.invalidateQueries({ queryKey: ['payment-intents'] })
      onClose()
    },
  })
  const errors = formErrors(match.error, ['payment_intent_id'])

  return (
    <Dialog
      open
      title={t('payments.match.title', { receipt: receipt.receipt })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={match.isPending} disabled={!intentId} onClick={() => match.mutate()}>
            {t('payments.match.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errorMessage(match.error)} /> : null}
        <p>{t('payments.match.text', { amount: amount(receipt.amount), reference: receipt.account_reference ?? '—' })}</p>
        {candidates.isPending ? (
          <p className="text-ink-muted">{t('common.loading')}</p>
        ) : options.length ? (
          <Select
            label={t('payments.match.intent')}
            placeholder={t('payments.match.choose')}
            options={options}
            value={intentId}
            onChange={(event) => setIntentId(event.target.value)}
            error={errors.fields.payment_intent_id}
          />
        ) : (
          <p className="text-ink-muted">{t('payments.match.none')}</p>
        )}
      </div>
    </Dialog>
  )
}

/** Payment requests from the tills (STK pushes, typed codes, refund payouts), with the unverified and mismatch filters. */
function IntentsList({ company }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const columns = [
    { key: 'created_at', label: t('payments.intents.columns.time'), sortKey: 'created_at', hideable: false, render: (row) => <span className="tabular-nums">{formatCompanyTime(row.created_at, locale, company)}</span> },
    { key: 'purpose', label: t('payments.intents.columns.purpose'), render: (row) => t(`payments.purposes.${row.purpose}`, { defaultValue: row.purpose }) },
    { key: 'mode', label: t('payments.intents.columns.mode'), render: (row) => t(`payments.modes.${row.mode}`, { defaultValue: row.mode }) },
    { key: 'amount', label: t('payments.intents.columns.amount'), sortKey: 'amount', align: 'end', render: (row) => <Amount value={row.amount} /> },
    { key: 'phone', label: t('payments.intents.columns.phone'), render: (row) => <span className="tabular-nums">{row.phone ?? ''}</span> },
    { key: 'reference', label: t('payments.intents.columns.reference'), sortKey: 'reference', render: (row) => <span className="tabular-nums">{row.reference ?? ''}</span> },
    { key: 'receipt', label: t('payments.intents.columns.receipt'), render: (row) => <span className="font-mono text-caption">{row.receipt ?? ''}</span> },
    { key: 'status', label: t('payments.intents.columns.status'), sortKey: 'status', render: (row) => <StatusBadge tone={INTENT_TONES[row.status]}>{t(`payments.statuses.${row.status}`, { defaultValue: row.status })}</StatusBadge> },
    {
      key: 'verification',
      label: t('payments.intents.columns.verification'),
      render: (row) =>
        row.verification ? <StatusBadge tone={VERIFICATION_TONES[row.verification]}>{t(`payments.verifications.${row.verification}`, { defaultValue: row.verification })}</StatusBadge> : null,
    },
    { key: 'message', label: t('payments.intents.columns.message'), defaultHidden: true, wrap: true, render: (row) => row.message ?? '' },
  ]
  const list = useServerList({
    id: 'payment-intents',
    endpoint: `companies/${company.id}/payment-intents`,
    queryKey: ['payment-intents', company.id],
    filters: { status: '' },
    defaultSort: '-created_at',
    columns,
  })
  return (
    <ListView
      list={list}
      title={t('payments.tabs.intents')}
      searchLabel={t('payments.intents.search')}
      filterFields={[
        {
          name: 'status',
          label: t('payments.intents.filters.status'),
          options: [
            { value: '', label: t('payments.intents.filters.all') },
            { value: 'unverified', label: t('payments.verifications.unverified') },
            { value: 'mismatch', label: t('payments.verifications.mismatch') },
            ...INTENT_STATUSES.map((status) => ({ value: status, label: t(`payments.statuses.${status}`) })),
          ],
        },
      ]}
      emptyText={list.term || list.filters.status ? t('payments.intents.emptyFiltered') : t('payments.intents.empty')}
    />
  )
}

/** Money received on the company's Till or Paybill that matched no request (C2B), with "Match to payment". */
function ReceiptsList({ company, canMatch }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [matching, setMatching] = useState(null)
  const columns = [
    { key: 'transacted_at', label: t('payments.receipts.columns.time'), sortKey: 'transacted_at', hideable: false, render: (row) => <span className="tabular-nums">{formatCompanyTime(row.transacted_at, locale, company)}</span> },
    { key: 'receipt', label: t('payments.receipts.columns.receipt'), sortKey: 'receipt', render: (row) => <span className="font-mono text-caption">{row.receipt}</span> },
    { key: 'amount', label: t('payments.receipts.columns.amount'), sortKey: 'amount', align: 'end', render: (row) => <Amount value={row.amount} /> },
    { key: 'account_reference', label: t('payments.receipts.columns.accountReference'), render: (row) => row.account_reference ?? '' },
    {
      key: 'status',
      label: t('payments.receipts.columns.status'),
      sortKey: 'status',
      render: (row) => <StatusBadge tone={row.status === 'matched' ? 'success' : 'warning'}>{t(`payments.receipts.status.${row.status}`)}</StatusBadge>,
    },
    ...(canMatch
      ? [
          actionsColumn(t('payments.receipts.columns.actions'), (row) =>
            row.status === 'unmatched' ? (
              <Button variant="secondary" onClick={() => setMatching(row)} aria-label={t('payments.match.actionFor', { receipt: row.receipt })}>
                {t('payments.match.action')}
              </Button>
            ) : null,
          ),
        ]
      : []),
  ]
  const list = useServerList({
    id: 'payment-receipts',
    endpoint: `companies/${company.id}/payment-receipts`,
    queryKey: ['payment-receipts', company.id],
    filters: { status: 'unmatched' },
    defaultSort: '-created_at',
    columns,
  })
  return (
    <>
      <ListView
        list={list}
        title={t('payments.tabs.receipts')}
        searchLabel={t('payments.receipts.search')}
        filterFields={[
          { name: 'status', label: t('payments.receipts.filters.status'), options: RECEIPT_FILTERS.map((value) => ({ value, label: t(`payments.receipts.filters.${value}`) })) },
        ]}
        emptyText={t('payments.receipts.empty')}
      />
      {matching ? <MatchDialog receipt={matching} company={company} onClose={() => setMatching(null)} /> : null}
    </>
  )
}

/**
 * Concept note 7.1: a company's mobile money payments: the requests the
 * tills made, and money received that matched none, to match by hand.
 */
export default function Payments() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const { company, picker, ready } = useSettingsCompany()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'receipts' ? 'receipts' : 'intents'
  const canMatch = company ? can('core.payment.match', companyScope(company)) : false

  return (
    <>
      <PageHeader title={t('payments.title')} description={t('payments.description')} />
      {picker}
      {!ready ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {ready && !company ? <Alert tone="info" title={t('finance.company.none')} /> : null}
      {company ? (
        <>
          <Tabs
            items={[
              { value: 'intents', label: t('payments.tabs.intents') },
              { value: 'receipts', label: t('payments.tabs.receipts') },
            ]}
            value={tab}
            onChange={(next) => setParams(next === 'receipts' ? { tab: 'receipts' } : {}, { replace: true })}
          />
          {tab === 'receipts' ? <ReceiptsList key={company.id} company={company} canMatch={canMatch} /> : <IntentsList key={company.id} company={company} />}
        </>
      ) : null}
    </>
  )
}
