import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, DecimalInput, Dialog, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { zonedToUtc } from '@/lib/dates'
import { compareDecimal } from '@/lib/money'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog, Back, Detail, DetailState, Figure, FormFooter, Litres, When } from './FuelParts'
import { fuelKey, productLabel, SETUP_MANAGE } from './fuelData'
import { TankDialog } from './SetupTanks'

let nextRow = 0
const blankRow = () => ({ key: `row-${(nextRow += 1)}`, dip_cm: '', litres: '' })

/** FUEL-06: the rows of a chart rise in both columns and stay within the tank; the API checks again. */
function chartProblem(rows, capacity) {
  if (rows.some((row) => row.dip_cm === null || row.litres === null || row.dip_cm === '' || row.litres === '')) return 'incomplete'
  if (rows.length < 2) return 'tooFew'
  for (let index = 1; index < rows.length; index += 1) {
    if (compareDecimal(rows[index].dip_cm, rows[index - 1].dip_cm) <= 0 || compareDecimal(rows[index].litres, rows[index - 1].litres) <= 0) return 'notRising'
  }
  if (capacity && rows.some((row) => compareDecimal(row.litres, capacity) > 0)) return 'aboveCapacity'
  return null
}

/** FUEL-06: a new calibration chart as rows of dip (cm) and litres; the old charts stay. */
function ChartDialog({ tank, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const zone = useTimeZone()
  const [rows, setRows] = useState([blankRow(), blankRow()])
  const [note, setNote] = useState('')
  const [from, setFrom] = useState('')
  const [shown, setShown] = useState(false)
  const problem = chartProblem(rows, tank.capacity_litres)
  const save = useMutation({
    mutationFn: () =>
      api.post(`fuel/tanks/${tank.id}/strapping`, {
        points: rows.map((row) => ({ dip_cm: row.dip_cm, litres: row.litres })),
        note: note.trim() || null,
        ...(from ? { effective_from: zonedToUtc(from, zone) } : {}),
      }),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['points', 'note', 'effective_from'])
  const update = (key, name) => (value) => setRows((current) => current.map((row) => (row.key === key ? { ...row, [name]: value } : row)))
  const close = () => {
    save.reset()
    onClose()
  }
  return (
    <Dialog
      open
      size="lg"
      title={t('fuel.tank.chart.new')}
      onClose={close}
      footer={
        <FormFooter
          action={t('fuel.tank.chart.save')}
          loading={save.isPending}
          disabled={Boolean(problem)}
          onClose={close}
          onSubmit={() => {
            setShown(true)
            save.mutate()
          }}
        />
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('fuel.tank.chart.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {errors.fields.points ? <Alert tone="danger" title={errors.fields.points} /> : null}
        {problem && problem !== 'incomplete' ? <Alert tone="warning" title={t(`fuel.tank.chart.problems.${problem}`)} /> : null}
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField type="datetime-local" label={t('fuel.tank.chart.effectiveFrom')} help={t('fuel.tank.chart.effectiveHelp')} value={from} error={errors.fields.effective_from} onChange={(event) => setFrom(event.target.value)} />
          <TextField label={t('fuel.fields.note')} value={note} maxLength={500} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} />
        </div>
        <ul aria-label={t('fuel.tank.chart.rows')} className="flex flex-col gap-3">
          {rows.map((row, index) => (
            <li key={row.key} className="grid grid-cols-[1fr_1fr_auto] items-end gap-3">
              <DecimalInput label={t('fuel.tank.chart.dip', { n: index + 1 })} suffix={t('fuel.units.cm')} value={row.dip_cm} maxDecimals={2} maxIntegerDigits={5} positive={false} showErrors={shown} onChange={update(row.key, 'dip_cm')} />
              <DecimalInput label={t('fuel.tank.chart.litres', { n: index + 1 })} suffix={t('fuel.units.litres')} value={row.litres} maxDecimals={3} maxIntegerDigits={13} positive={false} showErrors={shown} onChange={update(row.key, 'litres')} />
              <Button variant="ghost" aria-label={t('fuel.tank.chart.removeRow', { n: index + 1 })} disabled={rows.length <= 2} onClick={() => setRows((current) => current.filter((entry) => entry.key !== row.key))}>
                {t('fuel.actions.remove')}
              </Button>
            </li>
          ))}
        </ul>
        <div>
          <Button icon="plus" onClick={() => setRows((current) => [...current, blankRow()])}>
            {t('fuel.tank.chart.addRow')}
          </Button>
        </div>
      </div>
    </Dialog>
  )
}

/** FUEL-06: read a dip off the chart in force before writing it down. */
function DipPreview({ tank }) {
  const { t } = useTranslation()
  const [dip, setDip] = useState('')
  const convert = useMutation({ mutationFn: () => api.get(`fuel/tanks/${tank.id}/dip-convert?dip_cm=${encodeURIComponent(dip)}`) })
  return (
    <Card title={t('fuel.tank.preview.title')} subtitle={t('fuel.tank.preview.help')}>
      <div className="flex flex-wrap items-end gap-3">
        <DecimalInput label={t('fuel.tank.preview.dip')} suffix={t('fuel.units.cm')} value={dip} maxDecimals={2} maxIntegerDigits={5} positive={false} onChange={(value) => { convert.reset(); setDip(value) }} />
        <Button loading={convert.isPending} disabled={!dip} onClick={() => convert.mutate()}>
          {t('fuel.tank.preview.convert')}
        </Button>
      </div>
      <div className="mt-3" aria-live="polite">
        {convert.data?.data ? (
          <p className="text-body text-ink">
            {t('fuel.tank.preview.result')} <Litres value={convert.data.data.litres} className="font-medium" />
          </p>
        ) : null}
        {convert.isError ? <Alert tone="danger" title={errorMessage(convert.error)} /> : null}
      </div>
    </Card>
  )
}

/** FUEL-05, FUEL-06: one tank: its book stock and baseline, its calibration charts and the dip-to-litres preview. */
export default function TankDetail() {
  const { t } = useTranslation()
  const { tankId } = useParams()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [editing, setEditing] = useState(false)
  const [addingChart, setAddingChart] = useState(false)
  const [archiving, setArchiving] = useState(false)
  const [open, setOpen] = useState(null)
  const query = useQuery({ queryKey: [...fuelKey, 'tank', tankId], queryFn: () => api.get(`fuel/tanks/${tankId}`) })
  const charts = useQuery({ queryKey: [...fuelKey, 'tank', tankId, 'charts'], queryFn: () => api.get(`fuel/tanks/${tankId}/strapping`) })
  const archive = useMutation({
    mutationFn: () => api.post(`fuel/tanks/${tankId}/archive`),
    onSuccess: async () => {
      setArchiving(false)
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const tank = query.data?.data
  const back = <Back to="/fuel/setup?tab=tanks">{t('fuel.tank.back')}</Back>
  if (!tank) return <DetailState query={query} back={back} notFound={t('fuel.tank.notFound')} />

  const manage = can(SETUP_MANAGE)
  const list = charts.data?.data ?? []
  const shownChart = open ?? list[0]?.id
  const pointColumns = [
    { key: 'dip_cm', label: t('fuel.tank.chart.dipColumn'), align: 'end', render: (point) => <Figure value={point.dip_cm} unit={t('fuel.units.cm')} /> },
    { key: 'litres', label: t('fuel.tank.chart.litresColumn'), align: 'end', render: (point) => <Litres value={point.litres} /> },
  ]
  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('fuel.tank.eyebrow')}
        title={`${tank.code} · ${tank.name}`}
        description={[tank.location?.name, productLabel(tank.product)].filter(Boolean).join(' · ')}
        actions={
          manage && !tank.archived_at ? (
            <>
              <Button onClick={() => setEditing(true)}>{t('fuel.actions.edit')}</Button>
              <Button variant="danger" onClick={() => setArchiving(true)}>
                {t('fuel.actions.archive')}
              </Button>
            </>
          ) : null
        }
      />
      {tank.archived_at ? <Alert tone="info" title={t('fuel.tank.archived')} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('fuel.tank.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Detail label={t('fuel.fields.capacity')}>
              <Litres value={tank.capacity_litres} />
            </Detail>
            <Detail label={t('fuel.fields.tolerance')}>{tank.tolerance_litres ? <Litres value={tank.tolerance_litres} /> : t('fuel.tank.companyTolerance')}</Detail>
            <Detail label={t('fuel.tank.book')}>
              <Litres value={tank.book_litres} />
            </Detail>
            <Detail label={t('fuel.tank.baseline')}>
              <span className="flex flex-col">
                <Litres value={tank.baseline_litres} />
                <span className="text-caption text-ink-muted">{tank.baseline_at ? <When value={tank.baseline_at} /> : t('fuel.tank.noBaseline')}</span>
              </span>
            </Detail>
          </dl>
        </Card>

        <Card
          title={t('fuel.tank.charts')}
          subtitle={t('fuel.tank.chartsHelp')}
          actions={
            manage && !tank.archived_at ? (
              <Button icon="plus" onClick={() => setAddingChart(true)}>
                {t('fuel.tank.chart.new')}
              </Button>
            ) : null
          }
        >
          {list.length === 0 ? <p className="text-body text-ink-muted">{t('fuel.tank.chartsEmpty')}</p> : null}
          <ul className="flex flex-col gap-3">
            {list.map((chart) => (
              <li key={chart.id} className="flex flex-col gap-3 rounded-md border border-border p-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <div className="flex flex-col">
                    <span className="text-body text-ink">
                      {t('fuel.tank.chart.inForceFrom')} <When value={chart.effective_from} />
                    </span>
                    <span className="text-caption text-ink-muted">{[t('fuel.tank.chart.pointCount', { count: chart.points.length }), chart.created_by?.name, chart.note].filter(Boolean).join(' · ')}</span>
                  </div>
                  <Button variant="ghost" aria-expanded={shownChart === chart.id} onClick={() => setOpen(shownChart === chart.id ? '' : chart.id)}>
                    {shownChart === chart.id ? t('fuel.actions.hidePoints') : t('fuel.actions.showPoints')}
                  </Button>
                </div>
                {shownChart === chart.id ? <DataTable caption={t('fuel.tank.chart.points')} columns={pointColumns} rows={chart.points.map((point) => ({ ...point, id: point.dip_cm }))} /> : null}
              </li>
            ))}
          </ul>
        </Card>

        <DipPreview tank={tank} />
      </div>
      {editing ? <TankDialog open tank={tank} onClose={() => setEditing(false)} /> : null}
      {addingChart ? <ChartDialog tank={tank} onClose={() => setAddingChart(false)} /> : null}
      <ConfirmDialog
        open={archiving}
        title={t('fuel.tank.archiveTitle', { code: tank.code })}
        text={t('fuel.tank.archiveHelp')}
        action={t('fuel.actions.archive')}
        variant="danger"
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onClose={() => {
          archive.reset()
          setArchiving(false)
        }}
        onConfirm={() => archive.mutate()}
      />
    </>
  )
}
