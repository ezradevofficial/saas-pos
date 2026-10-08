import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, Money, MoneyInput, Select, StatusBadge, TextField } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { formatCompanyTime } from '@/lib/companyTime'
import { decimalsOf } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { useTenantCurrencies } from '@/pages/settings/finance/useSettingsCompany'
import { CREDIT_REQUEST, creditChangesKey } from './creditLimitData'


const TONES = { draft: 'neutral', pending: 'info', approved: 'warning', applied: 'success', rejected: 'danger', cancelled: 'neutral', conflicted: 'danger' }

/** A request's status: a dot and a word. */
export function CreditStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={TONES[status] ?? 'neutral'}>{t(`creditLimits.statuses.${status}`, { defaultValue: status })}</StatusBadge>
}

/** "KES 150,000.00 → KES 250,000.00" (amounts left out when field rules hide them). */
export function LimitChange({ change }) {
  const { t } = useTranslation()
  if (!change.requested_limit) return null
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      {change.current_limit ? (
        <Money amount={change.current_limit.amount_minor} currency={change.current_limit.currency} />
      ) : (
        <span className="text-ink-muted">{t('creditLimits.noLimit')}</span>
      )}
      <span aria-hidden="true" className="text-ink-muted">
        →
      </span>
      <span className="sr-only">{t('creditLimits.to')}</span>
      <Money amount={change.requested_limit.amount_minor} currency={change.requested_limit.currency} />
    </span>
  )
}

/**
 * The companies a request for `party` may be for: a company's party is its
 * own company's; a shared party, any active company where the user may
 * request (null when only the API can tell: the user cannot list companies).
 */
function useRequestCompanies(party) {
  const { companies, allowed } = useCompanies()
  const { canWithin } = usePermissions()
  if (party.company_id) return []
  if (!allowed) return null
  return companies.filter((company) => !company.archived_at && canWithin(CREDIT_REQUEST, [{ type: 'company', id: company.id }]))
}

/**
 * Ask for a party's credit limit to change: the new limit in the current
 * limit's currency (any active currency when there is none), a reason, and
 * for a shared party the company it is for. The amount goes to the API as a
 * string of minor units, never a number.
 */
