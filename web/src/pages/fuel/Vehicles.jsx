import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Checkbox, DecimalInput, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { usePharmacyCompanyChoice } from '../pharmacy/pharmacyData'
import { ConfirmDialog, FilterBar, FormFooter, FuelList, Litres, PartyPicker, RecordStatus } from './FuelParts'
import { FUEL_TYPES, fuelKey, VEHICLE_MANAGE } from './fuelData'

const FIELDS = ['company_id', 'party_id', 'registration', 'label', 'fuel_type', 'daily_litre_limit', 'status']

/** FUEL-20: a fleet vehicle of a customer; edited, only its label, fuel type, daily litre limit and status change. */
function VehicleDialog({ vehicle, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { company, companies, choose } = usePharmacyCompanyChoice()
  const editing = Boolean(vehicle)
  const [customer, setCustomer] = useState(vehicle?.customer ?? null)
  const [form, setForm] = useState({ registration: vehicle?.registration ?? '', label: vehicle?.label ?? '', fuel_type: vehicle?.fuel_type ?? '', limit: vehicle?.daily_litre_limit ?? '', blocked: vehicle?.status === 'blocked' })
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { label: form.label.trim() || null, fuel_type: form.fuel_type || null, daily_litre_limit: form.limit === '' ? null : form.limit }
      return editing
        ? api.patch(`fuel/vehicles/${vehicle.id}`, { ...body, status: form.blocked ? 'blocked' : 'active' })
        : api.post('fuel/vehicles', { ...body, company_id: company.id, party_id: customer.id, registration: form.registration.trim() })
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const close = () => {
    save.reset()
    onClose()
  }
  const ready = form.limit !== null && (editing || (customer && company && form.registration.trim()))
  return (
    <Dialog open title={editing ? t('fuel.vehicles.edit', { registration: vehicle.registration }) : t('fuel.vehicles.new')} onClose={close} footer={<FormFooter action={editing ? t('fuel.actions.save') : t('fuel.vehicles.create')} loading={save.isPending} disabled={!ready} onClose={close} onSubmit={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {editing ? null : (
          <>
            {companies.length > 1 ? <Select label={t('fuel.fields.company')} options={companies.map((entry) => ({ value: entry.id, label: entry.name }))} value={company?.id ?? ''} onChange={(event) => choose(event.target.value)} /> : null}
            <PartyPicker partyRole="customer" label={t('fuel.fields.customer')} value={customer} error={errors.fields.party_id} required onChange={setCustomer} />
            <TextField label={t('fuel.vehicles.registration')} value={form.registration} maxLength={20} error={errors.fields.registration} required onChange={(event) => set('registration')(event.target.value)} />
          </>
        )}
        <TextField label={t('fuel.vehicles.label')} help={t('fuel.vehicles.labelHelp')} value={form.label} maxLength={120} error={errors.fields.label} onChange={(event) => set('label')(event.target.value)} />
        <Select label={t('fuel.fields.fuelType')} help={t('fuel.vehicles.fuelTypeHelp')} options={[{ value: '', label: t('fuel.vehicles.anyFuel') }, ...FUEL_TYPES.map((value) => ({ value, label: t(`fuel.types.${value}`) }))]} value={form.fuel_type} error={errors.fields.fuel_type} onChange={(event) => set('fuel_type')(event.target.value)} />
        <DecimalInput label={t('fuel.vehicles.dailyLimit')} help={t('fuel.vehicles.dailyLimitHelp')} suffix={t('fuel.units.litres')} value={form.limit} maxDecimals={3} maxIntegerDigits={13} error={errors.fields.daily_litre_limit} onChange={set('limit')} />
        {editing ? <Checkbox label={t('fuel.vehicles.blocked')} checked={form.blocked} onChange={(event) => set('blocked')(event.target.checked)} /> : null}
      </div>
    </Dialog>
  )
}

/** FUEL-20: the fleet vehicles of customers, with their fuel type and daily limit; a blocked vehicle is flagged on a credit sale. */
export default function Vehicles() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const manage = can(VEHICLE_MANAGE)
  const [typed, setTyped] = useState('')
  const search = useDebounced(typed.trim())
  const [archived, setArchived] = useState(false)
  const [dialog, setDialog] = useState(null)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: (vehicle) => api.post(`fuel/vehicles/${vehicle.id}/archive`),
    onSuccess: async () => {
      setArchiving(null)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const columns = [
    { key: 'registration', label: t('fuel.vehicles.registration'), render: (row) => <span className="font-medium text-ink">{row.registration}</span> },
    { key: 'customer', label: t('fuel.fields.customer'), render: (row) => row.customer?.name ?? '' },
    { key: 'label', label: t('fuel.vehicles.label'), render: (row) => row.label ?? '' },
    { key: 'fuel', label: t('fuel.fields.fuelType'), render: (row) => (row.fuel_type ? t(`fuel.types.${row.fuel_type}`, { defaultValue: row.fuel_type }) : t('fuel.vehicles.anyFuel')) },
    { key: 'limit', label: t('fuel.vehicles.dailyLimit'), align: 'end', render: (row) => <Litres value={row.daily_litre_limit} /> },
    { key: 'status', label: t('fuel.columns.status'), render: (row) => (row.archived_at ? t('fuel.states.archived') : <RecordStatus status={row.status} />) },
    ...(manage
      ? [
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              row.archived_at ? null : (
                <span className="flex justify-end gap-2">
                  <Button variant="ghost" aria-label={t('fuel.vehicles.editLabel', { registration: row.registration })} onClick={() => setDialog(row)}>
                    {t('fuel.actions.edit')}
                  </Button>
                  <Button variant="ghost" aria-label={t('fuel.vehicles.archiveLabel', { registration: row.registration })} onClick={() => setArchiving(row)}>
                    {t('fuel.actions.archive')}
                  </Button>
                </span>
              ),
          },
        ]
      : []),
  ]
  return (
    <>
      <PageHeader
        title={t('fuel.vehicles.title')}
        description={t('fuel.vehicles.description')}
        actions={
          manage ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog('new')}>
              {t('fuel.vehicles.new')}
            </Button>
          ) : null
        }
      />
      <FilterBar>
        <TextField label={t('fuel.vehicles.search')} placeholder={t('fuel.vehicles.searchPlaceholder')} type="search" value={typed} maxLength={60} onChange={(event) => setTyped(event.target.value)} className="w-64" />
        <Checkbox label={t('fuel.vehicles.showArchived')} checked={archived} onChange={(event) => setArchived(event.target.checked)} />
      </FilterBar>
      <FuelList queryKey="vehicles" endpoint="fuel/vehicles" params={{ search, archived: archived ? 1 : '' }} columns={columns} caption={t('fuel.vehicles.title')} emptyText={search || archived ? t('fuel.vehicles.emptyFiltered') : t('fuel.vehicles.empty')} />
      {dialog ? <VehicleDialog vehicle={dialog === 'new' ? null : dialog} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={Boolean(archiving)}
        title={t('fuel.vehicles.archiveTitle', { registration: archiving?.registration })}
        text={t('fuel.vehicles.archiveHelp')}
        action={t('fuel.actions.archive')}
        variant="danger"
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onClose={() => {
          archive.reset()
          setArchiving(null)
        }}
        onConfirm={() => archive.mutate(archiving)}
      />
    </>
  )
}
