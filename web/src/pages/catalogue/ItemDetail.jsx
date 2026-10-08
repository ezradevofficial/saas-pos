import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { DuplicatesAlert } from '@/components/DuplicatesAlert'
import { HistoryPanel } from '@/components/HistoryPanel'
import { Alert, Button, Card, Icon, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatBytes, formatDecimal } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog } from '@/pages/settings/ConfirmDialog'
import { IMAGE_TYPES, MAX_IMAGE_BYTES, MAX_IMAGES, useItemCategories, useTaxCategories, useUoms } from './catalogueData'
import { ItemForm } from './ItemForm'
import { ItemPrices } from './ItemPrices'

const detailKey = (id) => ['items', 'detail', id]

/**
 * MD-02: an item's images, in order. Upload (JPEG, PNG or WebP up to 2 MB,
 * at most eight) with progress; reorder with buttons, focus kept on the
 * moved image and its new place announced; remove after a confirmation.
 */
function ItemImages({ item, canEdit }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const inputRef = useRef(null)
  const buttons = useRef(new Map())
  const [progress, setProgress] = useState(null) // { name, fraction }
  const [localError, setLocalError] = useState(null)
  const [removing, setRemoving] = useState(null)
  const [focus, setFocus] = useState(null) // { id, step }
  const [announcement, setAnnouncement] = useState('')
  const images = [...(item.images ?? [])].sort((a, b) => a.position - b.position)
  const name = (item?.name ?? '')

  const saved = (response) => {
    if (response?.data) queryClient.setQueryData(detailKey(item.id), { data: response.data })
    queryClient.invalidateQueries({ queryKey: ['history', 'item', item.id] })
  }

  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('image', file)
      setProgress({ name: file.name, fraction: 0 })
      return api.upload(`items/${item.id}/images`, form, { onProgress: (fraction) => setProgress({ name: file.name, fraction }) })
    },
    onSuccess: saved,
    onSettled: () => setProgress(null),
  })
  const reorder = useMutation({ mutationFn: (ids) => api.put(`items/${item.id}/images/order`, { image_ids: ids }), onSuccess: saved })
  const remove = useMutation({
    mutationFn: (image) => api.delete(`item-images/${image.id}`),
    onSuccess: async () => {
      setRemoving(null)
      await queryClient.invalidateQueries({ queryKey: detailKey(item.id) })
      queryClient.invalidateQueries({ queryKey: ['history', 'item', item.id] })
    },
  })

  // After a move, focus returns to the arrow pressed, or the other one when it is now disabled.
  useEffect(() => {
    if (!focus) return
    const pressed = buttons.current.get(`${focus.id}:${focus.step}`)
    const other = buttons.current.get(`${focus.id}:${-focus.step}`)
    ;(pressed && !pressed.disabled ? pressed : other)?.focus()
  }, [focus, item])

  const move = (index, step) => {
    const ids = images.map((image) => image.id)
    ;[ids[index], ids[index + step]] = [ids[index + step], ids[index]]
    // The new order shows at once; the API answer replaces it.
    queryClient.setQueryData(detailKey(item.id), (data) =>
      data ? { ...data, data: { ...data.data, images: data.data.images.map((image) => ({ ...image, position: ids.indexOf(image.id) + 1 })) } } : data,
    )
    setFocus({ id: images[index].id, step })
    setAnnouncement(t('items.images.movedTo', { position: index + step + 1, total: ids.length }))
    reorder.mutate(ids, { onError: () => queryClient.invalidateQueries({ queryKey: detailKey(item.id) }) })
  }

  const choose = (file) => {
    setLocalError(null)
    upload.reset()
    if (!file) return
    if (!IMAGE_TYPES.includes(file.type)) return setLocalError(t('items.images.wrongType'))
    if (file.size > MAX_IMAGE_BYTES) return setLocalError(t('items.images.tooLarge', { size: formatBytes(file.size, locale), max: formatBytes(MAX_IMAGE_BYTES, locale) }))
    upload.mutate(file)
  }

  const full = images.length >= MAX_IMAGES
  const failure = localError ?? (upload.error ? errorMessage(upload.error) : null) ?? (reorder.error ? errorMessage(reorder.error) : null)

  return (
    <Card
      title={t('items.images.title')}
      subtitle={t('items.images.subtitle', { max: MAX_IMAGES })}
      actions={
        canEdit ? (
          <>
            <input
              ref={inputRef}
              type="file"
              accept={IMAGE_TYPES.join(',')}
              className="sr-only"
              tabIndex={-1}
              aria-label={t('items.images.choose')}
              onChange={(event) => {
                choose(event.target.files?.[0])
                event.target.value = ''
              }}
            />
            <Button icon="image" disabled={full || upload.isPending} onClick={() => inputRef.current?.click()}>
              {t('items.images.add')}
            </Button>
          </>
        ) : null
      }
    >
      <div className="flex flex-col gap-4">
        {failure ? <Alert tone="danger" title={failure} /> : null}
        {full && canEdit ? <p className="text-caption text-ink-muted">{t('items.images.full', { max: MAX_IMAGES })}</p> : null}
        {progress ? (
          <div className="flex flex-col gap-1" role="status">
            <span className="text-caption text-ink-muted">{t('items.images.uploading', { name: progress.name, percent: Math.round(progress.fraction * 100) })}</span>
            <progress className="h-2 w-full accent-primary" value={progress.fraction} max={1} aria-label={t('items.images.progress')} />
          </div>
        ) : null}
        <p className="sr-only" aria-live="polite">
          {announcement}
        </p>
        {images.length === 0 ? (
          <p className="text-ink-muted">{t('items.images.empty')}</p>
        ) : (
          <ol aria-label={t('items.images.title')} className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {images.map((image, index) => {
              const label = t('items.images.alt', { n: index + 1, name })
              return (
                <li key={image.id} className="flex flex-col gap-2">
                  {image.url ? (
                    <img src={image.url} alt={label} className="aspect-square w-full rounded-md border border-border bg-surface-100 object-cover" />
                  ) : (
                    <div className="flex aspect-square w-full items-center justify-center rounded-md border border-border bg-surface-100 text-caption text-ink-muted">{label}</div>
                  )}
                  <div className="flex flex-wrap items-center gap-1">
                    <span className="mr-auto text-caption text-ink-muted tabular-nums">{t('items.images.position', { n: index + 1 })}</span>
                    {canEdit ? (
                      <>
                        <Button
                          ref={(node) => (node ? buttons.current.set(`${image.id}:-1`, node) : buttons.current.delete(`${image.id}:-1`))}
                          variant="ghost"
                          icon="up"
                          className="size-icon-btn px-0"
                          disabled={index === 0}
                          onClick={() => move(index, -1)}
                          aria-label={t('items.images.moveEarlier', { n: index + 1 })}
                        />
                        <Button
                          ref={(node) => (node ? buttons.current.set(`${image.id}:1`, node) : buttons.current.delete(`${image.id}:1`))}
                          variant="ghost"
                          icon="down"
                          className="size-icon-btn px-0"
                          disabled={index === images.length - 1}
                          onClick={() => move(index, 1)}
                          aria-label={t('items.images.moveLater', { n: index + 1 })}
                        />
                        <Button
                          variant="ghost"
                          icon="remove"
                          className="size-icon-btn px-0"
                          onClick={() => setRemoving({ ...image, n: index + 1 })}
                          aria-label={t('items.images.remove', { n: index + 1 })}
                        />
                      </>
                    ) : null}
                  </div>
                </li>
              )
            })}
          </ol>
        )}
      </div>
      <ConfirmDialog
        open={Boolean(removing)}
        title={removing ? t('items.images.removeTitle', { n: removing.n }) : ''}
        confirmLabel={t('items.images.removeConfirm')}
        cancelLabel={t('items.images.keep')}
        pending={remove.isPending}
        error={remove.error ? errorMessage(remove.error) : null}
        failure={remove.error}
        onConfirm={() => remove.mutate(removing)}
        onClose={() => {
          setRemoving(null)
          remove.reset()
        }}
      >
        {t('items.images.removeText')}
      </ConfirmDialog>
    </Card>
  )
}

