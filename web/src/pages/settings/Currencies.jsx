import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, ListView, MoneyInput, Select, StatusBadge, Switch } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatMinor } from '@/lib/money'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { actionsColumn } from '@/lib/listColumns'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { companyScope, useSettingsCompany, useTenantCurrencies } from './finance/useSettingsCompany'

const MAX_REPORTING = 3
const DECIMAL_CHOICES = [0, 1, 2, 3, 4]

/** "CDF 50": the cash rounding step in its currency. */
function roundingLabel(currency, locale) {
  return `${currency.code} ${formatMinor(currency.cash_rounding_minor, currency.decimals, locale)}`
}

/** Add a currency (CUR-01): an ISO currency the tenant does not use yet, and its cash rounding. */
function AddCurrencyDialog({ existing, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const catalogue = useQuery({ queryKey: ['currencies', 'catalogue'], queryFn: () => api.get('currencies') })
  const options = (catalogue.data?.data ?? []).filter((currency) => currency.active_in_iso && !existing.includes(currency.code))
  const [code, setCode] = useState('')
  const [rounding, setRounding] = useState('')
  const [localError, setLocalError] = useState(null)
  const [submitted, setSubmitted] = useState(false)
  const choice = options.find((currency) => currency.code === code)

  const mutation = useMutation({
    // Minor units travel as a string of digits (ADR 003); the API's integer rule reads it.
    mutationFn: () => api.post('tenant/currencies', { code, ...(rounding ? { cash_rounding_minor: rounding } : {}) }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['tenant-currencies'] })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['code', 'cash_rounding_minor'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('currencies.add.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('currencies.add.submit')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          setSubmitted(true)
          if (!code) return setLocalError(t('currencies.add.chooseCurrency'))
          // An invalid amount shows its own reason under the field (showErrors).
          if (rounding === null) return
          setLocalError(null)
          mutation.mutate()
        }}
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(mutation.error)} />
          </div>
        ) : null}
        <Select
          label={t('currencies.fields.currency')}
          placeholder={catalogue.isPending ? t('common.loading') : t('currencies.add.choose')}
          options={options.map((currency) => ({ value: currency.code, label: `${currency.code} · ${currency.name}` }))}
          value={code}
          onChange={(event) => {
            setCode(event.target.value)
            setRounding('')
          }}
          error={errors.fields.code ?? (localError && !code ? localError : undefined)}
          required
        />
        {choice ? (
          <MoneyInput
            key={choice.code}
            label={t('currencies.fields.rounding')}
            help={t('currencies.fields.roundingHelp')}
            currency={choice.code}
            decimals={choice.default_decimals}
            value={rounding}
            onChange={setRounding}
            error={errors.fields.cash_rounding_minor}
            showErrors={submitted}
          />
        ) : null}
      </form>
    </Dialog>
  )
}

/**
 * Change a tenant currency's decimals (until amounts are stored in it) and
 * cash rounding. The rounding is minor units of the current decimals, so
 * changing the decimals clears it: the user types it again rather than
 * having "50" silently become 0.50 or 5.0.
 */
function EditCurrencyDialog({ currency, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [decimals, setDecimals] = useState(String(currency.decimals))
  const [rounding, setRounding] = useState(String(currency.cash_rounding_minor))
  const [cleared, setCleared] = useState(false)
  const [submitted, setSubmitted] = useState(false)

  const mutation = useMutation({
    mutationFn: () => {
      // Minor units travel as a string of digits (ADR 003); the API's integer rule reads it.
      const body = { cash_rounding_minor: rounding }
      if (!currency.decimals_locked) body.decimals = Number(decimals)
      return api.patch(`tenant/currencies/${currency.id}`, body)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['tenant-currencies'] })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, ['decimals', 'cash_rounding_minor'])
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Dialog
      open
      title={t('currencies.edit.title', { code: currency.code })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('common.save')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          setSubmitted(true)
          if (!rounding) return
          mutation.mutate()
        }}
      >
        {errors.form ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={errorMessage(mutation.error)} />
          </div>
        ) : null}
        <Select
          label={t('currencies.fields.decimals')}
          help={currency.decimals_locked ? t('currencies.fields.decimalsLocked') : t('currencies.fields.decimalsHelp')}
          options={DECIMAL_CHOICES.map((value) => ({ value: String(value), label: String(value) }))}
          value={decimals}
          onChange={(event) => {
            setDecimals(event.target.value)
            setRounding('')
            setCleared(true)
          }}
          disabled={currency.decimals_locked}
          error={errors.fields.decimals}
        />
        <MoneyInput
          key={decimals}
          label={t('currencies.fields.rounding')}
          help={cleared ? t('currencies.fields.roundingCleared') : t('currencies.fields.roundingHelp')}
          currency={currency.code}
          decimals={Number(decimals)}
          value={rounding}
          onChange={setRounding}
          error={errors.fields.cash_rounding_minor ?? (submitted && rounding === '' ? t('currencies.fields.roundingRequired') : undefined)}
          showErrors={submitted}
          required
        />
      </form>
    </Dialog>
  )
}

