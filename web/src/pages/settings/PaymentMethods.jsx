import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Dialog, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { companyScope, useSettingsCompany } from './finance/useSettingsCompany'

const TYPES = ['cash', 'mobile_money', 'card', 'credit', 'voucher', 'points', 'bank_transfer']
const PROVIDER_TYPES = ['mobile_money', 'card']

const methodsKey = (companyId) => ['payment-methods', companyId]

/** The methods grouped by type (cash first), each group in till order. */
function groupByType(methods) {
  const sorted = [...methods].sort((a, b) => a.position - b.position)
  return TYPES.map((type) => ({ type, methods: sorted.filter((method) => method.type === type) })).filter((group) => group.methods.length > 0)
}

/** A provider key's label ("Consumer key"); never the raw key. */
function useKeyLabel() {
  const { t } = useTranslation()
  return (key) => t(`paymentMethods.keys.${key}`, { defaultValue: t('paymentMethods.keys.other') })
}

/** "shortcode, consumer key and passkey" in the UI language. */
function useKeyList() {
  const label = useKeyLabel()
  const locale = useLocale()
  return (keys) => new Intl.ListFormat(locale, { style: 'long', type: 'conjunction' }).format(keys.map((key) => label(key)))
}

function methodName(method, locale) {
  return (locale === 'fr' ? method.name_fr : method.name_en) ?? method.name
}

/**
 * MD-04: a provider's settings and secrets. Saved secrets are never shown:
 * the field reads "Saved" and offers Replace or Remove; a new value is
 * typed into a password field.
 */
function ProviderSettingsDialog({ method, companyId, onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const label = useKeyLabel()
  const queryClient = useQueryClient()
  const formId = useId()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const settingKeys = method.setting_keys ?? []
  const secretKeys = method.secret_keys ?? []
  const [settings, setSettings] = useState(() => Object.fromEntries(settingKeys.map((key) => [key, method.settings?.[key] ?? ''])))
  const [secrets, setSecrets] = useState(() => Object.fromEntries(secretKeys.map((key) => [key, ''])))
  // Per saved secret: 'saved' (kept), 'replace' (a new value typed) or 'remove'.
  const [secretState, setSecretState] = useState(() => Object.fromEntries(secretKeys.map((key) => [key, method.secrets_set?.[key] ? 'saved' : 'replace'])))

  const body = () => {
    const changedSettings = {}
    for (const key of settingKeys) {
      const value = settings[key].trim()
      if (value !== (method.settings?.[key] ?? '')) changedSettings[key] = value === '' ? null : value
    }
    const changedSecrets = {}
    for (const key of secretKeys) {
      if (secretState[key] === 'remove') changedSecrets[key] = null
      else if (secretState[key] === 'replace' && secrets[key] !== '') changedSecrets[key] = secrets[key]
    }
    return {
      ...(Object.keys(changedSettings).length ? { settings: changedSettings } : {}),
      ...(Object.keys(changedSecrets).length ? { secrets: changedSecrets } : {}),
    }
  }

  const mutation = useMutation({
    mutationFn: () => api.patch(`payment-methods/${method.id}`, body()),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: methodsKey(companyId) })
      onClose()
    },
  })
  const errors = formErrors(mutation.error, [])
  useErrorFocus(formRef, alertRef, mutation.error)
  const keyList = useKeyList()
  const formError =
    mutation.error?.code === 'provider_not_configured'
      ? t('paymentMethods.errors.clearWhileOn', { name: methodName(method, locale) })
      : errors.form
        ? errorMessage(mutation.error)
        : null

  return (
    <Dialog
      open
      title={t('paymentMethods.settings.title', { name: methodName(method, locale) })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" form={formId} loading={mutation.isPending}>
            {t('paymentMethods.settings.save')}
          </Button>
        </>
      }
    >
      <form
        id={formId}
        ref={formRef}
        noValidate
        autoComplete="off"
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
      >
        {formError ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={formError} />
          </div>
        ) : null}
        <p>{method.missing?.length ? t('paymentMethods.settings.missing', { keys: keyList(method.missing) }) : t('paymentMethods.settings.complete')}</p>
        {settingKeys.map((key) => (
          <TextField
            key={key}
            label={label(key)}
            value={settings[key]}
            onChange={(event) => setSettings((current) => ({ ...current, [key]: event.target.value }))}
          />
        ))}
        {secretKeys.length ? (
          <fieldset className="flex flex-col gap-4">
            <legend className="pb-1 text-label text-ink">{t('paymentMethods.settings.secrets')}</legend>
            <p className="text-caption text-ink-muted">{t('paymentMethods.settings.secretsHelp')}</p>
            {secretKeys.map((key) =>
              secretState[key] === 'replace' ? (
                <div key={key} className="flex items-end gap-2">
                  <TextField
                    className="flex-1"
                    type="password"
                    autoComplete="new-password"
                    label={label(key)}
                    value={secrets[key]}
                    onChange={(event) => setSecrets((current) => ({ ...current, [key]: event.target.value }))}
                  />
                  {method.secrets_set?.[key] ? (
                    <Button
                      variant="ghost"
                      onClick={() => {
                        setSecrets((current) => ({ ...current, [key]: '' }))
                        setSecretState((current) => ({ ...current, [key]: 'saved' }))
                      }}
                    >
                      {t('paymentMethods.settings.keep')}
                    </Button>
                  ) : null}
                </div>
              ) : (
                <div key={key} className="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                  <div className="flex flex-col gap-1">
                    <span className="text-label text-ink">{label(key)}</span>
                    {secretState[key] === 'remove' ? (
                      <StatusBadge tone="warning">{t('paymentMethods.settings.willRemove')}</StatusBadge>
                    ) : (
                      <StatusBadge tone="success">{t('paymentMethods.settings.saved')}</StatusBadge>
                    )}
                  </div>
                  <div className="flex gap-1">
                    {secretState[key] === 'remove' ? (
                      <Button variant="ghost" onClick={() => setSecretState((current) => ({ ...current, [key]: 'saved' }))}>
                        {t('paymentMethods.settings.keep')}
                      </Button>
                    ) : (
                      <>
                        <Button
                          variant="ghost"
                          icon="key"
                          aria-label={t('paymentMethods.settings.replaceKey', { key: label(key) })}
                          onClick={() => setSecretState((current) => ({ ...current, [key]: 'replace' }))}
                        >
                          {t('paymentMethods.settings.replace')}
                        </Button>
                        <Button
                          variant="ghost"
                          icon="remove"
                          aria-label={t('paymentMethods.settings.removeKey', { key: label(key) })}
                          onClick={() => setSecretState((current) => ({ ...current, [key]: 'remove' }))}
                        >
                          {t('paymentMethods.settings.remove')}
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              ),
            )}
          </fieldset>
        ) : null}
      </form>
    </Dialog>
  )
}

