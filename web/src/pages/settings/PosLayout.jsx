import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { useQuery } from '@tanstack/react-query'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Icon, Select, Switch, Tabs, TextField, VersionBar } from '@/components/ds'
import { POS_CATEGORY_COLOURS } from '@/components/ds/posTileStyles'
import { PageHeader } from '@/layouts/PageHeader'
import { dragStyle } from '@/lib/dragStyle'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { useDebounced } from '@/lib/useDebounced'
import { cn } from '@/lib/utils'
import { ChoiceGroup } from './brand/fields'
import { ACTIONS, buttonKey, cleanLayout, COLOURS, layoutFrom, MAX_PINNED, MAX_QUICK_BUTTONS, move, ORDERS, TILE_SIZES, WELCOME_MAX } from './posLayout/layout'
import { PosLayoutPreview } from './posLayout/PosLayoutPreview'

const KIND = 'pos_layout'
const TENANT = 'tenant:'

const scopeOf = (value) => {
  const [type, id] = String(value ?? TENANT).split(':')
  return { type: type || 'tenant', id: id || null }
}

function DragHandle({ attributes, listeners, label, disabled }) {
  return (
    <button
      type="button"
      {...attributes}
      {...listeners}
      disabled={disabled}
      aria-label={label}
      className="flex size-6 shrink-0 cursor-grab touch-none items-center justify-center rounded-sm text-ink-muted hover:text-ink disabled:cursor-not-allowed"
    >
      <Icon name="drag" />
    </button>
  )
}

/** A row of a sortable list (dnd-kit, mouse, touch and keyboard). */
function SortableRow({ id, label, disabled, children }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id, disabled })
  return (
    <li
      ref={setNodeRef}
      style={dragStyle(transform, transition)}
      data-row={id}
      className={cn('flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-200 px-2 py-2', isDragging && 'relative z-10 border-primary')}
    >
      <DragHandle attributes={attributes} listeners={listeners} label={label} disabled={disabled} />
      {children}
    </li>
  )
}

function SortableList({ ids, onMove, disabled, children, empty }) {
  const sensors = useSensors(useSensor(PointerSensor), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }))
  const onDragEnd = ({ active, over }) => {
    if (over && active.id !== over.id) onMove(active.id, over.id)
  }
  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
      <SortableContext items={ids} strategy={verticalListSortingStrategy} disabled={disabled}>
        <ul className="flex flex-col gap-2">
          {ids.length === 0 ? <li className="px-2 py-2 text-caption text-ink-muted">{empty}</li> : null}
          {children}
        </ul>
      </SortableContext>
    </DndContext>
  )
}

/** Token colour swatches for a category tile (BR-01: never a typed colour). */
function Swatches({ value, onChange, disabled, name }) {
  const { t } = useTranslation()
  return (
    <div role="radiogroup" aria-label={t('posLayout.categories.colour', { name })} className="flex flex-wrap items-center gap-1">
      {[null, ...COLOURS].map((colour) => {
        const selected = (value ?? null) === colour
        return (
          <button
            key={colour ?? 'none'}
            type="button"
            role="radio"
            aria-checked={selected}
            aria-label={t(`posLayout.colours.${colour ?? 'none'}`)}
            title={t(`posLayout.colours.${colour ?? 'none'}`)}
            disabled={disabled}
            onClick={() => onChange(colour)}
            className={cn(
              'flex size-6 items-center justify-center rounded-sm border',
              colour ? POS_CATEGORY_COLOURS[colour].tile : 'bg-surface-200',
              selected ? 'border-ink' : 'border-border-strong',
              'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus focus-visible:outline-solid disabled:opacity-40',
            )}
          >
            {selected ? <Icon name="check" className="text-ink" /> : null}
          </button>
        )
      })}
    </div>
  )
}

