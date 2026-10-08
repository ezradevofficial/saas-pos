import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Icon, Money, MoneyInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useCompanies, useCompanySelection } from '@/layouts/companySelection'
import { rolesPerCompany, useSharingModes } from '@/lib/masterData'
import { decimalsOf, minorToDecimal, toMinor } from '@/lib/money'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useTenantCurrencies } from '@/pages/settings/finance/useSettingsCompany'
import { CREDIT_SET_DIRECTLY } from './creditLimitData'
import { PARTY_KINDS, PARTY_ROLES, ROLE_PATHS } from './partyData'

const COUNTRIES = ['KE', 'CD']

let rowKey = 0
const nextKey = () => `row-${++rowKey}`

function initialValues(party, role) {
  return {
    company_id: party?.company_id ?? '',
    kind: party?.kind ?? (role === 'supplier' ? 'organisation' : 'person'),
    name: party?.name ?? '',
    legal_name: party?.legal_name ?? '',
    tax_id: party?.tax_id ?? '',
    roles: party?.roles ?? [role],
    phones: (party?.phones ?? []).map((phone) => ({ key: nextKey(), number: phone.number ?? '', label: phone.label ?? '' })),
    emails: (party?.emails ?? []).map((email) => ({ key: nextKey(), address: email.address ?? '', label: email.label ?? '' })),
    addresses: (party?.addresses ?? []).map((address) => ({
      key: nextKey(),
      label: address.label ?? '',
      line1: address.line1 ?? '',
      line2: address.line2 ?? '',
      city: address.city ?? '',
      region: address.region ?? '',
      postal_code: address.postal_code ?? '',
      country: address.country ?? '',
    })),
    currency: party?.currency ?? '',
    payment_terms_days: party?.payment_terms_days == null ? '' : String(party.payment_terms_days),
    credit_limit: party?.credit_limit ? String(party.credit_limit.amount_minor) : '',
    credit_limit_currency: party?.credit_limit?.currency ?? '',
    price_list_id: party?.price_list_id ?? '',
    tags: (party?.tags ?? []).join(', '),
    confirmShared: false,
  }
}

const blankToNull = (value) => (value.trim() === '' ? null : value.trim())

/**
 * MD-01: a party's details; `party` null to create one with `role`.
 * Phones, emails and addresses are lists; the credit limit is typed in its
 * currency and sent in major units (ADR 003). A role change that moves the
 * party between shared and one company asks for that choice explicitly
 * (company_change_needs_confirmation). Fields hidden by field rules
 * (RBAC-05) are not in `party`: neither shown nor sent.
 */
