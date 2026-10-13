import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useItemCategories } from '@/pages/catalogue/catalogueData'
import { Back, ItemPicker } from './InventoryParts'
import { inventoryKey, itemLabel, locationOptions, usePlaces } from './inventoryData'

/**
 * INV-09: start a stock count at one location: a full count (every item
 * with stock or a reorder rule there) or a cycle count of chosen items or
 * a category. Starting it freezes those items there and snapshots the
 * expected quantities; the count opens for entry afterwards.
 */
export default function CountForm() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const categories = useItemCategories()
  const [location, setLocation] = useState('')
  const [kind, setKind] = useState('full')
  const [scope, setScope] = useState('items') // cycle counts: 'items' | 'category'
  const [items, setItems] = useState([])
  const [category, setCategory] = useState('')
  const [note, setNote] = useState('')
  const [submitted, setSubmitted] = useState(false)

  const save = useMutation({
    mutationFn: () =>
      api.post('inventory/counts', {
        location_id: location,
        kind,
        ...(kind === 'cycle' && scope === 'items' ? { item_ids: items.map((item) => item.id) } : {}),
        ...(kind === 'cycle' && scope === 'category' ? { category_id: category } : {}),
        ...(note.trim() ? { note: note.trim() } : {}),
      }),
    onSuccess: async (response) => {
      toast.success(t('inventory.counts.created', { number: response?.data?.number ?? '' }))
      await queryClient.invalidateQueries({ queryKey: inventoryKey })
      navigate(`/inventory/counts/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, ['location_id', 'kind', 'item_ids', 'category_id', 'note'])
  const cycleReady = kind === 'full' || (scope === 'items' ? items.length > 0 : Boolean(category))
  const ready = location && cycleReady

  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <>
      <Back to="/inventory/counts">{t('inventory.counts.back')}</Back>
      <PageHeader title={t('inventory.counts.newTitle')} description={t('inventory.counts.newHelp')} />
      <form noValidate onSubmit={submit} className="flex flex-col gap-5">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <Card>
          <div className="grid gap-4 sm:grid-cols-2">
            <Select
              label={t('inventory.fields.location')}
              placeholder={t('inventory.pickers.location')}
              options={locationOptions(places.activeLocations)}
              value={location}
              required
              error={errors.fields.location_id ?? (submitted && !location ? t('inventory.validation.location') : undefined)}
              onChange={(event) => setLocation(event.target.value)}
            />
            <Select
              label={t('inventory.fields.countKind')}
              options={['full', 'cycle'].map((value) => ({ value, label: t(`inventory.countKinds.${value}`) }))}
              value={kind}
              help={t(`inventory.counts.kindHelp.${kind}`)}
              error={errors.fields.kind}
              onChange={(event) => setKind(event.target.value)}
            />
            {kind === 'cycle' ? (
              <Select
                label={t('inventory.counts.countWhat')}
                options={[
                  { value: 'items', label: t('inventory.counts.chosenItems') },
                  { value: 'category', label: t('inventory.counts.aCategory') },
                ]}
                value={scope}
                onChange={(event) => setScope(event.target.value)}
              />
            ) : null}
            {kind === 'cycle' && scope === 'category' ? (
              <Select
                label={t('inventory.fields.category')}
                placeholder={t('inventory.pickers.category')}
                options={categories.all.filter((entry) => !entry.archived_at).map((entry) => ({ value: entry.id, label: entry.name }))}
                value={category}
                required
                error={errors.fields.category_id ?? (submitted && !category ? t('inventory.validation.category') : undefined)}
                onChange={(event) => setCategory(event.target.value)}
              />
            ) : null}
            <TextField label={t('inventory.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} className="sm:col-span-2" />
          </div>
        </Card>
        {kind === 'cycle' && scope === 'items' ? (
          <Card title={t('inventory.counts.itemsTitle')} subtitle={t('inventory.counts.itemsHelp')}>
            <div className="flex flex-col gap-3">
              <ItemPicker
                label={t('inventory.counts.addItem')}
                value={null}
                exclude={items.map((item) => item.id)}
                error={errors.fields.item_ids ?? (submitted && items.length === 0 ? t('inventory.validation.items') : undefined)}
                onChange={(item) => item && setItems((current) => [...current, item])}
                className="max-w-field"
              />
              {items.length ? (
                <ul aria-label={t('inventory.counts.itemsTitle')} className="flex flex-col divide-y divide-border">
                  {items.map((item) => (
                    <li key={item.id} className="flex items-center justify-between gap-3 py-2">
                      <span>{itemLabel(item)}</span>
                      <Button
                        variant="ghost"
                        icon="remove"
                        aria-label={t('inventory.counts.removeItem', { item: item.name })}
                        onClick={() => setItems((current) => current.filter((entry) => entry.id !== item.id))}
                      />
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </Card>
        ) : null}
        <div className="flex flex-wrap gap-2">
          <Button variant="ghost" onClick={() => navigate('/inventory/counts')}>
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('inventory.counts.start')}
          </Button>
        </div>
      </form>
    </>
  )
}
