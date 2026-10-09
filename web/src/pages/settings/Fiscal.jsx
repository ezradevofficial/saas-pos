import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Dialog, ListView, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { todayIn } from '@/lib/dates'
import { actionsColumn } from '@/lib/listColumns'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { companyScope, useSettingsCompany } from './finance/useSettingsCompany'

const IDENTITY = ['tin', 'branch_code', 'device_serial']
const DEFAULT_CODES = ['default_item_class_code', 'default_packaging_unit_code', 'default_quantity_unit_code']
/** Credentials a person types (the eTIMS key comes from initialising, never typed). */
const TYPED_CREDENTIALS = { dgi_emcf: ['api_token'] }
const STATUS_TONES = { queued: 'info', sending: 'info', retrying: 'warning', accepted: 'success', rejected: 'danger', needs_attention: 'danger' }
const STATUS_FILTERS = ['all', 'pending', 'accepted', 'rejected', 'needs_attention', 'retrying', 'queued', 'sending']
const settingsKey = (companyId) => ['fiscal-settings', companyId]

/** A missing requirement in words ("KRA PIN", "Communication key: initialise the device"). */
function useMissingLabel() {
  const { t } = useTranslation()
  return (field) => t(`fiscal.fields.${field.replace('credentials.', 'credential_')}`, { defaultValue: field })
}

/**
 * Concept note 7.2: the company's link to the tax authority. The identity
 * the authority registered (PIN, branch id, device serial), the driver,
 * credentials and switching transmission on need `core.fiscal.configure`;
 * the default item and unit codes need `core.fiscal.edit`. Credentials are
 * write-only. Changing the identity drops the authority's key: initialise
 * the device again.
 */
function SettingsCard({ company, settings, drivers, canEdit, canConfigure }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const missingLabel = useMissingLabel()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState(() => ({
    driver: settings?.driver ?? drivers[0] ?? '',
    ...Object.fromEntries(IDENTITY.map((field) => [field, settings?.[field] ?? ''])),
    ...Object.fromEntries(DEFAULT_CODES.map((field) => [field, settings?.settings?.[field] ?? ''])),
    credentials: {},
  }))
  const set = (name) => (event) => setValues((current) => ({ ...current, [name]: event.target.value }))
  const refresh = () => queryClient.invalidateQueries({ queryKey: settingsKey(company.id) })

  const body = () => {
    const out = {}
    if (canConfigure) {
      out.driver = values.driver
      for (const field of IDENTITY) out[field] = values[field].trim() || null
      const typed = Object.fromEntries(Object.entries(values.credentials).filter(([, value]) => value !== ''))
      if (Object.keys(typed).length) out.credentials = typed
    }
    out.settings = Object.fromEntries(DEFAULT_CODES.map((field) => [field, values[field].trim() || null]))
    return out
  }
  const save = useMutation({ mutationFn: () => api.put(`companies/${company.id}/fiscal-settings`, body()), onSuccess: refresh })
  const toggle = useMutation({ mutationFn: (enabled) => api.put(`companies/${company.id}/fiscal-settings`, { enabled }), onSuccess: refresh })
  const initialise = useMutation({ mutationFn: () => api.post(`companies/${company.id}/fiscal-settings/initialize`, {}), onSuccess: refresh })
  const errors = formErrors(save.error, [...IDENTITY, 'driver', 'settings', 'credentials'])
  useErrorFocus(formRef, alertRef, save.error)
  const typedCredentials = TYPED_CREDENTIALS[values.driver] ?? []
  const missing = settings?.missing ?? []

  return (
    <Card
      title={t('fiscal.settings.title')}
      subtitle={
        settings?.initialized_at
          ? t('fiscal.settings.initialisedAt', { time: formatCompanyTime(settings.initialized_at, locale, company) })
          : t('fiscal.settings.notInitialised')
      }
      actions={
        canConfigure && settings ? (
          <Button icon="sync" loading={initialise.isPending} onClick={() => initialise.mutate()}>
            {t('fiscal.settings.initialise')}
          </Button>
        ) : null
      }
    >
      <div className="flex flex-col gap-4">
        {initialise.isError ? <Alert tone="danger" title={errorMessage(initialise.error)} /> : null}
        {initialise.isSuccess ? <Alert tone="success" title={t('fiscal.settings.initialised')} /> : null}
        <div className="flex flex-col gap-1">
          <Switch
            label={t('fiscal.settings.enabled')}
            checked={Boolean(settings?.enabled)}
            disabled={!canConfigure || !settings || toggle.isPending}
            onChange={(enabled) => toggle.mutate(enabled)}
          />
          {toggle.isError ? <p className="text-caption text-danger">{errorMessage(toggle.error)}</p> : null}
          {missing.length ? (
            <p className="text-caption text-ink-muted">{t('fiscal.settings.missing', { fields: missing.map(missingLabel).join(', ') })}</p>
          ) : null}
        </div>
        <form
          id={formId}
          ref={formRef}
          noValidate
          autoComplete="off"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate()
          }}
          className="flex flex-col gap-4"
        >
          {errors.form ? (
            <div ref={alertRef} tabIndex={-1} className="rounded-md">
              <Alert tone="danger" title={errorMessage(save.error)} />
            </div>
          ) : null}
          {save.isSuccess ? <Alert tone="success" title={t('fiscal.settings.saved')} /> : null}
          <fieldset className="flex flex-col gap-4">
            <legend className="pb-1 text-label text-ink">{t('fiscal.settings.identity')}</legend>
            <p className="text-caption text-ink-muted">{canConfigure ? t('fiscal.settings.identityHelp') : t('fiscal.settings.identityLocked')}</p>
            <Select
              label={t('fiscal.fields.driver')}
              options={drivers.map((driver) => ({ value: driver, label: t(`fiscal.drivers.${driver}`, { defaultValue: driver }) }))}
              value={values.driver}
              onChange={set('driver')}
              disabled={!canConfigure}
              error={errors.fields.driver}
            />
            <div className="grid gap-4 sm:grid-cols-3">
              {IDENTITY.map((field) => (
                <TextField key={field} label={t(`fiscal.fields.${field}`)} value={values[field]} onChange={set(field)} disabled={!canConfigure} error={errors.fields[field]} className="font-mono" />
              ))}
            </div>
            {canConfigure
              ? typedCredentials.map((key) => (
                  <TextField
                    key={key}
                    type="password"
                    autoComplete="new-password"
                    label={t(`fiscal.fields.credential_${key}`)}
                    help={settings?.credentials_set?.[key] ? t('fiscal.settings.credentialSaved') : t('fiscal.settings.credentialHelp')}
                    value={values.credentials[key] ?? ''}
                    onChange={(event) => setValues((current) => ({ ...current, credentials: { ...current.credentials, [key]: event.target.value } }))}
                    error={errors.fields.credentials}
                  />
                ))
              : null}
          </fieldset>
          <fieldset className="flex flex-col gap-4">
            <legend className="pb-1 text-label text-ink">{t('fiscal.settings.codes')}</legend>
            <p className="text-caption text-ink-muted">{t('fiscal.settings.codesHelp')}</p>
            <div className="grid gap-4 sm:grid-cols-3">
              {DEFAULT_CODES.map((field) => (
                <TextField key={field} label={t(`fiscal.fields.${field}`)} value={values[field]} onChange={set(field)} disabled={!canEdit} className="font-mono" />
              ))}
            </div>
            {errors.fields.settings ? <p className="text-caption text-danger">{errors.fields.settings}</p> : null}
          </fieldset>
          {canEdit ? (
            <div className="flex justify-end">
              <Button variant="primary" type="submit" loading={save.isPending}>
                {t('fiscal.settings.save')}
              </Button>
            </div>
          ) : null}
        </form>
      </div>
    </Card>
  )
}

