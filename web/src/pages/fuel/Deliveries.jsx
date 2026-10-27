import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DecimalInput, Dialog, MoneyInput, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, FilterBar, Flags, FormFooter, FuelList, Litres, PartyPicker, StationSelect, TankSelect, When } from './FuelParts'
import { DeliveryFigures } from './DeliveryDetail'
import { currencyAt, DELIVERY_RECORD, fuelKey, useStations, useTanks } from './fuelData'

const FIELDS = ['tank_id', 'supplier_id', 'note_number', 'note_litres', 'before_dip_cm', 'after_dip_cm', 'sold_during_litres', 'unit_cost_minor', 'currency']

/** FUEL-25: a delivery: the note, the dip before and after, litres sold while offloading and the cost per litre. The server posts what was measured. */
function DeliveryDialog({ stations, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [location, setLocation] = useState('')
  const { tanks, all } = useTanks(location)
  const [supplier, setSupplier] = useState(null)
  const [form, setForm] = useState({ tank_id: '', note_number: '', note_litres: '', before: '', after: '', sold: '', cost: '' })
  const [shown, setShown] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const tank = all.find((entry) => entry.id === form.tank_id)
  const currency = currencyAt(stations, tank?.location?.id)
  const save = useMutation({
    mutationFn: () =>
      api.post('fuel/deliveries', {
        tank_id: form.tank_id,
        supplier_id: supplier?.id ?? null,
        note_number: form.note_number.trim(),
        note_litres: form.note_litres,
        before_dip_cm: form.before,
        after_dip_cm: form.after,
        sold_during_litres: form.sold === '' ? null : form.sold,
        unit_cost_minor: form.cost,
        currency,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const done = save.data?.data
  const ready = form.tank_id && currency && form.note_number.trim() && form.note_litres && form.before && form.after && form.sold !== null && form.cost
  return (
    <Dialog
      open
      size="lg"
      title={done ? t('fuel.deliveries.recorded') : t('fuel.deliveries.new')}
      onClose={onClose}
      footer={
        done ? (
          <Button variant="primary" onClick={onClose}>
            {t('fuel.actions.close')}
          </Button>
        ) : (
          <FormFooter
            action={t('fuel.deliveries.save')}
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
          <Alert tone="success" title={t('fuel.deliveries.recordedAs', { number: done.number })} />
          <DeliveryFigures delivery={done} />
          <Link to={`/fuel/deliveries/${done.id}`} className="w-fit text-label text-primary hover:text-primary-hover">
            {t('fuel.deliveries.open')}
          </Link>
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-body text-ink-muted">{t('fuel.deliveries.help')}</p>
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <StationSelect
            all
            stations={stations}
            value={location}
            onChange={(value) => {
              setLocation(value)
              set('tank_id')('')
            }}
          />
          <TankSelect tanks={tanks} value={form.tank_id} error={errors.fields.tank_id} required onChange={set('tank_id')} />
          <PartyPicker partyRole="supplier" label={t('fuel.deliveries.supplier')} value={supplier} error={errors.fields.supplier_id} onChange={setSupplier} />
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('fuel.deliveries.noteNumber')} value={form.note_number} maxLength={60} error={errors.fields.note_number} required onChange={(event) => set('note_number')(event.target.value)} />
            <DecimalInput label={t('fuel.deliveries.noteLitres')} suffix={t('fuel.units.litres')} value={form.note_litres} maxDecimals={3} maxIntegerDigits={13} showErrors={shown} error={errors.fields.note_litres} required onChange={set('note_litres')} />
            <DecimalInput label={t('fuel.deliveries.beforeDip')} suffix={t('fuel.units.cm')} value={form.before} maxDecimals={2} maxIntegerDigits={5} positive={false} showErrors={shown} error={errors.fields.before_dip_cm} required onChange={set('before')} />
            <DecimalInput label={t('fuel.deliveries.afterDip')} suffix={t('fuel.units.cm')} value={form.after} maxDecimals={2} maxIntegerDigits={5} positive={false} showErrors={shown} error={errors.fields.after_dip_cm} required onChange={set('after')} />
            <DecimalInput label={t('fuel.deliveries.soldDuring')} help={t('fuel.deliveries.soldDuringHelp')} suffix={t('fuel.units.litres')} value={form.sold} maxDecimals={3} maxIntegerDigits={13} positive={false} showErrors={shown} error={errors.fields.sold_during_litres} onChange={set('sold')} />
            {currency ? (
              <MoneyInput key={currency} label={t('fuel.deliveries.unitCost')} help={t('fuel.deliveries.unitCostHelp')} currency={currency} value={form.cost} showErrors={shown} error={errors.fields.unit_cost_minor} required onChange={set('cost')} />
            ) : (
              <TextField label={t('fuel.deliveries.unitCost')} help={t('fuel.deliveries.pickTank')} value="" disabled readOnly />
            )}
          </div>
        </div>
      )}
    </Dialog>
  )
}

/** FUEL-25: the deliveries received, with the shortage against the note. */
export default function Deliveries() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ location_id: '', tank_id: '', from: '', to: '' })
  const { tanks } = useTanks(filters.location_id)
  const [recording, setRecording] = useState(false)
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  const columns = [
    { key: 'number', label: t('fuel.columns.number'), render: (row) => <span className="font-medium text-ink tabular-nums">{row.number}</span> },
    { key: 'received', label: t('fuel.deliveries.receivedAt'), render: (row) => <When value={row.received_at} /> },
    { key: 'tank', label: t('fuel.fields.tank'), render: (row) => (row.tank ? `${row.tank.code} · ${row.tank.name}` : '') },
    { key: 'supplier', label: t('fuel.deliveries.supplier'), render: (row) => row.supplier?.name ?? '' },
    { key: 'note', label: t('fuel.deliveries.noteNumber'), render: (row) => row.note_number },
    { key: 'noteLitres', label: t('fuel.deliveries.noteLitres'), align: 'end', render: (row) => <Litres value={row.note_litres} /> },
    { key: 'receivedLitres', label: t('fuel.deliveries.received'), align: 'end', render: (row) => <Litres value={row.received_litres} className="font-medium text-ink" /> },
    { key: 'shortage', label: t('fuel.deliveries.shortageLabel'), align: 'end', render: (row) => <Litres value={row.shortage_litres} /> },
    { key: 'cost', label: t('fuel.deliveries.cost'), align: 'end', render: (row) => <Amount minor={row.cost_minor} currency={row.currency} /> },
    { key: 'flags', label: t('fuel.columns.flags'), render: (row) => <Flags flags={row.flags} /> },
  ]
  return (
    <>
      <PageHeader
        title={t('fuel.deliveries.title')}
        description={t('fuel.deliveries.description')}
        actions={
          can(DELIVERY_RECORD) ? (
            <Button variant="primary" icon="plus" onClick={() => setRecording(true)}>
              {t('fuel.deliveries.new')}
            </Button>
          ) : null
        }
      />
      <FilterBar>
        <StationSelect all stations={stations} value={filters.location_id} onChange={(value) => setFilters((current) => ({ ...current, location_id: value, tank_id: '' }))} className="w-56" />
        <TankSelect all tanks={tanks} value={filters.tank_id} onChange={set('tank_id')} className="w-56" />
        <TextField type="date" label={t('fuel.filters.from')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
      </FilterBar>
      <FuelList
        queryKey="deliveries"
        endpoint="fuel/deliveries"
        params={filters}
        columns={columns}
        caption={t('fuel.deliveries.title')}
        emptyText={Object.values(filters).some(Boolean) ? t('fuel.deliveries.emptyFiltered') : t('fuel.deliveries.empty')}
        onRowClick={(row) => navigate(`/fuel/deliveries/${row.id}`)}
      />
      {recording ? <DeliveryDialog stations={stations} onClose={() => setRecording(false)} /> : null}
    </>
  )
}
