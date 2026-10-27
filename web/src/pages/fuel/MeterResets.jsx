import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, DecimalInput, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { compareDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone, zoneOfRecord } from '@/lib/useTimeZone'
import { FilterBar, FormFooter, FuelList, Litres, RecordStatus, StationSelect, When } from './FuelParts'
import { apiMessage, fuelKey, RESET_APPROVE, RESET_REQUEST, RESET_STATUSES, useStations } from './fuelData'

const FIELDS = ['nozzle_id', 'shift_id', 'reading_before', 'reading_after', 'reason']

/** FUEL-17: ask for a reset when a meter was replaced or rolled over: the reading before, the reading after and why. */
function ResetDialog({ onClose }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const zone = useTimeZone()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ nozzle_id: '', shift_id: '', before: '', after: '', reason: '' })
  const [shown, setShown] = useState(false)
  const set = (name) => (value) => setForm((current) => ({ ...current, [name]: value }))
  const nozzles = useQuery({ queryKey: [...fuelKey, 'nozzles', 'all'], queryFn: () => api.get('fuel/nozzles'), staleTime: 30_000 })
  const list = (nozzles.data?.data ?? []).filter((nozzle) => !nozzle.archived_at)
  const nozzle = list.find((entry) => entry.id === form.nozzle_id)
  const shifts = useQuery({
    queryKey: [...fuelKey, 'shifts', 'open', nozzle?.location?.id],
    queryFn: () => api.get(`fuel/shifts?status=open&location_id=${nozzle.location.id}&per_page=50`),
    enabled: Boolean(nozzle?.location?.id),
  })
  const open = shifts.data?.data ?? []
  const save = useMutation({
    mutationFn: () =>
      api.post('fuel/meter-resets', {
        nozzle_id: form.nozzle_id,
        shift_id: form.shift_id || null,
        reading_before: form.before,
        reading_after: form.after,
        reason: form.reason.trim(),
      }),
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
  const above = form.before && form.after && compareDecimal(form.after, form.before) > 0
  const ready = form.nozzle_id && form.before !== null && form.before !== '' && form.after !== null && form.after !== '' && !above && form.reason.trim().length >= 2
  return (
    <Dialog
      open
      title={t('fuel.resets.new')}
      onClose={close}
      footer={
        <FormFooter
          action={t('fuel.resets.send')}
          loading={save.isPending}
          disabled={!ready}
          onClose={close}
          onSubmit={() => {
            setShown(true)
            save.mutate()
          }}
        />
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-body text-ink-muted">{t('fuel.resets.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Select
          label={t('fuel.fields.nozzle')}
          placeholder={t('fuel.pickers.nozzle')}
          options={list.map((entry) => ({ value: entry.id, label: `${entry.label} · ${entry.location?.name ?? ''}` }))}
          value={form.nozzle_id}
          error={errors.fields.nozzle_id}
          required
          onChange={(event) => setForm((current) => ({ ...current, nozzle_id: event.target.value, shift_id: '' }))}
        />
        <Select
          label={t('fuel.resets.shift')}
          help={t('fuel.resets.shiftHelp')}
          options={[{ value: '', label: t('fuel.resets.noShift') }, ...open.map((shift) => ({ value: shift.id, label: `${shift.opened_by?.name ?? ''} · ${formatCompanyTime(shift.opened_at, locale, zoneOfRecord(zone))}` }))]}
          value={form.shift_id}
          error={errors.fields.shift_id}
          disabled={!nozzle}
          onChange={(event) => set('shift_id')(event.target.value)}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <DecimalInput label={t('fuel.resets.before')} suffix={t('fuel.units.litres')} value={form.before} maxDecimals={3} maxIntegerDigits={13} positive={false} showErrors={shown} error={errors.fields.reading_before} required onChange={set('before')} />
          <DecimalInput
            label={t('fuel.resets.after')}
            suffix={t('fuel.units.litres')}
            value={form.after}
            maxDecimals={3}
            maxIntegerDigits={13}
            positive={false}
            showErrors={shown}
            error={errors.fields.reading_after ?? (above ? t('fuel.resets.notLower') : undefined)}
            required
            onChange={set('after')}
          />
        </div>
        <TextField label={t('fuel.resets.reason')} value={form.reason} maxLength={500} error={errors.fields.reason} required onChange={(event) => set('reason')(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** FUEL-17: meter resets; a person other than the requester approves or rejects a waiting one. */
export default function MeterResets() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const { stations } = useStations()
  const [filters, setFilters] = useState({ status: 'pending', location_id: '' })
  const [requesting, setRequesting] = useState(false)
  const set = (name) => (value) => setFilters((current) => ({ ...current, [name]: value }))
  const decide = useMutation({
    mutationFn: ({ reset, decision }) => api.post(`fuel/meter-resets/${reset.id}/${decision}`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: fuelKey })
    },
  })
  const deciding = decide.isPending ? decide.variables?.reset.id : null
  const columns = [
    { key: 'nozzle', label: t('fuel.fields.nozzle'), render: (row) => <span className="font-medium text-ink">{row.nozzle?.label}</span> },
    { key: 'station', label: t('fuel.fields.station'), render: (row) => row.location?.name ?? '' },
    { key: 'before', label: t('fuel.resets.before'), align: 'end', render: (row) => <Litres value={row.reading_before} /> },
    { key: 'after', label: t('fuel.resets.after'), align: 'end', render: (row) => <Litres value={row.reading_after} /> },
    { key: 'reason', label: t('fuel.resets.reason'), wrap: true, render: (row) => row.reason },
    { key: 'status', label: t('fuel.columns.status'), render: (row) => <RecordStatus status={row.status} /> },
    {
      key: 'requested',
      label: t('fuel.resets.requestedBy'),
      render: (row) => (
        <span className="flex flex-col">
          <span>{row.requested_by?.name}</span>
          <span className="text-caption text-ink-muted">
            <When value={row.requested_at} />
          </span>
        </span>
      ),
    },
    { key: 'decided', label: t('fuel.settlement.decidedBy'), render: (row) => row.decided_by?.name ?? '' },
    ...(can(RESET_APPROVE)
      ? [
          {
            key: 'actions',
            label: '',
            align: 'end',
            render: (row) =>
              row.status === 'pending' ? (
                <span className="flex justify-end gap-2">
                  <Button variant="primary" aria-label={t('fuel.resets.approveLabel', { nozzle: row.nozzle?.label })} loading={deciding === row.id && decide.variables?.decision === 'approve'} disabled={decide.isPending} onClick={() => decide.mutate({ reset: row, decision: 'approve' })}>
                    {t('fuel.actions.approve')}
                  </Button>
                  <Button variant="danger" aria-label={t('fuel.resets.rejectLabel', { nozzle: row.nozzle?.label })} loading={deciding === row.id && decide.variables?.decision === 'reject'} disabled={decide.isPending} onClick={() => decide.mutate({ reset: row, decision: 'reject' })}>
                    {t('fuel.actions.reject')}
                  </Button>
                </span>
              ) : null,
          },
        ]
      : []),
  ]
  return (
    <>
      <PageHeader
        title={t('fuel.resets.title')}
        description={t('fuel.resets.description')}
        actions={
          can(RESET_REQUEST) ? (
            <Button variant="primary" icon="plus" onClick={() => setRequesting(true)}>
              {t('fuel.resets.new')}
            </Button>
          ) : null
        }
      />
      {decide.isError ? <Alert tone="danger" title={apiMessage(decide.error)} /> : null}
      <FilterBar>
        <Select label={t('fuel.columns.status')} options={[{ value: '', label: t('fuel.filters.anyStatus') }, ...RESET_STATUSES.map((value) => ({ value, label: t(`fuel.states.${value}`) }))]} value={filters.status} onChange={(event) => set('status')(event.target.value)} className="w-52" />
        <StationSelect all stations={stations} value={filters.location_id} onChange={set('location_id')} className="w-56" />
      </FilterBar>
      <FuelList queryKey="meter-resets" endpoint="fuel/meter-resets" params={filters} columns={columns} caption={t('fuel.resets.title')} emptyText={filters.status === 'pending' ? t('fuel.resets.emptyPending') : t('fuel.resets.empty')} />
      {requesting ? <ResetDialog onClose={() => setRequesting(false)} /> : null}
    </>
  )
}
