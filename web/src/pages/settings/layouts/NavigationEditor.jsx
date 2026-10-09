import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Icon, Select, TextField, VersionBar } from '@/components/ds'
import { applyNavigationLayout, NAV_GROUPS, navigationTree, visibleGroups } from '@/layouts/navigation'
import { PageHeader } from '@/layouts/PageHeader'
import { dragStyle } from '@/lib/dragStyle'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { cn } from '@/lib/utils'
import { moveInTree, treePayload } from './navigationTree'
import { parseScope, useLayoutScopes } from './scopes'

const CATALOGUE_ITEMS = new Map(NAV_GROUPS.flatMap((group) => group.items.map((item) => [item.to, item])))
const CATALOGUE_GROUPS = new Map(NAV_GROUPS.map((group) => [group.id, group]))

function DragHandle({ attributes, listeners, label }) {
  return (
    <button
      type="button"
      {...attributes}
      {...listeners}
      aria-label={label}
      className="flex size-6 shrink-0 cursor-grab touch-none items-center justify-center rounded-sm text-ink-muted hover:text-ink"
    >
      <Icon name="drag" />
    </button>
  )
}

function ItemRow({ item, groups, groupId, onChange, onMove, disabled }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: `i:${item.id}`, disabled })
  const base = CATALOGUE_ITEMS.get(item.id)
  const defaultLabel = base ? base.label(t) : item.id
  return (
    <li
      ref={setNodeRef}
      style={dragStyle(transform, transition)}
      data-nav-item={item.id}
      className={cn('flex flex-wrap items-center gap-2 rounded-md border border-border bg-surface-200 px-2 py-2', isDragging && 'relative z-10 border-primary', item.hidden && 'bg-surface-100')}
    >
      <DragHandle attributes={attributes} listeners={listeners} label={t('layouts.navigation.moveItem', { name: item.label || defaultLabel })} />
      <Icon name={base?.icon} className="text-ink-muted" />
      <TextField
        aria-label={t('layouts.navigation.itemName', { name: defaultLabel })}
        placeholder={defaultLabel}
        value={item.label ?? ''}
        maxLength={60}
        disabled={disabled}
        onChange={(event) => onChange({ ...item, label: event.target.value })}
        className="min-w-0 flex-1"
      />
      <Select
        aria-label={t('layouts.navigation.itemGroup', { name: item.label || defaultLabel })}
        options={groups}
        value={groupId}
        disabled={disabled}
        onChange={(event) => onMove(event.target.value)}
        className="w-full sm:w-palette"
      />
      <Button
        variant="ghost"
        className="px-2"
        icon={item.hidden ? 'hide' : 'show'}
        aria-pressed={Boolean(item.hidden)}
        aria-label={item.hidden ? t('layouts.navigation.showItem', { name: item.label || defaultLabel }) : t('layouts.navigation.hideItem', { name: item.label || defaultLabel })}
        disabled={disabled}
        onClick={() => onChange({ ...item, hidden: !item.hidden })}
      />
    </li>
  )
}

function GroupBlock({ group, groups, onChange, onMoveItem, disabled }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: `g:${group.id}`, disabled })
  const base = CATALOGUE_GROUPS.get(group.id)
  const defaultLabel = base ? base.label(t) : t('layouts.navigation.newGroup')
  return (
    <li ref={setNodeRef} style={dragStyle(transform, transition)} className={cn('flex flex-col gap-2 rounded-lg border border-border p-3', isDragging && 'relative z-10 border-primary bg-surface-100')}>
      <div className="flex items-center gap-2">
        <DragHandle attributes={attributes} listeners={listeners} label={t('layouts.navigation.moveGroup', { name: group.label || defaultLabel })} />
        <TextField
          aria-label={t('layouts.navigation.groupName', { name: defaultLabel })}
          placeholder={defaultLabel}
          value={group.label ?? ''}
          maxLength={60}
          disabled={disabled}
          onChange={(event) => onChange({ ...group, label: event.target.value })}
          className="min-w-0 flex-1"
        />
      </div>
      <SortableContext items={group.items.map((item) => `i:${item.id}`)} strategy={verticalListSortingStrategy}>
        <ul className="flex flex-col gap-2">
          {group.items.length === 0 ? <li className="px-2 py-2 text-caption text-ink-muted">{t('layouts.navigation.emptyGroup')}</li> : null}
          {group.items.map((item) => (
            <ItemRow
              key={item.id}
              item={item}
              groups={groups}
              groupId={group.id}
              disabled={disabled}
              onChange={(next) => onChange({ ...group, items: group.items.map((entry) => (entry.id === item.id ? next : entry)) })}
              onMove={(target) => onMoveItem(item.id, target)}
            />
          ))}
        </ul>
      </SortableContext>
    </li>
  )
}