/** CUR-02: the company's base currency (locked after the first posting) and up to three reporting currencies. */
function CompanyCurrencies({ company, currencies }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: ['company-currencies', company.id], queryFn: () => api.get(`companies/${company.id}/currencies`) })
  const data = query.data?.data
  const [saved, setSaved] = useState(false)

  return (
    <Card
      title={company.name}
      subtitle={t('currencies.company.subtitle')}
      actions={data?.base_currency_locked ? <StatusBadge tone="neutral">{t('currencies.company.locked')}</StatusBadge> : null}
    >
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {data ? (
        <CompanyCurrenciesForm
          key={`${data.base_currency}:${data.reporting_currencies.join(',')}`}
          company={company}
          data={data}
          currencies={currencies}
          saved={saved}
          onSaved={setSaved}
        />
      ) : null}
    </Card>
  )
}

function CompanyCurrenciesForm({ company, data, currencies, saved, onSaved }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const canEdit = can('core.currency.edit', companyScope(company))
  const alertRef = useRef(null)
  const formRef = useRef(null)
  const [base, setBase] = useState(data.base_currency)
  const [reporting, setReporting] = useState(() => [...data.reporting_currencies, '', '', ''].slice(0, MAX_REPORTING))

  const mutation = useMutation({
    mutationFn: () => api.put(`companies/${company.id}/currencies`, { base_currency: base, reporting_currencies: reporting.filter(Boolean) }),
    onSuccess: (response) => {
      onSaved(true)
      queryClient.setQueryData(['company-currencies', company.id], response)
      queryClient.invalidateQueries({ queryKey: ['companies'] })
    },
  })
  const errors = formErrors(mutation.error, ['base_currency', 'reporting_currencies'])
  useErrorFocus(formRef, alertRef, mutation.error)

  const codes = currencies.map((currency) => currency.code)
  const choices = (current) => (codes.includes(current) || !current ? codes : [...codes, current]).map((code) => ({ value: code, label: code }))

  return (
    <form
      ref={formRef}
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        onSaved(false)
        mutation.mutate()
      }}
    >
      {errors.form ? (
        <div ref={alertRef} tabIndex={-1} className="rounded-md">
          <Alert tone="danger" title={errorMessage(mutation.error)} />
        </div>
      ) : null}
      {saved ? <Alert tone="success" title={t('currencies.company.saved')} /> : null}
      <Select
        label={t('currencies.company.base')}
        help={data.base_currency_locked ? t('currencies.company.baseLocked') : t('currencies.company.baseHelp')}
        className="max-w-field"
        options={choices(base)}
        value={base}
        onChange={(event) => setBase(event.target.value)}
        disabled={!canEdit || data.base_currency_locked}
        error={errors.fields.base_currency}
      />
      <fieldset className="flex flex-col gap-3">
        <legend className="pb-1 text-label text-ink">{t('currencies.company.reporting')}</legend>
        <p className="text-caption text-ink-muted">{t('currencies.company.reportingHelp', { count: MAX_REPORTING })}</p>
        <div className="grid gap-3 sm:grid-cols-3">
          {reporting.map((code, index) => (
            <Select
              key={index}
              label={t('currencies.company.reportingN', { n: index + 1 })}
              options={[{ value: '', label: t('currencies.company.none') }, ...choices(code).filter((option) => option.value !== base)]}
              value={code}
              onChange={(event) => setReporting((current) => current.map((value, i) => (i === index ? event.target.value : value)))}
              disabled={!canEdit}
            />
          ))}
        </div>
        {errors.fields.reporting_currencies ? <p className="text-caption text-danger">{errors.fields.reporting_currencies}</p> : null}
      </fieldset>
      {canEdit ? (
        <div>
          <Button variant="primary" type="submit" loading={mutation.isPending}>
            {t('currencies.company.save')}
          </Button>
        </div>
      ) : null}
    </form>
  )
}

