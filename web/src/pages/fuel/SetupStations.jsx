import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DataTable, Dialog, Select } from '@/components/ds'
import { useMoneyDefaults } from '@/lib/defaultCurrency'
import { locationCurrency, locationOptions, usePlaces } from '../pharmacy/pharmacyData'
import { ConfirmDialog, FormFooter } from './FuelParts'
import { fuelKey, SETUP_MANAGE, useStations } from './fuelData'

function StationDialog({ open, stations, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const { options: currencies } = useMoneyDefaults(null)
  const [location, setLocation] = useState('')
  const [currency, setCurrency] = useState('')
  const taken = new Set(stations.map((station) => station.location?.id))
  const free = places.activeLocations.filter((entry) => !taken.has(entry.id))
  const chosenCurrency = currency || locationCurrency(places, location) || ''
  const save = useMutation({
    mutationFn: () => api.post('fuel/stations', { location_id: location, currency: chosenCurrency }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      setLocation('')
      setCurrency('')
      onClose()
    },
  })
  const errors = formErrors(save.error, ['location_id', 'currency'])
  const close = () => {
    save.reset()
    onClose()
  }
  return (
    <Dialog open={open} title={t('fuel.setup.stations.new')} onClose={close} footer={<FormFooter action={t('fuel.setup.stations.create')} loading={save.isPending} disabled={!location || !chosenCurrency} onClose={close} onSubmit={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('fuel.setup.stations.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select label={t('fuel.fields.location')} placeholder={t('fuel.pickers.location')} options={locationOptions(free)} value={location} error={errors.fields.location_id} required onChange={(event) => setLocation(event.target.value)} />
        <Select label={t('fuel.fields.currency')} options={currencies} value={chosenCurrency} error={errors.fields.currency} required onChange={(event) => setCurrency(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** FUEL-02: the locations marked as forecourts, with the currency their prices and shifts use. */
export default function SetupStations() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { all, loading } = useStations()
  const [creating, setCreating] = useState(false)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: (station) => api.post(`fuel/stations/${station.id}/archive`),
    onSuccess: async () => {
      setArchiving(null)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const manage = can(SETUP_MANAGE)
  const columns = [
    { key: 'location', label: t('fuel.fields.station'), render: (row) => <span className="font-medium text-ink">{row.location?.name}</span> },
    { key: 'currency', label: t('fuel.fields.currency'), render: (row) => row.currency },
    { key: 'state', label: t('fuel.columns.status'), render: (row) => (row.archived_at ? t('fuel.states.archived') : t('fuel.states.active')) },
    ...(manage
      ? [
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              row.archived_at ? null : (
                <Button variant="ghost" aria-label={t('fuel.setup.stations.archiveLabel', { name: row.location?.name })} onClick={() => setArchiving(row)}>
                  {t('fuel.actions.archive')}
                </Button>
              ),
          },
        ]
      : []),
  ]
  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-body text-ink-muted">{t('fuel.setup.stations.description')}</p>
        {manage ? (
          <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
            {t('fuel.setup.stations.new')}
          </Button>
        ) : null}
      </div>
      <DataTable caption={t('fuel.setup.tabs.stations')} columns={columns} rows={all} loading={loading} emptyText={t('fuel.setup.stations.empty')} />
      <StationDialog open={creating} stations={all} onClose={() => setCreating(false)} />
      <ConfirmDialog
        open={Boolean(archiving)}
        title={t('fuel.setup.stations.archiveTitle', { name: archiving?.location?.name })}
        text={t('fuel.setup.stations.archiveHelp')}
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
    </section>
  )
}