/**
 * History labels for an item's own fields: units, barcodes, category and
 * type by name, not id; and for its price changes (MD-03 follow-up), the
 * price list by name (from the lists the item's prices show).
 */
function useItemHistoryFields(item) {
  const { t } = useTranslation()
  const locale = useLocale()
  const uoms = useUoms()
  const categories = useItemCategories()
  const taxCategories = useTaxCategories()
  const none = t('history.none')
  const code = (id) => uoms.all.find((uom) => uom.id === id)?.code ?? t('history.unknown')
  const named = (list) => (id) => {
    if (!id) return none
    const found = list.find((entry) => entry.id === id)
    return found ? (found?.name ?? '') : t('history.unknown')
  }
  return {
    type: { format: (value) => (value ? t(`items.types.${value}`, { defaultValue: value }) : none) },
    base_uom_id: { format: (value) => (value ? code(value) : none) },
    category_id: { format: named(categories.all) },
    tax_category_id: { format: named(taxCategories.all) },
    uoms: {
      format: (list) =>
        Array.isArray(list) && list.length ? list.map((uom) => t('items.history.unit', { unit: code(uom.uom_id), factor: formatDecimal(uom.factor, locale) })).join(', ') : none,
    },
    barcodes: {
      format: (list) =>
        Array.isArray(list) && list.length ? list.map((entry) => (entry.uom_id ? `${entry.barcode} (${code(entry.uom_id)})` : entry.barcode)).join(', ') : none,
    },
    images: { format: (list) => (Array.isArray(list) ? t('items.history.images', { count: list.length }) : none) },
    uom_id: { format: (value) => (value ? code(value) : none) },
    price_list_id: { format: (value) => (item?.prices ?? []).find((list) => list.price_list_id === value)?.name ?? t('history.unknown') },
    // The item itself: every entry here is about it.
    item_id: { hidden: true },
  }
}

