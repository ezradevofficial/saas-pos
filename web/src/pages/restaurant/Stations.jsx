import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, DataTable, Dialog, MultiSelect, Select, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { ConfirmDialog, LocationPicker } from './RestaurantParts'
import { restaurantKey, STATION_KINDS, STATION_MANAGE, useCanAt, useItemCategories, useLocationChoice } from './restaurantData'

/** REST-08: add or edit a station and the categories routed to it. */
function StationDialog({ station, location, categories, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState({
    name: station?.name ?? '',
    kind: station?.kind ?? 'kitchen',
    printer: station?.printer ?? '',
    is_default: Boolean(station?.is_default),
    category_ids: (station?.category_ids ?? []).map(String),
  })
  const set = (field) => (event) => setForm((current) => ({ ...current, [field]: event.target.value }))
  const save = useMutation({
    mutationFn: () => {
      const body = {
        location_id: location.id,
        name: form.name.trim(),
        kind: form.kind,
        printer: form.printer.trim() || null,
        is_default: form.is_default,
        sort: station?.sort ?? 0,
        category_ids: form.category_ids,
      }
      return station?.id ? api.put(`restaurant/stations/${station.id}`, body) : api.post('restaurant/stations', body)
    },
    onSuccess: () => {
      toast.success(t('restaurant.stations.saved'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['name', 'kind', 'printer', 'is_default', 'category_ids'])
  return (
    <Dialog
      open
      size="lg"
      title={station?.id ? t('restaurant.stations.edit') : t('restaurant.stations.add')}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} disabled={!form.name.trim()} onClick={() => save.mutate()}>
            {t('restaurant.stations.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('restaurant.fields.name')} value={form.name} error={errors.fields.name} onChange={set('name')} />
          <Select label={t('restaurant.stations.kind')} options={STATION_KINDS.map((kind) => ({ value: kind, label: t(`restaurant.stations.kinds.${kind}`) }))} value={form.kind} error={errors.fields.kind} onChange={set('kind')} />
          <TextField label={t('restaurant.stations.printer')} help={t('restaurant.stations.printerHelp')} value={form.printer} error={errors.fields.printer} onChange={set('printer')} />
        </div>
        <Switch label={t('restaurant.stations.isDefault')} checked={form.is_default} onChange={(next) => setForm((current) => ({ ...current, is_default: next }))} />
        <MultiSelect
          label={t('restaurant.stations.categories')}
          help={t('restaurant.stations.categoriesHelp')}
          placeholder={t('restaurant.stations.noCategories')}
          options={categories.map((category) => ({ value: String(category.id), label: category.name }))}
          value={form.category_ids}
          error={errors.fields.category_ids}
          onChange={(next) => setForm((current) => ({ ...current, category_ids: next }))}
        />
      </div>
    </Dialog>
  )
}

/**
 * REST-08: the stations of a location (kitchen, bar, other), their printers,
 * the default one, and the item categories routed to each. Changing them
 * needs restaurant.station.manage at the location.
 */
export default function Stations() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const places = useLocationChoice()
  const { location, isPending: placesPending } = places
  const canManage = useCanAt(location)(STATION_MANAGE)
  const categories = useItemCategories()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...restaurantKey, 'stations', location?.id], queryFn: () => api.get(`restaurant/stations?location_id=${location.id}`), enabled: Boolean(location) })
  const stations = (query.data?.data ?? []).filter((entry) => !entry.archived_at)
  const archive = useMutation({
    mutationFn: (id) => api.post(`restaurant/stations/${id}/archive`),
    onSuccess: () => {
      toast.success(t('restaurant.stations.archived'))
      queryClient.invalidateQueries({ queryKey: restaurantKey })
      setDialog(null)
    },
  })
  const categoryNames = (ids) => (ids ?? []).map((id) => categories.find((category) => String(category.id) === String(id))?.name).filter(Boolean).join(', ')
  const columns = [
    { key: 'name', label: t('restaurant.fields.name'), render: (row) => row.name },
    { key: 'kind', label: t('restaurant.stations.kind'), render: (row) => t(`restaurant.stations.kinds.${row.kind}`, { defaultValue: row.kind }) },
    { key: 'printer', label: t('restaurant.stations.printer'), render: (row) => row.printer || t('restaurant.stations.noPrinter') },
    { key: 'default', label: t('restaurant.stations.defaultColumn'), render: (row) => (row.is_default ? t('restaurant.yes') : '') },
    { key: 'categories', label: t('restaurant.stations.categories'), wrap: true, render: (row) => categoryNames(row.category_ids) || t('restaurant.stations.noCategories') },
    {
      key: 'actions',
      label: <span className="sr-only">{t('restaurant.fields.actions')}</span>,
      align: 'end',
      render: (row) =>
        canManage ? (
          <div className="flex justify-end gap-2">
            <Button onClick={() => setDialog({ type: 'edit', station: row })}>{t('restaurant.stations.edit')}</Button>
            <Button variant="ghost" onClick={() => setDialog({ type: 'archive', station: row })}>
              {t('restaurant.stations.archive')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('restaurant.stations.title')}
        description={t('restaurant.stations.description')}
        actions={
          canManage ? (
            <Button variant="primary" onClick={() => setDialog({ type: 'edit' })}>
              {t('restaurant.stations.add')}
            </Button>
          ) : null
        }
      />
      <div className="flex flex-col gap-5">
        <LocationPicker choice={places} />
        {placesPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {!placesPending && !location ? <p className="text-ink-muted">{t('restaurant.noLocation')}</p> : null}
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {location && !query.isError ? (
          <DataTable caption={t('restaurant.stations.title')} columns={columns} rows={stations} loading={query.isPending} emptyText={t('restaurant.stations.empty')} />
        ) : null}
      </div>
      {dialog?.type === 'edit' ? <StationDialog key={dialog.station?.id ?? 'new'} station={dialog.station} location={location} categories={categories} onClose={() => setDialog(null)} /> : null}
      <ConfirmDialog
        open={dialog?.type === 'archive'}
        title={t('restaurant.stations.archive')}
        message={t('restaurant.stations.archiveAsk', { name: dialog?.station?.name ?? '' })}
        confirmLabel={t('restaurant.stations.archive')}
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onClose={() => setDialog(null)}
        onConfirm={() => archive.mutate(dialog.station.id)}
      />
    </>
  )
}
