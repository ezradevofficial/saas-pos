import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, MoneyInput, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { useDebounced } from '@/lib/useDebounced'
import { usePharmacyCompanyChoice } from '../pharmacy/pharmacyData'
import { Amount, FilterBar, FormFooter, FuelList, PartyPicker, RecordStatus } from './FuelParts'
import { FUEL_TYPES, fuelKey, VOUCHER_MANAGE, VOUCHER_STATUSES } from './fuelData'

const FIELDS = ['company_id', 'value_minor', 'currency', 'code', 'party_id', 'fuel_type', 'expires_on']

/** FUEL-21: issue a prepaid voucher: its value in the voucher's currency, an optional code (else the server makes one), customer, fuel type and expiry. */
function VoucherDialog({ onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { company, companies, choose } = usePharmacyCompanyChoice()
  const { currency: defaultCurrency, options: currencies } = useMoneyDefaults(company?.id)
  const [currency, setCurrency] = useState('')
  const [customer, setCustomer] = useState(null)
  const [form, setForm] = useState({ value: '', code: '', fuel_type: '', expires_on: '' })
  const [shown, setShown] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const chosen = currency || defaultCurrency
  const save = useMutation({
    mutationFn: () =>
      api.post('fuel/vouchers', {
        company_id: company.id,
        value_minor: form.value,
        currency: chosen,
        code: form.code.trim() || null,
        party_id: customer?.id ?? null,
        fuel_type: form.fuel_type || null,
        expires_on: form.expires_on || null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const done = save.data?.data
  const ready = company && chosen && form.value
  return (
    <Dialog
      open
      title={done ? t('fuel.vouchers.issued') : t('fuel.vouchers.new')}
      onClose={onClose}
      footer={
        done ? (
          <Button variant="primary" onClick={onClose}>
            {t('fuel.actions.close')}
          </Button>
        ) : (
          <FormFooter
            action={t('fuel.vouchers.issue')}
            loading={save.isPending}
            disabled={!ready}
            onClose={onClose}
            onSubmit={() => {
              setShown(true)
              save.mutate()
            }}
          />
        )
      }
    >
      {done ? (
        <div className="flex flex-col gap-4">
          <Alert tone="success" title={t('fuel.vouchers.issuedCode', { code: done.code })}>
            <Amount minor={done.value_minor} currency={done.currency} />
          </Alert>
          <Link to={`/fuel/vouchers/${done.id}`} className="w-fit text-label text-primary hover:text-primary-hover">
            {t('fuel.vouchers.open')}
          </Link>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-body text-ink-muted">{t('fuel.vouchers.help')}</p>
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          {companies.length > 1 ? (
            <Select
              label={t('fuel.fields.company')}
              options={companies.map((entry) => ({ value: entry.id, label: entry.name }))}
              value={company?.id ?? ''}
              onChange={(event) => {
                setCurrency('')
                choose(event.target.value)
              }}
            />
          ) : null}
          <Select label={t('fuel.fields.currency')} options={currencies} value={chosen} error={errors.fields.currency} required onChange={(event) => setCurrency(event.target.value)} />
          <MoneyInput key={chosen} label={t('fuel.vouchers.value')} currency={chosen} value={form.value} showErrors={shown} error={errors.fields.value_minor} required onChange={set('value')} />
          <TextField label={t('fuel.vouchers.code')} help={t('fuel.vouchers.codeHelp')} value={form.code} maxLength={40} error={errors.fields.code} onChange={(event) => set('code')(event.target.value)} />
          <PartyPicker partyRole="customer" label={t('fuel.fields.customer')} value={customer} error={errors.fields.party_id} onChange={setCustomer} />
          <Select label={t('fuel.fields.fuelType')} help={t('fuel.vouchers.fuelTypeHelp')} options={[{ value: '', label: t('fuel.vehicles.anyFuel') }, ...FUEL_TYPES.map((value) => ({ value, label: t(`fuel.types.${value}`) }))]} value={form.fuel_type} error={errors.fields.fuel_type} onChange={(event) => set('fuel_type')(event.target.value)} />
          <TextField type="date" label={t('fuel.vouchers.expiresOn')} help={t('fuel.vouchers.expiresHelp')} value={form.expires_on} error={errors.fields.expires_on} onChange={(event) => set('expires_on')(event.target.value)} />
        </div>
      )}
    </Dialog>
  )
}

/** FUEL-21: the fuel vouchers with their balance and status; issued by people who hold the manage permission. */
export default function Vouchers() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const [typed, setTyped] = useState('')
  const search = useDebounced(typed.trim())
  const [status, setStatus] = useState('')
  const [issuing, setIssuing] = useState(false)
  const columns = [
    { key: 'code', label: t('fuel.vouchers.code'), render: (row) => <span className="font-medium text-ink tabular-nums">{row.code}</span> },
    { key: 'customer', label: t('fuel.fields.customer'), render: (row) => row.customer?.name ?? '' },
    { key: 'value', label: t('fuel.vouchers.value'), align: 'end', render: (row) => <Amount minor={row.value_minor} currency={row.currency} /> },
    { key: 'balance', label: t('fuel.vouchers.balance'), align: 'end', render: (row) => <Amount minor={row.balance_minor} currency={row.currency} className="font-medium text-ink" /> },
    { key: 'fuel', label: t('fuel.fields.fuelType'), render: (row) => (row.fuel_type ? t(`fuel.types.${row.fuel_type}`, { defaultValue: row.fuel_type }) : t('fuel.vehicles.anyFuel')) },
    { key: 'expires', label: t('fuel.vouchers.expiresOn'), render: (row) => row.expires_on ?? '' },
    { key: 'status', label: t('fuel.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
  ]
  return (
    <>
      <PageHeader
        title={t('fuel.vouchers.title')}
        description={t('fuel.vouchers.description')}
        actions={
          can(VOUCHER_MANAGE) ? (
            <Button variant="primary" icon="plus" onClick={() => setIssuing(true)}>
              {t('fuel.vouchers.new')}
            </Button>
          ) : null
        }
      />
      <FilterBar>
        <TextField label={t('fuel.vouchers.search')} value={typed} maxLength={40} onChange={(event) => setTyped(event.target.value)} className="w-64" />
        <Select label={t('fuel.columns.status')} options={[{ value: '', label: t('fuel.filters.anyStatus') }, ...VOUCHER_STATUSES.map((value) => ({ value, label: t(`fuel.states.${value}`) }))]} value={status} onChange={(event) => setStatus(event.target.value)} className="w-44" />
      </FilterBar>
      <FuelList
        queryKey="vouchers"
        endpoint="fuel/vouchers"
        params={{ search, status }}
        columns={columns}
        caption={t('fuel.vouchers.title')}
        emptyText={search || status ? t('fuel.vouchers.emptyFiltered') : t('fuel.vouchers.empty')}
        onRowClick={(row) => navigate(`/fuel/vouchers/${row.id}`)}
      />
      {issuing ? <VoucherDialog onClose={() => setIssuing(false)} /> : null}
    </>
  )
}