/** MD-02, MD-07: one item, Details (form, images, prices) and History. */
export default function ItemDetail() {
  const { t } = useTranslation()
  const { itemId } = useParams()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const { can, canWithin } = usePermissions()
  const [duplicates, setDuplicates] = useState(() => location.state?.duplicates ?? [])
  const [archiving, setArchiving] = useState(false)
  const [saved, setSaved] = useState(Boolean(location.state?.created))
  const tab = params.get('tab') === 'history' ? 'history' : 'details'
  const query = useQuery({ queryKey: detailKey(itemId), queryFn: () => api.get(`items/${itemId}`) })
  const item = query.data?.data
  const timeZone = useTimeZone(item?.company_id)
  const historyFields = useItemHistoryFields(item)

  const action = useMutation({
    mutationFn: (kind) => api.post(`items/${itemId}/${kind}`),
    onSuccess: async (response) => {
      setArchiving(false)
      queryClient.setQueryData(detailKey(itemId), { data: response.data })
      await queryClient.invalidateQueries({ queryKey: ['items', 'list'] })
      queryClient.invalidateQueries({ queryKey: ['history', 'item', itemId] })
    },
  })

  const back = (
    <Link to="/catalogue/items" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t('items.back')}
    </Link>
  )
  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <>
        {back}
        <Alert tone="danger" title={query.error.status === 404 ? t('items.notFound') : errorMessage(query.error)} />
      </>
    )
  }

  const chain = item.company_id ? [{ type: 'company', id: item.company_id }] : []
  const allowed = (name) => (item.company_id ? canWithin(name, chain) : can(name))
  const archived = Boolean(item.archived_at)
  const canEdit = allowed('core.item.edit') && !archived
  const canArchive = allowed('core.item.archive')
  const name = (item?.name ?? '')

  return (
    <>
      {back}
      <PageHeader
        eyebrow={<span className="font-mono">{item.code}</span>}
        title={name || item.code}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            {item.type ? <span>{t(`items.types.${item.type}`)}</span> : null}
            <StatusBadge tone={archived ? 'neutral' : 'success'}>{archived ? t('items.status.archivedOne') : t('items.status.activeOne')}</StatusBadge>
          </span>
        }
        actions={
          canArchive ? (
            archived ? (
              <Button icon="restore" loading={action.isPending} onClick={() => action.mutate('restore')}>
                {t('items.restore')}
              </Button>
            ) : (
              <Button variant="danger" icon="archive" onClick={() => setArchiving(true)}>
                {t('items.archive')}
              </Button>
            )
          ) : null
        }
      />
      {saved ? <Alert tone="success" title={t('items.saved', { name })} /> : null}
      {action.isError && !archiving ? <Alert tone="danger" title={errorMessage(action.error)} /> : null}
      <DuplicatesAlert kind="item" matches={duplicates} linkTo={(match) => `/catalogue/items/${match.id}`} onDismiss={() => setDuplicates([])} />
      <Tabs
        items={[
          { value: 'details', label: t('items.tabs.details') },
          { value: 'history', label: t('items.tabs.history') },
        ]}
        value={tab}
        onChange={(next) => setParams(next === 'history' ? { tab: 'history' } : {}, { replace: true })}
      />
      {tab === 'details' ? (
        <div className="flex flex-col gap-5">
          <ItemForm
            key={item.id}
            item={item}
            readOnly={!canEdit}
            onSaved={(response) => {
              setSaved(true)
              setDuplicates(response.meta?.possible_duplicates ?? [])
            }}
          />
          {'images' in item ? <ItemImages item={item} canEdit={canEdit} /> : null}
          {'prices' in item ? <ItemPrices item={item} detailKey={detailKey(item.id)} /> : null}
        </div>
      ) : (
        <Card>
          <HistoryPanel type="item" recordId={item.id} timeZone={timeZone} fields={historyFields} />
        </Card>
      )}
      <ConfirmDialog
        open={archiving}
        title={t('items.archiveTitle', { name: name || item.code })}
        confirmLabel={t('items.archiveConfirm')}
        cancelLabel={t('items.keep')}
        pending={action.isPending}
        error={action.error ? errorMessage(action.error) : null}
        failure={action.error}
        onConfirm={() => action.mutate('archive')}
        onClose={() => {
          setArchiving(false)
          action.reset()
        }}
      >
        {t('items.archiveText')}
      </ConfirmDialog>
    </>
  )
}
