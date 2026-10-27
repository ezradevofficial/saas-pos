import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, TextField } from '@/components/ds'
import { ConfirmDialog, FormFooter, Litres, StationSelect, TankSelect, When } from './FuelParts'
import { fuelKey, productLabel, SETUP_MANAGE, useStations, useTanks } from './fuelData'

function PumpDialog({ onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { stations } = useStations()
  const [form, setForm] = useState({ location_id: '', code: '', name: '' })
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const save = useMutation({
    mutationFn: () => api.post('fuel/pumps', { location_id: form.location_id, code: form.code.trim(), name: form.name.trim() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['location_id', 'code', 'name'])
  const close = () => {
    save.reset()
    onClose()
  }
  return (
    <Dialog open title={t('fuel.setup.pumps.new')} onClose={close} footer={<FormFooter action={t('fuel.setup.pumps.create')} loading={save.isPending} disabled={!form.location_id || !form.code.trim() || !form.name.trim()} onClose={close} onSubmit={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <StationSelect stations={stations} value={form.location_id} error={errors.fields.location_id} required onChange={set('location_id')} />
        <TextField label={t('fuel.fields.code')} value={form.code} maxLength={20} error={errors.fields.code} required onChange={(event) => set('code')(event.target.value)} />
        <TextField label={t('fuel.fields.name')} value={form.name} maxLength={100} error={errors.fields.name} required onChange={(event) => set('name')(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** FUEL-07: a nozzle of a pump, linked to one tank of the same station. */
function NozzleDialog({ pump, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { tanks } = useTanks(pump.location?.id)
  const [tank, setTank] = useState('')
  const [code, setCode] = useState('')
  const save = useMutation({
    mutationFn: () => api.post('fuel/nozzles', { pump_id: pump.id, tank_id: tank, code: code.trim() }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['pump_id', 'tank_id', 'code'])
  const close = () => {
    save.reset()
    onClose()
  }
  return (
    <Dialog open title={t('fuel.setup.nozzles.new', { pump: pump.code })} onClose={close} footer={<FormFooter action={t('fuel.setup.nozzles.create')} loading={save.isPending} disabled={!tank || !code.trim()} onClose={close} onSubmit={() => save.mutate()} />}>
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('fuel.setup.nozzles.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <TextField label={t('fuel.fields.code')} value={code} maxLength={20} error={errors.fields.code} required onChange={(event) => setCode(event.target.value)} />
        <TankSelect tanks={tanks} value={tank} error={errors.fields.tank_id} required onChange={setTank} />
      </div>
    </Dialog>
  )
}

/** FUEL-07: the pumps of the stations, each with its nozzles, the tank behind them and the last meter reading. */
export default function SetupPumps() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const query = useQuery({ queryKey: [...fuelKey, 'pumps'], queryFn: () => api.get('fuel/pumps'), staleTime: 15_000 })
  const [creating, setCreating] = useState(false)
  const [addingTo, setAddingTo] = useState(null)
  const [archiving, setArchiving] = useState(null)
  const archive = useMutation({
    mutationFn: (target) => api.post(`fuel/${target.kind}/${target.id}/archive`),
    onSuccess: async () => {
      setArchiving(null)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const manage = can(SETUP_MANAGE)
  const pumps = (query.data?.data ?? []).filter((pump) => !pump.archived_at)
  const nozzleColumns = (pump) => [
    { key: 'label', label: t('fuel.fields.nozzle'), render: (row) => <span className="font-medium text-ink">{row.label}</span> },
    { key: 'tank', label: t('fuel.fields.tank'), render: (row) => (row.tank ? `${row.tank.code} · ${row.tank.name}` : '') },
    { key: 'product', label: t('fuel.fields.product'), render: (row) => productLabel(row.product) },
    { key: 'reading', label: t('fuel.setup.nozzles.lastReading'), align: 'end', render: (row) => <Litres value={row.last_reading_litres} /> },
    { key: 'at', label: t('fuel.setup.nozzles.readingAt'), render: (row) => <When value={row.last_reading_at} /> },
    ...(manage
      ? [
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) => (
              <Button variant="ghost" aria-label={t('fuel.setup.nozzles.archiveLabel', { name: row.label })} onClick={() => setArchiving({ kind: 'nozzles', id: row.id, name: row.label, pump })}>
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
        <p className="text-body text-ink-muted">{t('fuel.setup.pumps.description')}</p>
        {manage ? (
          <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
            {t('fuel.setup.pumps.new')}
          </Button>
        ) : null}
      </div>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {!query.isPending && pumps.length === 0 ? <p className="text-body text-ink-muted">{t('fuel.setup.pumps.empty')}</p> : null}
      {pumps.map((pump) => (
        <Card
          key={pump.id}
          title={`${pump.code} · ${pump.name}`}
          subtitle={pump.location?.name}
          actions={
            manage ? (
              <>
                <Button icon="plus" onClick={() => setAddingTo(pump)}>
                  {t('fuel.setup.nozzles.add')}
                </Button>
                <Button variant="ghost" aria-label={t('fuel.setup.pumps.archiveLabel', { name: pump.code })} onClick={() => setArchiving({ kind: 'pumps', id: pump.id, name: pump.code })}>
                  {t('fuel.actions.archive')}
                </Button>
              </>
            ) : null
          }
        >
          <DataTable caption={t('fuel.setup.nozzles.caption', { pump: pump.code })} columns={nozzleColumns(pump)} rows={pump.nozzles ?? []} emptyText={t('fuel.setup.nozzles.empty')} />
        </Card>
      ))}
      {creating ? <PumpDialog onClose={() => setCreating(false)} /> : null}
      {addingTo ? <NozzleDialog pump={addingTo} onClose={() => setAddingTo(null)} /> : null}
      <ConfirmDialog
        open={Boolean(archiving)}
        title={t(archiving?.kind === 'pumps' ? 'fuel.setup.pumps.archiveTitle' : 'fuel.setup.nozzles.archiveTitle', { name: archiving?.name })}
        text={t(archiving?.kind === 'pumps' ? 'fuel.setup.pumps.archiveHelp' : 'fuel.setup.nozzles.archiveHelp')}
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