export function RequestCreditChangeDialog({ open, party, onClose, onRequested }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const currencies = useTenantCurrencies({ enabled: open && can('core.currency.view') })
  const companies = useRequestCompanies(party)
  const current = party.credit_limit ?? null
  const fixedCurrency = current?.currency ?? null
  const codes = fixedCurrency ? [fixedCurrency] : currencies.active.map((currency) => currency.code)
  const [currency, setCurrency] = useState(fixedCurrency ?? party.currency ?? '')
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [submitted, setSubmitted] = useState(false)
  const chosenCurrency = currency || codes[0] || ''
  const choosesCompany = Array.isArray(companies) && companies.length > 1
  const decimals = decimalsOf(chosenCurrency, currencies.all)

  const mutation = useMutation({
    mutationFn: () =>
      api.post('credit-limit-changes', {
        party_id: party.id,
        ...(choosesCompany ? { company_id: companyId || null } : {}),
        requested_limit: { amount_minor: String(amount), currency: chosenCurrency },
        reason: reason.trim(),
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: creditChangesKey })
      onRequested?.(response.data)
    },
  })
  const errors = formErrors(mutation.error, ['requested_limit', 'reason', 'company_id'], {
    'requested_limit.amount_minor': 'requested_limit',
    'requested_limit.currency': 'requested_limit',
  })
  const missing = {
    amount: submitted && (amount === '' || amount === null) ? t('creditLimits.request.amountRequired') : null,
    reason: submitted && !reason.trim() ? t('creditLimits.request.reasonRequired') : null,
    company: submitted && choosesCompany && !companyId ? t('creditLimits.request.companyRequired') : null,
  }

  const submit = () => {
    setSubmitted(true)
    if (amount === '' || amount === null || !reason.trim() || !chosenCurrency || (choosesCompany && !companyId)) return
    mutation.mutate()
  }

  return (
    <Dialog
      open={open}
      title={t('creditLimits.request.title', { name: party.name ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={mutation.isPending} onClick={submit}>
            {t('creditLimits.request.submit')}
          </Button>
        </>
      }
    >
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
      >
        {errors.form ? <Alert tone="danger" title={errorMessage(mutation.error) ?? errors.form} /> : null}
        <p>{t('creditLimits.request.text')}</p>
        <div className="flex flex-col gap-1">
          <span className="text-label text-ink-muted">{t('creditLimits.current')}</span>
          {current ? <Money amount={current.amount_minor} currency={current.currency} /> : <span className="text-ink">{t('creditLimits.noLimit')}</span>}
        </div>
        {choosesCompany ? (
          <Select
            label={t('creditLimits.request.company')}
            placeholder={t('creditLimits.request.chooseCompany')}
            options={companies.map((company) => ({ value: company.id, label: company.name }))}
            value={companyId}
            onChange={(event) => setCompanyId(event.target.value)}
            error={missing.company ?? errors.fields.company_id}
          />
        ) : null}
        {fixedCurrency ? null : (
          <Select
            label={t('creditLimits.request.currency')}
            options={codes.map((code) => ({ value: code, label: code }))}
            value={chosenCurrency}
            onChange={(event) => {
              // Minor units of the old currency are cleared, never reinterpreted.
              setCurrency(event.target.value)
              setAmount('')
            }}
          />
        )}
        <MoneyInput
          key={chosenCurrency}
          label={t('creditLimits.request.amount')}
          help={fixedCurrency ? t('creditLimits.request.amountHelp', { currency: fixedCurrency }) : undefined}
          currency={chosenCurrency}
          decimals={decimals}
          value={amount}
          onChange={setAmount}
          showErrors={submitted}
          error={missing.amount ?? errors.fields.requested_limit}
        />
        <TextField
          label={t('creditLimits.request.reason')}
          help={t('creditLimits.request.reasonHelp')}
          value={reason}
          maxLength={1000}
          onChange={(event) => setReason(event.target.value)}
          error={missing.reason ?? errors.fields.reason}
        />
      </form>
    </Dialog>
  )
}

/** "3 h 20 min", "2 d 4 h": how long a request has waited at its step. */
function useDuration() {
  const { t } = useTranslation()
  return (seconds) => {
    const minutes = Math.floor((Number(seconds) || 0) / 60)
    const days = Math.floor(minutes / 1440)
    const hours = Math.floor((minutes % 1440) / 60)
    if (days > 0) return t('creditLimits.detail.days', { days, hours })
    if (hours > 0) return t('creditLimits.detail.hours', { hours, minutes: minutes % 60 })
    return t('creditLimits.detail.minutes', { count: minutes })
  }
}

/**
 * WF-10: one request: the change, its status, where its flow is (step,
 * who holds it, how long), its history, the approval's link while one
 * waits, and Cancel for whoever may cancel it (with a reason).
 */
export function CreditChangeDialog({ changeId, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const duration = useDuration()
  const companyOf = useCompanyOfRecord()
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const query = useQuery({ queryKey: [...creditChangesKey, 'detail', changeId], queryFn: () => api.get(`credit-limit-changes/${changeId}`), enabled: Boolean(changeId) })
  const cancel = useMutation({
    mutationFn: () => api.post(`credit-limit-changes/${changeId}/cancel`, { reason: reason.trim() }),
    onSuccess: async (response) => {
      setCancelling(false)
      queryClient.setQueryData([...creditChangesKey, 'detail', changeId], response)
      await queryClient.invalidateQueries({ queryKey: creditChangesKey })
    },
  })
  const change = query.data?.data
  const meta = query.data?.meta ?? {}
  const workflow = meta.workflow
  // L10N-03: the request's company zone, not the switcher's.
  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(change?.company?.id)) : '')

  return (
    <Dialog
      open={Boolean(changeId)}
      size="lg"
      title={change ? t('creditLimits.detail.title', { number: change.number }) : t('common.loading')}
      onClose={onClose}
      footer={
        change?.can_cancel && !cancelling ? (
          <Button variant="danger" onClick={() => setCancelling(true)}>
            {t('creditLimits.detail.cancel')}
          </Button>
        ) : null
      }
    >
      {query.isPending ? <p>{t('common.loading')}</p> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {change ? (
        <div className="flex flex-col gap-5">
          <dl className="grid gap-3 sm:grid-cols-2">
            <div className="flex flex-col gap-1">
              <dt className="text-label text-ink-muted">{t('creditLimits.columns.change')}</dt>
              <dd className="text-ink">
                <LimitChange change={change} />
              </dd>
            </div>
            <div className="flex flex-col gap-1">
              <dt className="text-label text-ink-muted">{t('creditLimits.columns.status')}</dt>
              <dd>
                <CreditStatus status={change.status} />
              </dd>
            </div>
            <div className="flex flex-col gap-1">
              <dt className="text-label text-ink-muted">{t('creditLimits.columns.requestedBy')}</dt>
              <dd className="text-ink">
                {change.requested_by?.name ?? ''} · {when(change.created_at)}
              </dd>
            </div>
            <div className="flex flex-col gap-1">
              <dt className="text-label text-ink-muted">{t('creditLimits.columns.company')}</dt>
              <dd className="text-ink">{change.company?.name ?? ''}</dd>
            </div>
            <div className="flex flex-col gap-1 sm:col-span-2">
              <dt className="text-label text-ink-muted">{t('creditLimits.columns.reason')}</dt>
              <dd className="text-ink">{change.reason}</dd>
            </div>
          </dl>

          {workflow ? (
            <section aria-labelledby="credit-flow" className="flex flex-col gap-3">
              <h3 id="credit-flow" className="text-h3 text-ink">
                {t('creditLimits.detail.flow')}
              </h3>
              {(workflow.current ?? []).map((step) => (
                <div key={step.token_id} className="flex flex-col gap-1 rounded-md border border-border p-3">
                  <span className="font-medium text-ink">{step.name}</span>
                  <span>
                    {step.holders?.blocked
                      ? t('creditLimits.detail.blocked')
                      : t('creditLimits.detail.holders', { names: (step.holders?.users ?? []).map((user) => user.name).join(', ') || t('creditLimits.detail.nobody') })}
                  </span>
                  <span>{t('creditLimits.detail.waiting', { time: duration(step.seconds_in_stage) })}</span>
                </div>
              ))}
              {meta.approval_id ? (
                <Link to={`/approvals/${meta.approval_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
                  {t('creditLimits.detail.openApproval')}
                </Link>
              ) : null}
              <ol className="flex flex-col gap-2" aria-label={t('creditLimits.detail.history')}>
                {(workflow.history ?? []).map((event, index) => (
                  <li key={`${event.type}-${index}`} className="flex flex-wrap gap-x-2 text-body">
                    <span className="text-ink">{t(`creditLimits.events.${event.type}`, { defaultValue: event.type, step: event.node_name ?? '' })}</span>
                    {event.user?.name ? <span>{event.user.name}</span> : null}
                    <span>{when(event.occurred_at)}</span>
                    {event.reason ? <span className="w-full">{event.reason}</span> : null}
                  </li>
                ))}
              </ol>
            </section>
          ) : null}

          {cancelling ? (
            <div className="flex flex-col gap-3 rounded-md border border-border p-3">
              {cancel.isError ? <Alert tone="danger" title={errorMessage(cancel.error)} /> : null}
              <TextField label={t('creditLimits.detail.cancelReason')} value={reason} maxLength={1000} onChange={(event) => setReason(event.target.value)} />
              <div className="flex flex-wrap gap-2">
                <Button variant="ghost" onClick={() => setCancelling(false)}>
                  {t('creditLimits.detail.keep')}
                </Button>
                <Button variant="danger" loading={cancel.isPending} disabled={!reason.trim()} onClick={() => cancel.mutate()}>
                  {t('creditLimits.detail.confirmCancel')}
                </Button>
              </div>
            </div>
          ) : null}
        </div>
      ) : null}
    </Dialog>
  )
}

/** The party's requests, newest first, each opening its detail. */
export function PartyCreditChanges({ party }) {
  const { t } = useTranslation()
  const locale = useLocale()
  // A shared party's requests belong to different companies: each in its own zone.
  const companyOf = useCompanyOfRecord()
  const [openId, setOpenId] = useState(null)
  const query = useQuery({
    queryKey: [...creditChangesKey, 'party', party.id],
    queryFn: () => api.get(`credit-limit-changes?party=${party.id}&per_page=50`),
  })
  const rows = query.data?.data ?? []

  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />

  return (
    <>
      {rows.length === 0 ? (
        <p className="text-ink-muted">{t('creditLimits.emptyParty')}</p>
      ) : (
        <ul className="flex flex-col divide-y divide-border" aria-label={t('creditLimits.title')}>
          {rows.map((change) => (
            <li key={change.id}>
              <button type="button" className="flex w-full flex-wrap items-center gap-x-4 gap-y-1 py-3 text-left hover:bg-surface-300" onClick={() => setOpenId(change.id)}>
                <span className="font-medium text-ink">{change.number}</span>
                <LimitChange change={change} />
                <CreditStatus status={change.status} />
                <span className="text-caption text-ink-muted">{formatCompanyTime(change.created_at, locale, companyOf(change.company?.id))}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
      <CreditChangeDialog changeId={openId} onClose={() => setOpenId(null)} />
    </>
  )
}