/**
 * CUR-01, CUR-02: the currencies the business uses (decimals, cash
 * rounding, on or off) and each company's base and reporting currencies.
 */
export default function Currencies() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { tenantWide } = usePermissions()
  const canEdit = tenantWide('core.currency.edit')
  const { company, picker, ready } = useSettingsCompany()
  const currencies = useTenantCurrencies()
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState(null)

  const toggle = useMutation({
    mutationFn: ({ currency, active }) => api.patch(`tenant/currencies/${currency.id}`, { active }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['tenant-currencies'] }),
  })

  const columns = [
    {
      key: 'code',
      label: t('currencies.columns.currency'),
      sortKey: 'code',
      hideable: false,
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-ink">{row.code}</span>
          <span className="text-caption text-ink-muted">{row.name}</span>
        </div>
      ),
    },
    { key: 'name', label: t('currencies.columns.name'), defaultHidden: true, render: (row) => row.name },
    { key: 'decimals', label: t('currencies.columns.decimals'), sortKey: 'decimals', numeric: true, align: 'end' },
    {
      key: 'rounding',
      label: t('currencies.columns.rounding'),
      sortKey: 'cash_rounding',
      exportKey: 'cash_rounding',
      numeric: true,
      align: 'end',
      render: (row) => roundingLabel(row, locale),
    },
    {
      key: 'active',
      label: t('currencies.columns.status'),
      sortKey: 'status',
      exportKey: 'status',
      render: (row) =>
        canEdit ? (
          <span className="flex items-center gap-2">
            <Switch
              checked={row.active}
              aria-label={t('currencies.toggle', { code: row.code })}
              disabled={toggle.isPending}
              onChange={(active) => toggle.mutate({ currency: row, active })}
            />
            <span className="text-ink-muted">{row.active ? t('currencies.status.active') : t('currencies.status.inactive')}</span>
          </span>
        ) : (
          <StatusBadge tone={row.active ? 'success' : 'neutral'}>{row.active ? t('currencies.status.active') : t('currencies.status.inactive')}</StatusBadge>
        ),
    },
    ...(canEdit
      ? [
          actionsColumn(t('currencies.columns.actions'), (row) => (
            <Button variant="ghost" icon="edit" onClick={() => setEditing(row)} aria-label={t('currencies.editCode', { code: row.code })}>
              {t('currencies.edit.action')}
            </Button>
          )),
        ]
      : []),
  ]
  // The table pages (the pickers read every currency through useTenantCurrencies).
  const list = useServerList({ id: 'tenant-currencies', endpoint: 'tenant/currencies', queryKey: ['tenant-currencies'], columns })

  return (
    <>
      <PageHeader
        title={t('settings.currencies.title')}
        description={t('settings.currencies.description')}
        actions={
          canEdit ? (
            <Button variant="primary" icon="plus" onClick={() => setAdding(true)}>
              {t('currencies.add.action')}
            </Button>
          ) : null
        }
      />
      {toggle.isError ? <Alert tone="danger" title={errorMessage(toggle.error)} /> : null}
      <section className="flex flex-col gap-3" aria-labelledby="tenant-currencies-title">
        <h2 id="tenant-currencies-title" className="text-h2 text-ink">
          {t('currencies.tenant.title')}
        </h2>
        <p className="text-ink-muted">{t('currencies.tenant.description')}</p>
        <ListView
          list={list}
          title={t('currencies.tenant.title')}
          searchPlaceholder={t('currencies.searchPlaceholder')}
          emptyText={list.term ? t('currencies.emptyFiltered') : t('currencies.tenant.empty')}
        />
      </section>
      {picker}
      {ready && company ? <CompanyCurrencies key={company.id} company={company} currencies={currencies.active} /> : null}

      {adding ? <AddCurrencyDialog existing={currencies.all.map((currency) => currency.code)} onClose={() => setAdding(false)} /> : null}
      {editing ? <EditCurrencyDialog key={editing.id} currency={editing} onClose={() => setEditing(null)} /> : null}
    </>
  )
}
