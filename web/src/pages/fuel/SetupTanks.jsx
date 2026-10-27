import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, DecimalInput, Dialog, TextField } from '@/components/ds'
import { FormFooter, Litres, ProductSelect, StationSelect } from './FuelParts'
import { fuelKey, productLabel, SETUP_MANAGE, useProducts, useStations, useTanks } from './fuelData'

const FIELDS = ['location_id', 'product_id', 'code', 'name', 'capacity_litres', 'tolerance_litres']

/** FUEL-05: a tank at a station (code, name, product, capacity, optional tolerance). Also edits (name, capacity, tolerance) when `tank` is given. */
export function TankDialog({ open, tank, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { stations } = useStations()
  const { products } = useProducts()
  const editing = Boolean(tank)
  const [form, setForm] = useState({ location_id: '', product_id: '', code: '', name: tank?.name ?? '', capacity: tank?.capacity_litres ?? '', tolerance: tank?.tolerance_litres ?? '' })
  const [shown, setShown] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const save = useMutation({
    mutationFn: () => {
      const body = { name: form.name.trim(), capacity_litres: form.capacity, tolerance_litres: form.tolerance === '' ? null : form.tolerance }
      return editing ? api.patch(`fuel/tanks/${tank.id}`, body) : api.post('fuel/tanks', { ...body, location_id: form.location_id, product_id: form.product_id, code: form.code.trim() })
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
  const ready = form.name.trim() && form.capacity && form.capacity !== null && form.tolerance !== null && (editing || (form.location_id && form.product_id && form.code.trim()))
  return (
    <Dialog open={open} title={editing ? t('fuel.setup.tanks.edit', { code: tank.code }) : t('fuel.setup.tanks.new')} onClose={close} footer={<FormFooter action={editing ? t('fuel.actions.save') : t('fuel.setup.tanks.create')} loading={save.isPending} disabled={!ready} onClose={close} onSubmit={() => {
            setShown(true)
            save.mutate()
          }} />}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {editing ? null : (
          <>
            <StationSelect stations={stations} value={form.location_id} error={errors.fields.location_id} required onChange={set('location_id')} />
            <ProductSelect products={products} value={form.product_id} error={errors.fields.product_id} required onChange={set('product_id')} />
            <TextField label={t('fuel.fields.code')} value={form.code} maxLength={20} error={errors.fields.code} required onChange={(event) => set('code')(event.target.value)} />
          </>
        )}
        <TextField label={t('fuel.fields.name')} value={form.name} maxLength={100} error={errors.fields.name} required onChange={(event) => set('name')(event.target.value)} />
        <DecimalInput label={t('fuel.fields.capacity')} suffix={t('fuel.units.litres')} value={form.capacity} maxDecimals={3} showErrors={shown} error={errors.fields.capacity_litres} required onChange={set('capacity')} />
        <DecimalInput label={t('fuel.fields.tolerance')} help={t('fuel.setup.tanks.toleranceHelp')} suffix={t('fuel.units.litres')} value={form.tolerance} maxDecimals={3} positive={false} showErrors={shown} error={errors.fields.tolerance_litres} onChange={set('tolerance')} />
      </div>
    </Dialog>
  )
}

/** FUEL-05: the tanks of the stations, with the book stock; a row opens the tank. */
export default function SetupTanks() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const { all, loading } = useTanks()
  const [creating, setCreating] = useState(0)
  const columns = [
    { key: 'code', label: t('fuel.fields.code'), render: (row) => <span className="font-medium text-ink">{row.code}</span> },
    { key: 'name', label: t('fuel.fields.name'), render: (row) => row.name },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'product', label: t('fuel.fields.product'), render: (row) => productLabel(row.product) },
    { key: 'capacity', label: t('fuel.fields.capacity'), align: 'end', render: (row) => <Litres value={row.capacity_litres} /> },
    { key: 'book', label: t('fuel.tank.book'), align: 'end', render: (row) => <Litres value={row.book_litres} /> },
    { key: 'state', label: t('fuel.columns.status'), render: (row) => (row.archived_at ? t('fuel.states.archived') : t('fuel.states.active')) },
  ]
  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-body text-ink-muted">{t('fuel.setup.tanks.description')}</p>
        {can(SETUP_MANAGE) ? (
          <Button variant="primary" icon="plus" onClick={() => setCreating((count) => count + 1)}>
            {t('fuel.setup.tanks.new')}
          </Button>
        ) : null}
      </div>
      <DataTable caption={t('fuel.setup.tabs.tanks')} columns={columns} rows={all} loading={loading} emptyText={t('fuel.setup.tanks.empty')} onRowClick={(row) => navigate(`/fuel/tanks/${row.id}`)} />
      {creating ? <TankDialog key={creating} open tank={null} onClose={() => setCreating(0)} /> : null}
    </section>
  )
}