function MethodRow({ method, canEdit, first, last, onMove, onToggle, onSettings, toggling, error }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const name = methodName(method, locale)
  const meta = method.provider ? t(`paymentMethods.providers.${method.provider}`, { defaultValue: t('paymentMethods.providers.other') }) : method.currency
  const status = method.active
    ? { tone: 'success', label: t('paymentMethods.status.on') }
    : PROVIDER_TYPES.includes(method.type) && !method.configured
      ? { tone: 'warning', label: t('paymentMethods.status.setup') }
      : { tone: 'neutral', label: t('paymentMethods.status.off') }

  return (
    <li className="flex flex-col gap-2 py-3">
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <div className="flex min-w-0 grow basis-full flex-col sm:basis-0">
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span className="font-medium text-ink">{name}</span>
            <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
          </span>
          {meta && meta !== name ? <span className="text-caption text-ink-muted">{meta}</span> : null}
        </div>
        <div className="flex flex-wrap items-center gap-1">
          {canEdit && PROVIDER_TYPES.includes(method.type) ? (
            <Button variant="ghost" icon="key" onClick={onSettings} aria-label={t('paymentMethods.settings.actionFor', { name })}>
              {t('paymentMethods.settings.action')}
            </Button>
          ) : null}
          {canEdit ? (
            <>
              <Button variant="ghost" icon="up" className="size-icon-btn px-0" disabled={first} onClick={() => onMove(-1)} aria-label={t('paymentMethods.moveUp', { name })} />
              <Button variant="ghost" icon="down" className="size-icon-btn px-0" disabled={last} onClick={() => onMove(1)} aria-label={t('paymentMethods.moveDown', { name })} />
            </>
          ) : null}
          <Switch
            className="ml-2"
            checked={method.active}
            disabled={!canEdit || toggling}
            aria-label={t('paymentMethods.toggle', { name })}
            onChange={onToggle}
          />
        </div>
      </div>
      {error ? <Alert tone="danger" title={error} /> : null}
    </li>
  )
}

/**
 * MD-04: a company's payment methods by type, in till order (moved with
 * buttons, so keyboards work), switched on only when their provider is
 * set up, and the provider settings (secrets never shown).
 */