/** "Send earlier sales": never automatic; the person confirms, as these documents reach the authority. */
function SendEarlierDialog({ company, onClose }) {
  const { t } = useTranslation()
  const [from, setFrom] = useState(() => todayIn(company.timezone))
  const [confirm, setConfirm] = useState(false)
  const send = useMutation({ mutationFn: () => api.post(`companies/${company.id}/fiscal-submissions/send-earlier`, { from, confirm }) })
  const errors = formErrors(send.error, ['from', 'confirm'])
  return (
    <Dialog
      open
      title={t('fiscal.earlier.title')}
      onClose={onClose}
      footer={
        send.isSuccess ? (
          <Button variant="primary" onClick={onClose}>
            {t('fiscal.earlier.done')}
          </Button>
        ) : (
          <>
            <Button variant="ghost" onClick={onClose}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" loading={send.isPending} disabled={!confirm || !from} onClick={() => send.mutate()}>
              {t('fiscal.earlier.send')}
            </Button>
          </>
        )
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        {send.isSuccess ? (
          <Alert tone="success" title={t('fiscal.earlier.queued', { from })} />
        ) : (
          <>
            <p>{t('fiscal.earlier.text')}</p>
            <TextField type="date" label={t('fiscal.earlier.from')} value={from} max={todayIn(company.timezone)} onChange={(event) => setFrom(event.target.value)} error={errors.fields.from} />
            <Checkbox label={t('fiscal.earlier.confirm')} checked={confirm} onChange={(event) => setConfirm(event.target.checked)} />
            {errors.fields.confirm ? <p className="text-caption text-danger">{errors.fields.confirm}</p> : null}
          </>
        )}
      </div>
    </Dialog>
  )
}

/** The fiscal queue: each sale, refund and void sent to the authority, its status and attempts, with Retry. */
function SubmissionsList({ company, canEdit }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const retry = useMutation({
    mutationFn: (submission) => api.post(`fiscal-submissions/${submission.id}/retry`, {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['fiscal-submissions', company.id] }),
  })
  const when = (value) => (value ? formatCompanyTime(value, locale, company) : '')
  const columns = [
    { key: 'created_at', label: t('fiscal.submissions.columns.queued'), sortKey: 'created_at', hideable: false, render: (row) => <span className="tabular-nums">{when(row.created_at)}</span> },
    { key: 'document_type', label: t('fiscal.submissions.columns.document'), render: (row) => t(`fiscal.documentTypes.${row.document_type}`, { defaultValue: row.document_type }) },
    { key: 'document_number', label: t('fiscal.submissions.columns.number'), render: (row) => <span className="tabular-nums">{row.document_number ?? ''}</span> },
    { key: 'invoice_number', label: t('fiscal.submissions.columns.invoice'), sortKey: 'invoice_number', align: 'end', numeric: true, render: (row) => row.invoice_number ?? '' },
    {
      key: 'status',
      label: t('fiscal.submissions.columns.status'),
      sortKey: 'status',
      render: (row) => (
        <span className="flex flex-col gap-1">
          <StatusBadge tone={STATUS_TONES[row.status]}>{t(`fiscal.statuses.${row.status}`, { defaultValue: row.status })}</StatusBadge>
          {row.status === 'retrying' && row.next_attempt_at ? <span className="text-caption text-ink-muted">{t('fiscal.submissions.next', { time: when(row.next_attempt_at) })}</span> : null}
        </span>
      ),
    },
    { key: 'attempts', label: t('fiscal.submissions.columns.attempts'), sortKey: 'attempts', align: 'end', numeric: true, render: (row) => row.attempts },
    { key: 'error', label: t('fiscal.submissions.columns.error'), wrap: true, render: (row) => (row.error ? <span className="text-caption text-danger">{row.error}</span> : '') },
    ...(canEdit
      ? [
          actionsColumn(t('fiscal.submissions.columns.actions'), (row) =>
            row.status !== 'accepted' && row.status !== 'sending' ? (
              <Button
                variant="secondary"
                loading={retry.isPending && retry.variables?.id === row.id}
                onClick={() => retry.mutate(row)}
                aria-label={t('fiscal.submissions.retryFor', { number: row.document_number ?? row.id })}
              >
                {t('fiscal.submissions.retry')}
              </Button>
            ) : null,
          ),
        ]
      : []),
  ]
  const list = useServerList({
    id: 'fiscal-submissions',
    endpoint: `companies/${company.id}/fiscal-submissions`,
    queryKey: ['fiscal-submissions', company.id],
    filters: { status: 'all' },
    defaultSort: '-created_at',
    columns,
  })
  return (
    <section className="flex flex-col gap-3">
      <h2 className="text-h2 text-ink">{t('fiscal.submissions.title')}</h2>
      {retry.isError ? <Alert tone="danger" title={errorMessage(retry.error)} /> : null}
      <ListView
        list={list}
        title={t('fiscal.submissions.title')}
        searchLabel={t('fiscal.submissions.search')}
        filterFields={[{ name: 'status', label: t('fiscal.submissions.filters.status'), options: STATUS_FILTERS.map((value) => ({ value, label: t(`fiscal.filters.${value}`) })) }]}
        emptyText={t('fiscal.submissions.empty')}
      />
    </section>
  )
}

/**
 * Concept note 7.2 (KRA eTIMS, DRC DGI): a company's fiscal settings, the
 * device's initialisation, the queue of documents sent to the authority
 * with retries, and sending sales made before transmission was on.
 */
export default function Fiscal() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const { company, picker, ready } = useSettingsCompany()
  const [earlier, setEarlier] = useState(false)
  const scope = companyScope(company)
  const canEdit = company ? can('core.fiscal.edit', scope) || can('core.fiscal.configure', scope) : false
  const canConfigure = company ? can('core.fiscal.configure', scope) : false
  const query = useQuery({ queryKey: settingsKey(company?.id), queryFn: () => api.get(`companies/${company.id}/fiscal-settings`), enabled: Boolean(company) })
  const settings = query.data?.data ?? null
  const drivers = settings?.drivers ?? query.data?.meta?.drivers ?? []

  return (
    <>
      <PageHeader
        title={t('fiscal.title')}
        description={t('fiscal.description')}
        actions={
          canEdit && settings?.enabled ? (
            <Button icon="deliveries" onClick={() => setEarlier(true)}>
              {t('fiscal.earlier.action')}
            </Button>
          ) : null
        }
      />
      {picker}
      {!ready || (company && query.isPending) ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {ready && !company ? <Alert tone="info" title={t('finance.company.none')} /> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {company && query.isSuccess ? (
        <div className="flex flex-col gap-5">
          {!settings ? <Alert tone="info" title={t('fiscal.settings.none')} /> : null}
          {drivers.length === 0 ? <Alert tone="warning" title={t('fiscal.settings.noDriver')} /> : null}
          <SettingsCard key={`${company.id}-${settings?.updated_at ?? 'new'}`} company={company} settings={settings} drivers={drivers} canEdit={canEdit} canConfigure={canConfigure} />
          <SubmissionsList key={company.id} company={company} canEdit={canEdit} />
        </div>
      ) : null}
      {earlier && company ? <SendEarlierDialog company={company} onClose={() => setEarlier(false)} /> : null}
    </>
  )
}