/** The sidebar as the chosen role sees it: the draft over the catalogue, filtered by the role's permissions. */
function Preview({ payload, permissionNames, hasModule }) {
  const { t } = useTranslation()
  const can = (name) => (permissionNames === null ? true : (Array.isArray(name) ? name : [name]).some((one) => permissionNames.includes(one)))
  const groups = visibleGroups(applyNavigationLayout(NAV_GROUPS, payload), { can, hasModule, tenantWide: can, hasCompany: true })
  return (
    <nav aria-label={t('layouts.navigation.preview')} className="flex flex-col rounded-lg bg-sidebar px-3 py-3">
      {groups.map((group) => (
        <section key={group.id}>
          <h3 className="px-2 pt-3 pb-2 text-caption font-medium text-sidebar-ink uppercase">{group.label(t)}</h3>
          <ul className="flex flex-col gap-px">
            {group.items.map((item) => (
              <li key={item.to} className={cn('flex h-nav items-center gap-2 rounded-md px-2 text-body text-sidebar-ink', payload.home === item.to && 'bg-sidebar-active text-sidebar-ink-active')}>
                <Icon name={item.icon} />
                <span className="min-w-0 flex-1 truncate">{item.label(t)}</span>
                {payload.home === item.to ? <span className="text-caption">{t('layouts.navigation.homeMark')}</span> : null}
              </li>
            ))}
          </ul>
        </section>
      ))}
    </nav>
  )
}

/**
 * LAY-02: the navigation editor. For the organisation (every role without
 * its own) or one role: groups renamed and reordered, items reordered,
 * renamed, hidden or moved between groups (drag and drop, or the keyboard:
 * Space on a handle, then the arrow keys), and the role's home page. A
 * preview shows the sidebar as that role sees it. Hiding never grants:
 * permissions and modules still filter (RBAC-09). Versioned (LAY-06).
 */