/** An item picker that searches the server (items?search=). */
function ItemPicker({ label, onPick, exclude = [], disabled }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['items', 'pos-layout-picker', search],
    queryFn: () => api.get(`items?per_page=20&sort=name${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    staleTime: 30_000,
  })
  const options = (query.data?.data ?? []).filter((item) => !exclude.includes(item.id)).map((item) => ({ value: item.id, label: `${item.name} · ${item.code}` }))
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label}
      placeholder={t('posLayout.pickItem')}
      options={options}
      value=""
      disabled={disabled}
      onSearchChange={onSearchChange}
      onChange={(event) => {
        const item = (query.data?.data ?? []).find((one) => one.id === event.target.value)
        if (item) onPick(item)
      }}
      className="w-full max-w-field"
    />
  )
}

/** The images a category tile may show: the business's brand images and the photos of the category's items. */
function useImageOptions(categoryId, assets) {
  const items = useQuery({
    queryKey: ['items', 'pos-layout-images', categoryId],
    queryFn: () => api.get(`items?per_page=50&category=${encodeURIComponent(categoryId)}`),
    enabled: Boolean(categoryId),
    staleTime: 60_000,
  })
  return useMemo(
    () => [
      ...assets.filter((asset) => asset.kind !== 'favicon').map((asset) => ({ value: `brand_asset:${asset.id}`, label: asset.label, url: asset.url })),
      ...(items.data?.data ?? []).flatMap((item) => (item.images ?? []).map((image, index) => ({ value: `item_image:${image.id}`, label: `${item.name}${index ? ` (${index + 1})` : ''}`, url: image.url }))),
    ],
    [assets, items.data],
  )
}

function CategoryRow({ category, name, assets, disabled, onChange }) {
  const { t } = useTranslation()
  const options = useImageOptions(category.id, assets)
  const current = category.image ? `${category.image.source}:${category.image.id}` : ''
  return (
    <SortableRow id={category.id} label={t('posLayout.categories.move', { name })} disabled={disabled}>
      <span className={cn('min-w-0 flex-1 truncate text-body', category.hidden ? 'text-ink-muted' : 'text-ink')}>{name}</span>
      <Swatches name={name} value={category.color} disabled={disabled} onChange={(color) => onChange({ ...category, color })} />
      <Select
        aria-label={t('posLayout.categories.image', { name })}
        placeholder={t('posLayout.categories.noImage')}
        options={[{ value: '', label: t('posLayout.categories.noImage') }, ...options.map(({ value, label }) => ({ value, label }))]}
        value={current}
        disabled={disabled}
        onChange={(event) => {
          const [source, id] = event.target.value.split(':')
          onChange({ ...category, image: id ? { source, id } : null })
        }}
        className="w-full sm:w-auto sm:min-w-0"
      />
      <Button
        variant="ghost"
        className="px-2"
        icon={category.hidden ? 'hide' : 'show'}
        aria-pressed={category.hidden}
        aria-label={category.hidden ? t('posLayout.categories.show', { name }) : t('posLayout.categories.hide', { name })}
        disabled={disabled}
        onClick={() => onChange({ ...category, hidden: !category.hidden })}
      />
    </SortableRow>
  )
}

/**
 * The editor of one scope's layout (LAY-05): grid, products, categories,
 * quick buttons, keypad and customer display, beside the tablet and phone
 * preview. Changes are saved to the draft a moment after the last edit;
 * the VersionBar publishes, and the API refuses a layout with problems.
 */
function LayoutEditor({ initial, config, canEdit, canPublish, copyTargets, categories, items, assets, onReset }) {
  const { t } = useTranslation()
  const [layout, setLayout] = useState(initial)
  const [device, setDevice] = useState('tablet')
  const [saveState, setSaveState] = useState(null)
  const [quickType, setQuickType] = useState('action')
  const saved = useRef(JSON.stringify(cleanLayout(initial)))
  const settled = useDebounced(layout, 600)
  const clean = useMemo(() => cleanLayout(layout), [layout])
  const dirty = JSON.stringify(clean) !== saved.current
  const { saveDraft } = config

  useEffect(() => {
    const next = cleanLayout(settled)
    const text = JSON.stringify(next)
    if (!canEdit || text === saved.current) return
    saved.current = text
    setSaveState('saving')
    saveDraft(next, { name: t('posLayout.documentName') }).then(
      () => setSaveState('saved'),
      () => {
        saved.current = ''
        setSaveState('error')
      },
    )
    // saveDraft changes identity every render; the settled layout is what triggers a save.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [settled, canEdit])

  const [known, setKnown] = useState(() => new Map())
  // Names of pinned items and item buttons beyond the preview's first items.
  const named = new Set([...items.map((item) => item.id), ...known.keys()])
  const missing = [...new Set([...layout.products.pinned, ...layout.quick_buttons.filter((b) => b.type === 'item').map((b) => b.id)])].filter((id) => !named.has(id)).sort()
  const extra = useQuery({
    queryKey: ['items', 'pos-layout-names', missing],
    queryFn: async () => (await Promise.all(missing.map((id) => api.get(`items/${id}`).catch(() => null)))).filter(Boolean).map((answer) => answer.data),
    enabled: missing.length > 0,
    staleTime: 60_000,
  })
  const names = useMemo(
    () => new Map([...categories.map((category) => [category.id, category.name]), ...items.map((item) => [item.id, item.name]), ...(extra.data ?? []).map((item) => [item.id, item.name])]),
    [categories, items, extra.data],
  )
  const nameOf = (id) => names.get(id) ?? known.get(id) ?? t('posLayout.unknown')
  const remember = (item) => setKnown((map) => new Map(map).set(item.id, item.name))
  const labelOf = (button) => (button.type === 'action' ? t(`posLayout.actions.${button.action}`) : nameOf(button.id))

  const set = (patch) => setLayout((current) => ({ ...current, ...patch }))
  const setIn = (section, patch) => setLayout((current) => ({ ...current, [section]: { ...current[section], ...patch } }))
  const pinned = layout.products.pinned
  const buttons = layout.quick_buttons
  const addButton = (button) => {
    if (buttons.length >= MAX_QUICK_BUTTONS || buttons.some((existing) => buttonKey(existing) === buttonKey(button))) return
    set({ quick_buttons: [...buttons, button] })
  }
  const columns = (min, max) => Array.from({ length: max - min + 1 }, (_, index) => ({ value: String(min + index), label: String(min + index) }))
  const disabled = !canEdit

  return (
    <>
      <VersionBar
        document={config.document}
        problems={dirty ? [] : config.problems}
        canEdit={canEdit}
        canPublish={canPublish && !dirty && saveState !== 'saving'}
        copyTargets={copyTargets}
        pending={config.pending}
        saveState={dirty ? 'saving' : saveState}
        onPublish={config.publish}
        onDiscard={async () => {
          await config.discardDraft()
          onReset()
        }}
        onRollback={async (version) => {
          await config.rollback(version)
          onReset()
        }}
        onCopy={(target, from) => config.copyTo(target, from)}
        conflict={config.conflict}
        onReload={async () => {
          await config.reload?.()
          onReset()
        }}
      />
      {!canEdit ? <Alert tone="info" title={t('posLayout.readOnly')} /> : null}

      <div className="grid gap-5 xl:grid-cols-2">
        <div className="flex min-w-0 flex-col gap-5">
          <Card title={t('posLayout.grid.title')} subtitle={t('posLayout.grid.text')}>
            <div className="grid gap-4 sm:grid-cols-3">
              <Select label={t('posLayout.grid.tablet')} options={columns(3, 6)} value={String(layout.grid.tablet_columns)} disabled={disabled} onChange={(event) => setIn('grid', { tablet_columns: Number(event.target.value) })} />
              <Select label={t('posLayout.grid.phone')} options={columns(2, 3)} value={String(layout.grid.phone_columns)} disabled={disabled} onChange={(event) => setIn('grid', { phone_columns: Number(event.target.value) })} />
              <Select
                label={t('posLayout.grid.size')}
                options={TILE_SIZES.map((size) => ({ value: size, label: t(`posLayout.sizes.${size}`) }))}
                value={layout.grid.tile_size}
                disabled={disabled}
                onChange={(event) => setIn('grid', { tile_size: event.target.value })}
              />
            </div>
          </Card>

          <Card title={t('posLayout.products.title')} subtitle={t('posLayout.products.text')}>
            <div className="flex flex-col gap-4">
              <Select
                label={t('posLayout.products.order')}
                help={layout.products.order === 'best_sellers' ? t('posLayout.products.bestSellersHelp') : undefined}
                options={ORDERS.map((order) => ({ value: order, label: t(`posLayout.orders.${order}`) }))}
                value={layout.products.order}
                disabled={disabled}
                onChange={(event) => setIn('products', { order: event.target.value })}
                className="max-w-field"
              />
              <h3 className="text-label text-ink">{t('posLayout.products.pinned')}</h3>
              <SortableList ids={pinned} disabled={disabled} empty={t('posLayout.products.noPinned')} onMove={(active, over) => setIn('products', { pinned: move(pinned, (id) => id, active, over) })}>
                {pinned.map((id) => (
                  <SortableRow key={id} id={id} label={t('posLayout.products.move', { name: nameOf(id) })} disabled={disabled}>
                    <span className="min-w-0 flex-1 truncate text-body text-ink">{nameOf(id)}</span>
                    <Button variant="ghost" className="px-2" icon="remove" aria-label={t('posLayout.products.unpin', { name: nameOf(id) })} disabled={disabled} onClick={() => setIn('products', { pinned: pinned.filter((one) => one !== id) })} />
                  </SortableRow>
                ))}
              </SortableList>
              {pinned.length < MAX_PINNED ? (
                <ItemPicker
                  label={t('posLayout.products.pin')}
                  exclude={pinned}
                  disabled={disabled}
                  onPick={(item) => {
                    remember(item)
                    setIn('products', { pinned: [...pinned, item.id] })
                  }}
                />
              ) : null}
            </div>
          </Card>

          <Card title={t('posLayout.categories.title')} subtitle={t('posLayout.categories.text')}>
            <SortableList
              ids={layout.categories.map((category) => category.id)}
              disabled={disabled}
              empty={t('posLayout.categories.none')}
              onMove={(active, over) => set({ categories: move(layout.categories, (category) => category.id, active, over) })}
            >
              {layout.categories.map((category) => (
                <CategoryRow
                  key={category.id}
                  category={category}
                  name={nameOf(category.id)}
                  assets={assets}
                  disabled={disabled}
                  onChange={(next) => set({ categories: layout.categories.map((one) => (one.id === category.id ? next : one)) })}
                />
              ))}
            </SortableList>
          </Card>

          <Card title={t('posLayout.quick.title')} subtitle={t('posLayout.quick.text', { max: MAX_QUICK_BUTTONS })}>
            <div className="flex flex-col gap-4">
              <SortableList ids={buttons.map(buttonKey)} disabled={disabled} empty={t('posLayout.quick.none')} onMove={(active, over) => set({ quick_buttons: move(buttons, buttonKey, active, over) })}>
                {buttons.map((button) => (
                  <SortableRow key={buttonKey(button)} id={buttonKey(button)} label={t('posLayout.quick.move', { name: labelOf(button) })} disabled={disabled}>
                    <span className="min-w-0 flex-1 truncate text-body text-ink">{labelOf(button)}</span>
                    <span className="text-caption text-ink-muted">{t(`posLayout.quick.types.${button.type}`)}</span>
                    <Button variant="ghost" className="px-2" icon="remove" aria-label={t('posLayout.quick.remove', { name: labelOf(button) })} disabled={disabled} onClick={() => set({ quick_buttons: buttons.filter((one) => buttonKey(one) !== buttonKey(button)) })} />
                  </SortableRow>
                ))}
              </SortableList>
              {buttons.length < MAX_QUICK_BUTTONS ? (
                <div className="flex flex-wrap items-end gap-3">
                  <Select
                    label={t('posLayout.quick.type')}
                    options={['action', 'item', 'category'].map((type) => ({ value: type, label: t(`posLayout.quick.types.${type}`) }))}
                    value={quickType}
                    disabled={disabled}
                    onChange={(event) => setQuickType(event.target.value)}
                    className="w-full sm:w-auto"
                  />
                  {quickType === 'action' ? (
                    <Select
                      label={t('posLayout.quick.add')}
                      placeholder={t('posLayout.quick.pickAction')}
                      options={ACTIONS.filter((action) => !buttons.some((b) => b.type === 'action' && b.action === action)).map((action) => ({ value: action, label: t(`posLayout.actions.${action}`) }))}
                      value=""
                      disabled={disabled}
                      onChange={(event) => addButton({ type: 'action', action: event.target.value })}
                      className="w-full max-w-field"
                    />
                  ) : quickType === 'item' ? (
                    <ItemPicker
                      label={t('posLayout.quick.add')}
                      disabled={disabled}
                      exclude={buttons.filter((b) => b.type === 'item').map((b) => b.id)}
                      onPick={(item) => {
                        remember(item)
                        addButton({ type: 'item', id: item.id })
                      }}
                    />
                  ) : (
                    <Select
                      label={t('posLayout.quick.add')}
                      placeholder={t('posLayout.quick.pickCategory')}
                      options={categories.filter((category) => !buttons.some((b) => b.type === 'category' && b.id === category.id)).map((category) => ({ value: category.id, label: category.name }))}
                      value=""
                      disabled={disabled}
                      onChange={(event) => addButton({ type: 'category', id: event.target.value })}
                      className="w-full max-w-field"
                    />
                  )}
                </div>
              ) : null}
            </div>
          </Card>

          <Card title={t('posLayout.keypad.title')} subtitle={t('posLayout.keypad.text')}>
            <ChoiceGroup
              label={t('posLayout.keypad.label')}
              name="keypad"
              value={layout.keypad}
              options={['right', 'left'].map((side) => ({ value: side, label: t(`posLayout.keypad.${side}`) }))}
              onChange={(keypad) => set({ keypad })}
              disabled={disabled}
            />
          </Card>

          <Card title={t('posLayout.display.title')} subtitle={t('posLayout.display.text')}>
            <div className="flex flex-col gap-4">
              <TextField
                label={t('posLayout.display.welcome')}
                help={t('posLayout.display.welcomeHelp', { max: WELCOME_MAX })}
                maxLength={WELCOME_MAX}
                value={layout.customer_display.welcome ?? ''}
                disabled={disabled}
                onChange={(event) => setIn('customer_display', { welcome: event.target.value })}
              />
              {['show_lines', 'show_second_currency', 'show_logo'].map((key) => (
                <Switch key={key} label={t(`posLayout.display.${key}`)} checked={layout.customer_display[key]} disabled={disabled} onChange={(value) => setIn('customer_display', { [key]: value })} />
              ))}
            </div>
          </Card>
        </div>

        <section aria-labelledby="pos-layout-preview" className="flex min-w-0 flex-col gap-3 xl:sticky xl:top-4 xl:self-start">
          <h2 id="pos-layout-preview" className="text-h2 text-ink">
            {t('posLayout.preview.title')}
          </h2>
          <Tabs
            items={[
              { value: 'tablet', label: t('posLayout.preview.tablet') },
              { value: 'phone', label: t('posLayout.preview.phone') },
            ]}
            value={device}
            onChange={setDevice}
          />
          <PosLayoutPreview layout={layout} device={device} items={items} names={names} labelOf={labelOf} />
          <p className="text-caption text-ink-muted">{t('posLayout.preview.text')}</p>
        </section>
      </div>
    </>
  )
}

/**
 * LAY-05: Settings → POS layout. The tills' sell screen for the whole
 * business, or one company, branch or location (a location's wins), as
 * versioned configuration (draft, publish, roll back, copy).
 */
export default function PosLayout() {
  const { t } = useTranslation()
  const { can, canWithin } = usePermissions()
  const [scopeValue, setScopeValue] = useState(TENANT)
  const [resets, setResets] = useState(0)
  const scope = scopeOf(scopeValue)

  const companies = useQuery({ queryKey: ['companies', 'pos-layout'], queryFn: () => api.get('companies?per_page=200') })
  const branches = useQuery({ queryKey: ['branches', 'pos-layout'], queryFn: () => api.get('branches?per_page=200') })
  const locations = useQuery({ queryKey: ['locations', 'pos-layout'], queryFn: () => api.get('locations?per_page=200') })
  const categoriesQuery = useQuery({ queryKey: ['item-categories', 'pos-layout'], queryFn: () => api.get('item-categories?per_page=100&sort=name') })
  const itemsQuery = useQuery({ queryKey: ['items', 'pos-layout-preview'], queryFn: () => api.get('items?per_page=24&sort=name') })
  const assetsQuery = useQuery({ queryKey: ['branding', 'assets'], queryFn: () => api.get('branding/assets'), retry: false })

  const branchList = useMemo(() => branches.data?.data ?? [], [branches.data])
  const locationList = useMemo(() => locations.data?.data ?? [], [locations.data])
  const places = useMemo(
    () => [
      ...(companies.data?.data ?? []).map((company) => ({ type: 'company', id: company.id, label: t('posLayout.scope.company', { name: company.name }) })),
      ...branchList.map((branch) => ({ type: 'branch', id: branch.id, label: t('posLayout.scope.branch', { name: branch.name, company: branch.company?.name ?? '' }) })),
      ...locationList.map((location) => ({ type: 'location', id: location.id, label: t('posLayout.scope.location', { name: location.name, branch: location.branch?.name ?? '' }) })),
    ],
    [companies.data, branchList, locationList, t],
  )
  const options = [{ value: TENANT, label: t('posLayout.scope.tenant') }, ...places.map((place) => ({ value: `${place.type}:${place.id}`, label: place.label }))]

  // RBAC-04: a place's layout is edited with the permission there or above it; the API checks again.
  const chain = useMemo(() => {
    if (scope.type === 'tenant') return null
    const branchOf = (id) => branchList.find((branch) => branch.id === id)
    if (scope.type === 'company') return [scope]
    if (scope.type === 'branch') return [scope, { type: 'company', id: branchOf(scope.id)?.company_id }]
    const location = locationList.find((one) => one.id === scope.id)
    const branch = branchOf(location?.branch_id)
    return [scope, { type: 'branch', id: location?.branch_id }, { type: 'company', id: branch?.company_id }]
  }, [scope, branchList, locationList])
  const allowed = (name) => (chain === null ? can(name, { type: 'tenant' }) : canWithin(name, chain))

  const config = useConfigDocument(KIND, 'default', scope)
  const categories = useMemo(() => categoriesQuery.data?.data ?? [], [categoriesQuery.data])
  const items = useMemo(() => itemsQuery.data?.data ?? [], [itemsQuery.data])
  const assets = useMemo(() => (assetsQuery.data?.data ?? []).filter((asset) => asset.kind !== 'favicon').map((asset, index) => ({ ...asset, label: t(`posLayout.assets.${asset.kind}`, { number: index + 1 }) })), [assetsQuery.data, t])
  const loading = config.isLoading || categoriesQuery.isLoading

  return (
    <>
      <PageHeader title={t('posLayout.title')} description={t('posLayout.description')} />
      <Select
        label={t('posLayout.scope.label')}
        help={scope.type === 'tenant' ? t('posLayout.scope.tenantHelp') : t('posLayout.scope.overrideHelp')}
        options={options}
        value={scopeValue}
        onChange={(event) => setScopeValue(event.target.value)}
        className="max-w-field"
      />
      {config.error ? <Alert tone="danger" title={errorMessage(config.error)} /> : null}
      {loading ? (
        <p className="text-ink-muted">{t('common.loading')}</p>
      ) : config.error ? null : (
        <LayoutEditor
          key={`${scopeValue}:${resets}`}
          initial={layoutFrom(config.payload, categories)}
          config={config}
          canEdit={allowed('pos.layout.edit')}
          canPublish={allowed('pos.layout.publish')}
          copyTargets={places.filter((place) => !(place.type === scope.type && place.id === scope.id))}
          categories={categories}
          items={items}
          assets={assets}
          onReset={() => setResets((count) => count + 1)}
        />
      )}
    </>
  )
}