export function PartyForm({ party, role, readOnly = false, onSaved }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const { can, canWithin } = usePermissions()
  const { modes } = useSharingModes()
  const { companies } = useCompanies()
  const { company: selected } = useCompanySelection()
  const currencies = useTenantCurrencies({ enabled: can('core.currency.view') })
  const activeCompanies = companies.filter((company) => !company.archived_at)
  const [values, setValues] = useState(() => initialValues(party, role))
  const [submitted, setSubmitted] = useState(false)
  const creating = !party
  const shows = (field) => creating || field in party
  // WF-01: without core.credit_limit.set_directly the limit is read-only here;
  // it changes through a credit limit change request (the API refuses raises).
  const creditLocked = !(party?.company_id ? canWithin(CREDIT_SET_DIRECTLY, [{ type: 'company', id: party.company_id }]) : can(CREDIT_SET_DIRECTLY))

  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const setRow = (list, key, patch) => setValues((current) => ({ ...current, [list]: current[list].map((row) => (row.key === key ? { ...row, ...patch } : row)) }))
  const addRow = (list, row) => setValues((current) => ({ ...current, [list]: [...current[list], { key: nextKey(), ...row }] }))
  const removeRow = (list, key) => setValues((current) => ({ ...current, [list]: current[list].filter((row) => row.key !== key) }))

  // TEN-08: whether the party will be kept per company, and whether that changes.
  const nowPerCompany = rolesPerCompany(values.roles, modes)
  const wasPerCompany = creating ? null : Boolean(party.company_id)
  const rolesChanged = !creating && [...values.roles].sort().join() !== [...(party.roles ?? [])].sort().join()
  const flips = rolesChanged && nowPerCompany !== wasPerCompany
  const chosenCompany = values.company_id || selected?.id || (activeCompanies.length === 1 ? activeCompanies[0].id : '')
  const companyId = creating || flips ? (nowPerCompany ? chosenCompany || null : null) : (party.company_id ?? null)
  const companyRecord = companies.find((company) => company.id === (companyId ?? selected?.id)) ?? selected ?? activeCompanies[0]

  const tenantCodes = currencies.active.map((currency) => currency.code)
  const fallbackCurrency = companyRecord?.base_currency ?? ''
  const currencyCodes = [...new Set([...tenantCodes, ...(tenantCodes.length ? [] : [fallbackCurrency]), values.currency, values.credit_limit_currency].filter(Boolean))].sort()
  const creditCurrency = values.credit_limit_currency || values.currency || fallbackCurrency
  const creditDecimals = decimalsOf(creditCurrency, currencies.all)

  const priceListCompany = companyId ?? selected?.id ?? null
  const canPriceLists = can(['core.price_list.view', 'core.price_list.edit'])
  const priceLists = useQuery({
    queryKey: ['price-lists', priceListCompany],
    queryFn: () => api.get(`companies/${priceListCompany}/price-lists?per_page=200`),
    enabled: Boolean(canPriceLists && priceListCompany && shows('price_list_id')),
  })

  const body = () => {
    const data = {}
    if (creating ? nowPerCompany : flips) data.company_id = nowPerCompany ? chosenCompany || null : null
    if (shows('kind')) data.kind = values.kind
    if (shows('name')) data.name = values.name.trim()
    if (shows('legal_name')) data.legal_name = blankToNull(values.legal_name)
    if (shows('tax_id')) data.tax_id = blankToNull(values.tax_id)
    if (shows('roles')) data.roles = values.roles
    if (shows('phones')) data.phones = values.phones.filter((row) => row.number.trim()).map((row) => ({ number: row.number.trim(), label: blankToNull(row.label) }))
    if (shows('emails')) data.emails = values.emails.filter((row) => row.address.trim()).map((row) => ({ address: row.address.trim(), label: blankToNull(row.label) }))
    if (shows('addresses')) {
      data.addresses = values.addresses
        .filter((row) => ['line1', 'line2', 'city', 'region', 'postal_code'].some((field) => row[field].trim()))
        .map((row) => ({
          label: blankToNull(row.label),
          line1: row.line1.trim(),
          line2: blankToNull(row.line2),
          city: blankToNull(row.city),
          region: blankToNull(row.region),
          postal_code: blankToNull(row.postal_code),
          country: row.country || null,
        }))
    }
    if (shows('currency')) data.currency = values.currency || null
    if (shows('payment_terms_days')) {
      const days = values.payment_terms_days.trim()
      data.payment_terms_days = days === '' ? null : /^\d+$/.test(days) ? Number(days) : days
    }
    if (shows('credit_limit') && !creditLocked) {
      // Minor units from the field, sent as a major-unit decimal string with its currency (MoneyAmount).
      data.credit_limit = values.credit_limit === '' ? null : minorToDecimal(toMinor(values.credit_limit), creditDecimals)
      if (values.credit_limit !== '') data.credit_limit_currency = creditCurrency
    }
    if (shows('price_list_id') && canPriceLists) data.price_list_id = values.price_list_id || null
    if (shows('tags')) {
      data.tags = [
        ...new Set(
          values.tags
            .split(',')
            .map((tag) => tag.trim())
            .filter(Boolean),
        ),
      ]
    }
    return data
  }

  const mutation = useMutation({
    mutationFn: () => (creating ? api.post('parties', body()) : api.patch(`parties/${party.id}`, body())),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['parties'] })
      if (!creating) {
        queryClient.setQueryData(['parties', 'detail', party.id], { data: response.data })
        queryClient.invalidateQueries({ queryKey: ['history', 'party', party.id] })
      }
      onSaved?.(response)
    },
  })
  const rowFields = [
    ...values.phones.flatMap((_, index) => [`phones.${index}.number`, `phones.${index}.label`]),
    ...values.emails.flatMap((_, index) => [`emails.${index}.address`, `emails.${index}.label`]),
    ...values.addresses.flatMap((_, index) => [`addresses.${index}.line1`, `addresses.${index}.country`, `addresses.${index}.postal_code`]),
  ]
  const errors = formErrors(mutation.error, [
    'company_id',
    'kind',
    'name',
    'legal_name',
    'tax_id',
    'roles',
    'phones',
    'emails',
    'addresses',
    'currency',
    'payment_terms_days',
    'credit_limit',
    'credit_limit_currency',
    'price_list_id',
    'tags',
    ...rowFields,
  ])
  useErrorFocus(formRef, alertRef, mutation.error)
  const tagError = errors.fields.tags ?? Object.entries(mutation.error?.errors ?? {}).find(([key]) => key.startsWith('tags.'))?.[1]?.[0]
  // Rows are sent without blanks: an error for the nth sent row belongs to the nth non-blank row.
  const sentIndex = (list, row, filled) => values[list].filter(filled).indexOf(row)

  const showCompany = (creating && nowPerCompany) || (flips && nowPerCompany) || (!creating && !flips && errors.fields.company_id)
  const toggleRole = (name, checked) =>
    setValues((current) => ({ ...current, roles: checked ? [...current.roles, name] : current.roles.filter((entry) => entry !== name) }))

  return (
    <form
      ref={formRef}
      noValidate
      className="flex flex-col gap-5"
      onSubmit={(event) => {
        event.preventDefault()
        setSubmitted(true)
        if (values.roles.length === 0 || values.credit_limit === null) return
        if (flips && !nowPerCompany && !values.confirmShared) return
        mutation.mutate()
      }}
    >
      {errors.form ? (
        <div ref={alertRef} tabIndex={-1} className="rounded-md">
          <Alert tone="danger" title={errorMessage(mutation.error)} />
        </div>
      ) : null}
      <fieldset disabled={readOnly} className="flex min-w-0 flex-col gap-5">
        <Card title={t('parties.form.details')}>
          <div className="grid gap-4 sm:grid-cols-2">
            {shows('kind') ? (
              <Select
                label={t('parties.form.kind')}
                options={PARTY_KINDS.map((kind) => ({ value: kind, label: t(`parties.kinds.${kind}`) }))}
                value={values.kind}
                onChange={set('kind')}
                error={errors.fields.kind}
                required
              />
            ) : null}
            {shows('name') ? <TextField label={t('parties.form.name')} value={values.name} onChange={set('name')} autoComplete="off" error={errors.fields.name} required /> : null}
            {shows('legal_name') ? (
              <TextField label={t('parties.form.legalName')} help={t('parties.form.legalNameHelp')} value={values.legal_name} onChange={set('legal_name')} error={errors.fields.legal_name} />
            ) : null}
            {shows('tax_id') ? <TextField label={t('parties.form.taxId')} help={t('parties.form.taxIdHelp')} value={values.tax_id} onChange={set('tax_id')} autoComplete="off" error={errors.fields.tax_id} /> : null}
            {shows('roles') ? (
              <fieldset className="flex flex-col gap-2 sm:col-span-2">
                <legend className="pb-1 text-label text-ink">{t('parties.form.roles')}</legend>
                <div className="flex flex-wrap gap-x-6 gap-y-2">
                  {PARTY_ROLES.map((name) => (
                    <Checkbox key={name} label={t(`parties.roles.${name}`)} checked={values.roles.includes(name)} onChange={(event) => toggleRole(name, event.target.checked)} />
                  ))}
                </div>
                {errors.fields.roles || (submitted && values.roles.length === 0) ? (
                  <p className="text-caption text-danger">{errors.fields.roles ?? t('parties.form.rolesRequired')}</p>
                ) : null}
              </fieldset>
            ) : null}
            {showCompany ? (
              <Select
                label={t('parties.form.company')}
                help={flips ? t('parties.form.companyChangeHelp') : t('parties.form.companyHelp')}
                options={activeCompanies.map((company) => ({ value: company.id, label: company.name }))}
                placeholder={t('items.form.chooseCompany')}
                value={chosenCompany}
                onChange={(event) => setValues((current) => ({ ...current, company_id: event.target.value, price_list_id: '' }))}
                error={errors.fields.company_id}
                required
                className="sm:col-span-2"
              />
            ) : null}
            {flips && !nowPerCompany ? (
              <div className="flex flex-col gap-2 sm:col-span-2">
                <Alert tone="info" title={t('parties.form.becomesShared')} />
                <Checkbox
                  label={t('parties.form.confirmShared')}
                  checked={values.confirmShared}
                  onChange={(event) => setValues((current) => ({ ...current, confirmShared: event.target.checked }))}
                />
                {submitted && !values.confirmShared ? <p className="text-caption text-danger">{t('parties.form.confirmSharedRequired')}</p> : null}
              </div>
            ) : null}
          </div>
        </Card>

        {shows('phones') || shows('emails') ? (
          <Card title={t('parties.form.contact')}>
            <div className="flex flex-col gap-5">
              {shows('phones') ? (
                <fieldset className="flex flex-col gap-3">
                  <legend className="pb-1 text-label text-ink">{t('parties.form.phones')}</legend>
                  {errors.fields.phones ? <p className="text-caption text-danger">{errors.fields.phones}</p> : null}
                  {values.phones.map((row, index) => {
                    const sent = sentIndex('phones', row, (entry) => entry.number.trim())
                    return (
                      <div key={row.key} className="flex flex-wrap items-end gap-3">
                        <TextField
                          label={t('parties.form.phone', { n: index + 1 })}
                          className="min-w-0 grow basis-full sm:basis-0"
                          type="tel"
                          inputMode="tel"
                          autoComplete="off"
                          value={row.number}
                          onChange={(event) => setRow('phones', row.key, { number: event.target.value })}
                          error={sent >= 0 ? errors.fields[`phones.${sent}.number`] : undefined}
                        />
                        <TextField
                          label={t('parties.form.label')}
                          placeholder={t('parties.form.phoneLabelExample')}
                          className="min-w-0 grow basis-full sm:basis-0"
                          value={row.label}
                          onChange={(event) => setRow('phones', row.key, { label: event.target.value })}
                        />
                        {readOnly ? null : (
                          <Button variant="ghost" icon="remove" onClick={() => removeRow('phones', row.key)} aria-label={t('parties.form.removePhone', { n: index + 1 })}>
                            {t('items.form.remove')}
                          </Button>
                        )}
                      </div>
                    )
                  })}
                  {readOnly ? null : (
                    <div>
                      <Button icon="plus" onClick={() => addRow('phones', { number: '', label: '' })}>
                        {t('parties.form.addPhone')}
                      </Button>
                    </div>
                  )}
                </fieldset>
              ) : null}
              {shows('emails') ? (
                <fieldset className="flex flex-col gap-3">
                  <legend className="pb-1 text-label text-ink">{t('parties.form.emails')}</legend>
                  {errors.fields.emails ? <p className="text-caption text-danger">{errors.fields.emails}</p> : null}
                  {values.emails.map((row, index) => {
                    const sent = sentIndex('emails', row, (entry) => entry.address.trim())
                    return (
                      <div key={row.key} className="flex flex-wrap items-end gap-3">
                        <TextField
                          label={t('parties.form.email', { n: index + 1 })}
                          className="min-w-0 grow basis-full sm:basis-0"
                          type="email"
                          autoComplete="off"
                          value={row.address}
                          onChange={(event) => setRow('emails', row.key, { address: event.target.value })}
                          error={sent >= 0 ? errors.fields[`emails.${sent}.address`] : undefined}
                        />
                        <TextField
                          label={t('parties.form.label')}
                          placeholder={t('parties.form.emailLabelExample')}
                          className="min-w-0 grow basis-full sm:basis-0"
                          value={row.label}
                          onChange={(event) => setRow('emails', row.key, { label: event.target.value })}
                        />
                        {readOnly ? null : (
                          <Button variant="ghost" icon="remove" onClick={() => removeRow('emails', row.key)} aria-label={t('parties.form.removeEmail', { n: index + 1 })}>
                            {t('items.form.remove')}
                          </Button>
                        )}
                      </div>
                    )
                  })}
                  {readOnly ? null : (
                    <div>
                      <Button icon="plus" onClick={() => addRow('emails', { address: '', label: '' })}>
                        {t('parties.form.addEmail')}
                      </Button>
                    </div>
                  )}
                </fieldset>
              ) : null}
            </div>
          </Card>
        ) : null}

        {shows('addresses') ? (
          <Card title={t('parties.form.addresses')}>
            <div className="flex flex-col gap-4">
              {errors.fields.addresses ? <p className="text-caption text-danger">{errors.fields.addresses}</p> : null}
              {values.addresses.length === 0 ? <p className="text-ink-muted">{t('parties.form.noAddresses')}</p> : null}
              {values.addresses.map((row, index) => {
                const sent = sentIndex('addresses', row, (entry) => ['line1', 'line2', 'city', 'region', 'postal_code'].some((field) => entry[field].trim()))
                const field = (name) => (sent >= 0 ? errors.fields[`addresses.${sent}.${name}`] : undefined)
                return (
                  <fieldset key={row.key} className="grid gap-3 border-b border-border pb-4 sm:grid-cols-2">
                    <legend className="sr-only">{t('parties.form.addressN', { n: index + 1 })}</legend>
                    <TextField label={t('parties.form.addressLabel')} placeholder={t('parties.form.addressLabelExample')} value={row.label} onChange={(event) => setRow('addresses', row.key, { label: event.target.value })} />
                    <TextField label={t('parties.form.line1')} value={row.line1} onChange={(event) => setRow('addresses', row.key, { line1: event.target.value })} error={field('line1')} />
                    <TextField label={t('parties.form.line2')} value={row.line2} onChange={(event) => setRow('addresses', row.key, { line2: event.target.value })} />
                    <TextField label={t('parties.form.city')} value={row.city} onChange={(event) => setRow('addresses', row.key, { city: event.target.value })} />
                    <TextField label={t('parties.form.region')} value={row.region} onChange={(event) => setRow('addresses', row.key, { region: event.target.value })} />
                    <TextField label={t('parties.form.postalCode')} value={row.postal_code} onChange={(event) => setRow('addresses', row.key, { postal_code: event.target.value })} error={field('postal_code')} />
                    <Select
                      label={t('parties.form.country')}
                      options={[{ value: '', label: t('parties.form.noCountry') }, ...COUNTRIES.map((code) => ({ value: code, label: t(`auth.countries.${code}`) }))]}
                      value={row.country}
                      onChange={(event) => setRow('addresses', row.key, { country: event.target.value })}
                      error={field('country')}
                    />
                    {readOnly ? null : (
                      <div className="flex items-end">
                        <Button variant="ghost" icon="remove" onClick={() => removeRow('addresses', row.key)} aria-label={t('parties.form.removeAddress', { n: index + 1 })}>
                          {t('items.form.remove')}
                        </Button>
                      </div>
                    )}
                  </fieldset>
                )
              })}
              {readOnly ? null : (
                <div>
                  <Button icon="plus" onClick={() => addRow('addresses', { label: '', line1: '', line2: '', city: '', region: '', postal_code: '', country: companyRecord?.country ?? '' })}>
                    {t('parties.form.addAddress')}
                  </Button>
                </div>
              )}
            </div>
          </Card>
        ) : null}

        <Card title={t('parties.form.terms')}>
          <div className="grid gap-4 sm:grid-cols-2">
            {shows('currency') ? (
              <Select
                label={t('parties.form.currency')}
                help={t('parties.form.currencyHelp')}
                options={[{ value: '', label: t('parties.form.noCurrency') }, ...currencyCodes.map((code) => ({ value: code, label: code }))]}
                value={values.currency}
                onChange={(event) => {
                  const next = event.target.value
                  // A typed credit limit keeps its currency; an empty one follows the party's.
                  setValues((current) => ({ ...current, currency: next, credit_limit_currency: current.credit_limit_currency || (current.credit_limit !== '' ? creditCurrency : '') }))
                }}
                error={errors.fields.currency}
              />
            ) : null}
            {shows('payment_terms_days') ? (
              <TextField
                label={t('parties.form.paymentTerms')}
                help={t('parties.form.paymentTermsHelp')}
                inputMode="numeric"
                suffix={t('parties.form.days')}
                value={values.payment_terms_days}
                onChange={set('payment_terms_days')}
                error={errors.fields.payment_terms_days}
              />
            ) : null}
            {shows('credit_limit') && creditLocked ? (
              <div className="flex flex-col gap-2 sm:col-span-2">
                <span className="text-label text-ink">{t('parties.form.creditLimit')}</span>
                {party?.credit_limit ? (
                  <Money amount={party.credit_limit.amount_minor} currency={party.credit_limit.currency} />
                ) : (
                  <span className="text-ink">{t('creditLimits.noLimit')}</span>
                )}
                <span className="text-caption text-ink-muted">{creating ? t('creditLimits.lockedNew') : t('creditLimits.locked')}</span>
              </div>
            ) : null}
            {shows('credit_limit') && !creditLocked ? (
              <>
                <Select
                  label={t('parties.form.creditCurrency')}
                  options={currencyCodes.map((code) => ({ value: code, label: code }))}
                  value={creditCurrency}
                  onChange={(event) =>
                    // The amount is in minor units of the old currency: cleared, never reinterpreted.
                    setValues((current) => ({ ...current, credit_limit_currency: event.target.value, credit_limit: '' }))
                  }
                  error={errors.fields.credit_limit_currency}
                />
                <MoneyInput
                  key={creditCurrency}
                  label={t('parties.form.creditLimit')}
                  help={t('parties.form.creditLimitHelp')}
                  currency={creditCurrency}
                  decimals={creditDecimals}
                  value={values.credit_limit}
                  onChange={(credit_limit) => setValues((current) => ({ ...current, credit_limit }))}
                  error={errors.fields.credit_limit}
                  showErrors={submitted}
                />
              </>
            ) : null}
            {shows('price_list_id') && canPriceLists ? (
              <Select
                label={t('parties.form.priceList')}
                options={[
                  { value: '', label: t('parties.form.noPriceList') },
                  ...(priceLists.data?.data ?? []).filter((list) => !list.archived_at || list.id === values.price_list_id).map((list) => ({ value: list.id, label: list.name })),
                ]}
                value={values.price_list_id}
                onChange={set('price_list_id')}
                error={errors.fields.price_list_id}
              />
            ) : null}
            {shows('tags') ? (
              <TextField
                label={t('parties.form.tags')}
                help={t('parties.form.tagsHelp')}
                className="sm:col-span-2"
                value={values.tags}
                onChange={set('tags')}
                autoComplete="off"
                error={tagError}
              />
            ) : null}
          </div>
        </Card>
      </fieldset>
      {readOnly ? null : (
        <div className="flex flex-wrap gap-2">
          <Button variant="primary" type="submit" loading={mutation.isPending}>
            {creating ? t(`parties.form.create.${role}`) : t('common.save')}
          </Button>
        </div>
      )}
    </form>
  )
}

/** MD-01: a new customer or supplier; once saved, its page (possible duplicates named there). */
export default function NewParty({ role }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const path = ROLE_PATHS[role]

  return (
    <>
      <Link to={`/contacts/${path}`} className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t(`parties.back.${role}`)}
      </Link>
      <PageHeader title={t(`parties.newTitle.${role}`)} description={t('parties.newText')} />
      {can('core.party.create') ? (
        <PartyForm
          party={null}
          role={role}
          onSaved={(response) => navigate(`/contacts/${path}/${response.data.id}`, { state: { created: true, duplicates: response.meta?.possible_duplicates ?? [] } })}
        />
      ) : null}
    </>
  )
}