export default function NavigationEditor() {
  const { t } = useTranslation()
  const { hasModule } = usePermissions()
  const scopes = useLayoutScopes()
  const [scopeValue, setScopeValue] = useState('tenant:')
  const scope = parseScope(scopeValue)
  const config = useConfigDocument('navigation', 'default', scope)
  const rights = scopes.can(scope)
  const role = useQuery({
    queryKey: ['roles', scope.id],
    queryFn: () => api.get(`roles/${scope.id}`),
    enabled: scope.type === 'role' && Boolean(scope.id),
  })

  // The draft being edited, per scope and stored version; edits stay until saved.
  const loadedKey = `${scopeValue}|${config.draft?.version ?? ''}|${config.published?.version ?? ''}|${config.isLoading}`
  const [edit, setEdit] = useState({ key: null, tree: null, home: null, dirty: false })
  if (!config.isLoading && edit.key !== loadedKey && !(edit.dirty && edit.key?.startsWith(`${scopeValue}|`))) {
    setEdit({ key: loadedKey, tree: navigationTree(NAV_GROUPS, config.payload), home: config.payload?.home ?? null, dirty: false })
  }
  const tree = edit.tree ?? navigationTree(NAV_GROUPS, null)
  const home = edit.home
  const change = (next) => setEdit((current) => ({ ...current, ...next, dirty: true }))
  const payload = treePayload(tree, home)

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )
  const groupOptions = tree.map((group) => ({ value: group.id, label: group.label?.trim() || CATALOGUE_GROUPS.get(group.id)?.label(t) || t('layouts.navigation.newGroup') }))
  const permissionNames = scope.type === 'role' ? (role.data?.data?.permissions ?? []) : null
  const previewGroups = visibleGroups(applyNavigationLayout(NAV_GROUPS, payload), {
    can: (name) => permissionNames === null || (Array.isArray(name) ? name : [name]).some((one) => permissionNames.includes(one)),
    tenantWide: (name) => permissionNames === null || permissionNames.includes(name),
    hasModule,
    hasCompany: true,
  })
  const homeOptions = [
    { value: '', label: t('layouts.navigation.homeDashboard') },
    ...previewGroups.flatMap((group) => group.items.filter((item) => item.to !== '/').map((item) => ({ value: item.to, label: item.label(t) }))),
  ]

  const moveItem = (id, target) => {
    const moving = tree.flatMap((group) => group.items).find((item) => item.id === id)
    change({
      tree: tree.map((group) => {
        const items = group.items.filter((item) => item.id !== id)
        return group.id === target ? { ...group, items: [...items, moving] } : { ...group, items }
      }),
    })
  }
  const addGroup = () => {
    const taken = tree.map((group) => group.id)
    let n = 1
    while (taken.includes(`custom-${n}`)) n += 1
    change({ tree: [...tree, { id: `custom-${n}`, label: t('layouts.navigation.newGroup'), items: [] }] })
  }

  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const save = async () => {
    setError(null)
    setSaving(true)
    try {
      await config.saveDraft(payload)
      setEdit((current) => ({ ...current, dirty: false }))
      toast.success(t('layouts.saved'))
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader title={t('layouts.navigation.title')} description={t('layouts.navigation.description')} />
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-end gap-3">
          <Select label={t('layouts.scopes.label')} options={scopes.options} value={scopeValue} onChange={(event) => setScopeValue(event.target.value)} className="w-full max-w-field" />
          <Select
            label={t('layouts.navigation.home')}
            options={homeOptions}
            value={home ?? ''}
            disabled={!rights.edit}
            onChange={(event) => change({ home: event.target.value || null })}
            className="w-full max-w-field"
          />
        </div>
        <VersionBar
          document={config.document}
          problems={config.problems}
          pending={config.pending}
          canEdit={rights.edit}
          canPublish={rights.publish}
          conflict={config.conflict}
          onReload={config.reload}
          saveState={edit.dirty ? 'unsaved' : undefined}
          onPublish={async () => {
            if (edit.dirty) await save()
            await config.publish()
            toast.success(t('layouts.published'))
          }}
          onDiscard={config.discardDraft}
          onRollback={config.rollback}
        />
        {error ? <Alert tone="danger" title={error} /> : null}
        {config.error ? <Alert tone="danger" title={errorMessage(config.error)} /> : null}
        <div className="grid gap-5 lg:grid-cols-3">
          <Card
            title={t('layouts.navigation.tree')}
            subtitle={t('layouts.navigation.treeHelp')}
            className="lg:col-span-2"
            actions={
              rights.edit ? (
                <div className="flex flex-wrap gap-2">
                  <Button icon="plus" onClick={addGroup}>
                    {t('layouts.navigation.addGroup')}
                  </Button>
                  <Button variant="primary" disabled={!edit.dirty} loading={saving} onClick={save}>
                    {t('layouts.saveDraft')}
                  </Button>
                </div>
              ) : null
            }
          >
            <div>
              <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={({ active, over }) => change({ tree: moveInTree(tree, active.id, over?.id) })}>
                <SortableContext items={tree.map((group) => `g:${group.id}`)} strategy={verticalListSortingStrategy}>
                  <ul className="flex flex-col gap-3" aria-label={t('layouts.navigation.tree')}>
                    {tree.map((group) => (
                      <GroupBlock
                        key={group.id}
                        group={group}
                        groups={groupOptions}
                        disabled={!rights.edit}
                        onChange={(next) => change({ tree: tree.map((entry) => (entry.id === group.id ? next : entry)) })}
                        onMoveItem={moveItem}
                      />
                    ))}
                  </ul>
                </SortableContext>
              </DndContext>
            </div>
          </Card>
          <Card title={t('layouts.navigation.preview')} subtitle={scopes.options.find((option) => option.value === scopeValue)?.label}>
            <div>
              <Preview payload={payload} permissionNames={permissionNames} hasModule={hasModule} />
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}
