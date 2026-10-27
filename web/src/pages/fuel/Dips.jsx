import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DecimalInput, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { zonedToUtc } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { Figure, FilterBar, FormFooter, FuelList, Litres, RecordStatus, StationSelect, TankSelect, When } from './FuelParts'
import { DIP_KINDS, DIP_RECORD, fuelKey, useStations, useTanks, VARIANCE_VIEW } from './fuelData'
import { ReconciliationFigures } from './ReconciliationDetail'

const FIELDS = ['tank_id', 'dip_cm', 'water_cm', 'taken_at']

/** FUEL-23, FUEL-24: what the dip came to in litres and how the tank reconciled. */
function DipResult({ dip, can }) {
  const { t } = useTranslation()
  const reconciliation = dip.reconciliation
  return (
    <div className="flex flex-col gap-4">
      <Alert tone="success" title={t('fuel.dips.recorded')}>
        {t('fuel.dips.litresResult')} <Litres value={dip.litres} className="font-medium text-ink" />
      </Alert>
      {reconciliation ? (
        <section className="flex flex-col gap-3" aria-label={t('fuel.dips.reconciliation')}>
          <div className="flex flex-wrap items-center gap-3">
            <h3 className="text-h3 text-ink">{t('fuel.dips.reconciliation')}</h3>
            <RecordStatus status={reconciliation.status} />
          </div>
          <p className="text-body text-ink-muted">{t(`fuel.dips.outcome.${reconciliation.status}`, { defaultValue: '' })}</p>
          {reconciliation.status === 'baseline' ? null : <ReconciliationFigures reconciliation={reconciliation} />}
          {reconciliation.status === 'pending_approval' && can ? (
            <Link to={`/fuel/reconciliations/${reconciliation.id}`} className="w-fit text-label text-primary hover:text-primary-hover">
              {t('fuel.dips.openReconciliation')}
            </Link>
          ) : null}
        </section>
      ) : (
        <p className="text-body text-ink-muted">{t('fuel.dips.noReconciliation')}</p>
      )}
    </div>
  )
}

/** FUEL-23: a dip of a tank in cm (and water in cm); the server converts it by the chart in force. */
function DipDialog({ stations, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const zone = useTimeZone()
  const { can } = usePermissions()
  const [location, setLocation] = useState('')
  const { tanks } = useTanks(location)
  const [form, setForm] = useState({ tank_id: '', dip: '', water: '', at: '' })
  const [shown, setShown] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const save = useMutation({
    mutationFn: () =>
      api.post('fuel/dips', {
        tank_id: form.tank_id,
        dip_cm: form.dip,
        water_cm: form.water === '' ? null : form.water,
        taken_at: form.at ? zonedToUtc(form.at, zone) : null,
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const errors = formErrors(save.error, FIELDS)
  const done = save.data?.data
  const ready = form.tank_id && form.dip && form.water !== null
  return (
    <Dialog
      open
      size="lg"
      title={done ? t('fuel.dips.recorded') : t('fuel.dips.new')}
      onClose={onClose}
      footer={
        done ? (
          <Button variant="primary" onClick={onClose}>
            {t('fuel.actions.close')}
          </Button>
        ) : (
          <FormFooter
            action={t('fuel.dips.save')}
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
        <DipResult dip={done} can={can(VARIANCE_VIEW)} />
      ) : (
        <div className="flex flex-col gap-4">
          <p className="text-body text-ink-muted">{t('fuel.dips.help')}</p>
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <StationSelect all stations={stations} value={location} onChange={(value) => { setLocation(value); set('tank_id')('') }} />
          <TankSelect tanks={tanks} value={form.tank_id} error={errors.fields.tank_id} required onChange={set('tank_id')} />
          <div className="grid gap-4 sm:grid-cols-2">
            <DecimalInput label={t('fuel.dips.dip')} suffix={t('fuel.units.cm')} value={form.dip} maxDecimals={2} maxIntegerDigits={5} positive={false} showErrors={shown} error={errors.fields.dip_cm} required onChange={set('dip')} />
            <DecimalInput label={t('fuel.dips.water')} help={t('fuel.dips.waterHelp')} suffix={t('fuel.units.cm')} value={form.water} maxDecimals={2} maxIntegerDigits={5} positive={false} showErrors={shown} error={errors.fields.water_cm} onChange={set('water')} />
          </div>
          <TextField type="datetime-local" label={t('fuel.dips.takenAt')} help={t('fuel.dips.takenAtHelp')} value={form.at} error={errors.fields.taken_at} onChange={(event) => set('at')(event.target.value)} />
        </div>
      )}
    </Dialog>
  )
}

/** FUEL-23: the dips taken at the stations, and a form to record one. */
export default function Dips() {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ location_id: '', tank_id: '', kind: '', from: '', to: '' })
  const { tanks } = useTanks(filters.location_id)
  const [recording, setRecording] = useState(false)
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  const columns = [
    { key: 'taken', label: t('fuel.dips.takenAt'), render: (row) => <span className="font-medium text-ink"><When value={row.taken_at} /></span> },
    { key: 'tank', label: t('fuel.fields.tank'), render: (row) => (row.tank ? `${row.tank.code} · ${row.tank.name}` : '') },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'kind', label: t('fuel.columns.kind'), render: (row) => t(`fuel.dipKinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'dip', label: t('fuel.dips.dip'), align: 'end', render: (row) => <Figure value={row.dip_cm} unit={t('fuel.units.cm')} /> },
    { key: 'water', label: t('fuel.dips.water'), align: 'end', render: (row) => <Figure value={row.water_cm} unit={t('fuel.units.cm')} /> },
    { key: 'litres', label: t('fuel.columns.litres'), align: 'end', render: (row) => <Litres value={row.litres} className="font-medium text-ink" /> },
    { key: 'by', label: t('fuel.columns.takenBy'), render: (row) => row.taken_by?.name ?? '' },
  ]
  return (
    <>
      <PageHeader
        title={t('fuel.dips.title')}
        description={t('fuel.dips.description')}
        actions={
          can(DIP_RECORD) ? (
            <Button variant="primary" icon="plus" onClick={() => setRecording(true)}>
              {t('fuel.dips.new')}
            </Button>
          ) : null
        }
      />
      <FilterBar>
        <StationSelect all stations={stations} value={filters.location_id} onChange={(value) => setFilters((current) => ({ ...current, location_id: value, tank_id: '' }))} className="w-56" />
        <TankSelect all tanks={tanks} value={filters.tank_id} onChange={set('tank_id')} className="w-56" />
        <Select label={t('fuel.columns.kind')} options={[{ value: '', label: t('fuel.filters.anyKind') }, ...DIP_KINDS.map((value) => ({ value, label: t(`fuel.dipKinds.${value}`) }))]} value={filters.kind} onChange={(event) => set('kind')(event.target.value)} className="w-52" />
        <TextField type="date" label={t('fuel.filters.from')} value={filters.from} onChange={(event) => set('from')(event.target.value)} />
        <TextField type="date" label={t('fuel.filters.to')} value={filters.to} onChange={(event) => set('to')(event.target.value)} />
      </FilterBar>
      <FuelList queryKey="dips" endpoint="fuel/dips" params={filters} columns={columns} caption={t('fuel.dips.title')} emptyText={Object.values(filters).some(Boolean) ? t('fuel.dips.emptyFiltered') : t('fuel.dips.empty')} />
      {recording ? <DipDialog stations={stations} onClose={() => setRecording(false)} /> : null}
    </>
  )
}