export default function PaymentMethods() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const keyList = useKeyList()
  const { company, picker, ready } = useSettingsCompany()
  const canEdit = company ? can('core.payment_method.edit', companyScope(company)) : false
  const [settingsFor, setSettingsFor] = useState(null)
  const [rowError, setRowError] = useState(null) // { id, message }

  const methods = useQuery({
    queryKey: methodsKey(company?.id),
    queryFn: () => api.get(`companies/${company.id}/payment-methods?per_page=200`),
    enabled: Boolean(company),
  })
  const list = methods.data?.data ?? []
  const groups = groupByType(list)

  const notConfigured = (method) =>
    method.missing?.length
      ? t('paymentMethods.errors.notConfigured', { name: methodName(method, locale), keys: keyList(method.missing) })
      : t('paymentMethods.errors.notConfiguredAny', { name: methodName(method, locale) })

  const toggle = useMutation({
    mutationFn: ({ method, active }) => api.patch(`payment-methods/${method.id}`, { active }),
    onMutate: () => setRowError(null),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: methodsKey(company.id) }),
    onError: (error, { method }) => {
      const name = methodName(method, locale)
      const message =
        error.code === 'provider_not_configured'
          ? notConfigured(method)
          : error.code === 'currency_not_active'
            ? t('paymentMethods.errors.currencyNotActive', { name, currency: method.currency })
            : errorMessage(error)
      setRowError({ id: method.id, message })
    },
  })

  const reorder = useMutation({
    mutationFn: (ids) => api.put(`companies/${company.id}/payment-methods/order`, { ids }),
    onMutate: (ids) => {
      setRowError(null)
      const key = methodsKey(company.id)
      const previous = queryClient.getQueryData(key)
      queryClient.setQueryData(key, (data) =>
        data ? { ...data, data: data.data.map((method) => ({ ...method, position: ids.indexOf(method.id) + 1 })) } : data,
      )
      return { previous }
    },
    onError: (_error, _ids, context) => queryClient.setQueryData(methodsKey(company.id), context?.previous),
    onSettled: () => queryClient.invalidateQueries({ queryKey: methodsKey(company.id) }),
  })

  const move = (group, index, step) => {
    const order = groups.map((entry) => entry.methods.map((method) => method.id))
    const ids = order[groups.indexOf(group)]
    ;[ids[index], ids[index + step]] = [ids[index + step], ids[index]]
    reorder.mutate(order.flat())
  }

  return (
    <>
      <PageHeader title={t('settings.paymentMethods.title')} description={t('settings.paymentMethods.description')} />
      {picker}
      {!ready || (company && methods.isPending) ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {ready && !company ? <Alert tone="info" title={t('finance.company.none')} /> : null}
      {methods.isError ? <Alert tone="danger" title={errorMessage(methods.error)} action={<Button onClick={() => methods.refetch()}>{t('common.retry')}</Button>} /> : null}
      {reorder.isError ? <Alert tone="danger" title={errorMessage(reorder.error)} /> : null}
      {company && methods.isSuccess && list.length === 0 ? <Alert tone="info" title={t('paymentMethods.empty')} /> : null}
      <div className="flex flex-col gap-5">
        {groups.map((group) => (
          <Card key={group.type} title={t(`paymentMethods.types.${group.type}`)}>
            <ul aria-label={t(`paymentMethods.types.${group.type}`)} className="-my-3 divide-y divide-border">
              {group.methods.map((method, index) => (
                <MethodRow
                  key={method.id}
                  method={method}
                  canEdit={canEdit}
                  first={index === 0}
                  last={index === group.methods.length - 1}
                  onMove={(step) => move(group, index, step)}
                  onToggle={(active) => {
                    // Known to be refused (provider_not_configured): say why without asking the API.
                    if (active && PROVIDER_TYPES.includes(method.type) && method.missing?.length) {
                      setRowError({ id: method.id, message: notConfigured(method) })
                      return
                    }
                    toggle.mutate({ method, active })
                  }}
                  onSettings={() => setSettingsFor(method)}
                  toggling={toggle.isPending && toggle.variables?.method.id === method.id}
                  error={rowError?.id === method.id ? rowError.message : null}
                />
              ))}
            </ul>
          </Card>
        ))}
      </div>
      {settingsFor && company ? <ProviderSettingsDialog key={settingsFor.id} method={settingsFor} companyId={company.id} onClose={() => setSettingsFor(null)} /> : null}
    </>
  )
}
