import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { removeView, upsertView, viewFromState, viewId } from '@/lib/listViews'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { cn } from '@/lib/utils'
import { Alert } from './Alert'
import { Button } from './Button'
import { Checkbox } from './Checkbox'
import { Dialog } from './Dialog'
import { Select } from './Select'
import { TextField } from './TextField'

const KIND = 'list_view'
const menuClasses = 'w-max rounded-md border border-border p-1 shadow-lg ring-0'
const menuItemClasses = 'gap-2 rounded-md px-2 py-2 text-body text-ink'
const EMPTY = { views: [], default_view: null }

const scopeValue = (scope) => `${scope.type}:${scope.id ?? ''}`

/**
 * LAY-04: the saved views of a list, in ListView's toolbar. The menu opens a
 * view (the user's own, those shared with their roles or with everyone, or
 * the list's own layout) and saves the list as it stands now (columns,
 * filters, sort, rows per page): as a new view, kept personal or shared
 * with a role or everyone by holders of `core.layout.edit` and `publish`
 * for the whole organisation, or over the open view when it is theirs to
 * change. Saving goes through useConfigDocument (draft, then publish), so
 * every change is a version of the list's configuration (LAY-06).
 */
export function ListViews({ list }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { tenantWide } = usePermissions()
  const [dialog, setDialog] = useState(null) // save | delete
  const [name, setName] = useState('')
  const [target, setTarget] = useState('user')
  const [makeDefault, setMakeDefault] = useState(false)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const { all, active, changed, select, refresh } = list.views
  const mine = { type: 'user', id: user?.id ?? null }
  const canShare = tenantWide('core.layout.edit') && tenantWide('core.layout.publish')
  const editable = (view) => Boolean(view) && (view.personal || canShare)

  const roles = useQuery({
    queryKey: ['roles', 'options'],
    queryFn: () => api.get('roles?per_page=200&sort=name'),
    enabled: canShare && dialog === 'save',
    staleTime: 60_000,
  })
  const targets = [
    { value: scopeValue(mine), label: t('ds.listViews.onlyMe') },
    ...(canShare ? [{ value: 'tenant:', label: t('ds.listViews.everyone') }] : []),
    ...(canShare ? (roles.data?.data ?? []).map((role) => ({ value: `role:${role.id}`, label: t('ds.listViews.role', { name: role.name }) })) : []),
  ]
  const [targetType, targetId] = (dialog === 'save' ? target : scopeValue(active?.scope ?? mine)).split(':')
  const scope = { type: targetType || 'user', id: targetId || null }
  // The document the dialog (or the open view) writes to; the hook carries its draft and revision.
  const document = useConfigDocument(KIND, list.id, scope, { enabled: dialog !== null || (editable(active) && changed) })

  const personal = all.filter((view) => view.personal)
  const shared = all.filter((view) => !view.personal)

  const state = () => ({
    columns: list.columns,
    hidden: list.hiddenColumns,
    filters: list.filters,
    filterDefaults: list.filterDefaults,
    sort: list.sort,
    defaultSort: list.defaultSort,
    perPage: list.perPage,
  })

  const run = async (work, done) => {
    setError(null)
    setBusy(true)
    try {
      const key = await work()
      setDialog(null)
      await refresh(key)
      toast.success(done)
    } catch (failure) {
      const message = errorMessage(failure)
      if (dialog === null) toast.error(message)
      else setError(message)
    } finally {
      setBusy(false)
    }
  }

  const write = async (payload) => {
    await document.saveDraft(payload)
    await document.publish()
  }

  const saveNew = () =>
    run(async () => {
      const base = document.payload ?? EMPTY
      const id = viewId(name, (base.views ?? []).map((view) => view.id))
      await write(upsertView(base, viewFromState({ id, name }, state()), { makeDefault }))
      return `${scope.type}.${id}`
    }, t('ds.listViews.saved', { name: name.trim() }))

  const saveChanges = () =>
    run(async () => {
      await write(upsertView(document.payload ?? EMPTY, viewFromState({ id: active.id, name: active.name }, state())))
      return active.key
    }, t('ds.listViews.saved', { name: active.name }))

  const remove = () =>
    run(async () => {
      await write(removeView(document.payload ?? EMPTY, active.id))
      return null
    }, t('ds.listViews.deleted', { name: active.name }))

  const openSave = () => {
    setName('')
    setTarget(scopeValue(mine))
    setMakeDefault(false)
    setError(null)
    setDialog('save')
  }
  const close = () => {
    setDialog(null)
    setError(null)
  }
  const ready = !document.isLoading && !busy

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button icon="views" aria-label={active ? t('ds.listViews.buttonActive', { name: active.name }) : t('ds.listViews.button')}>
            <span className="max-w-field truncate">{active ? active.name : t('ds.listViews.button')}</span>
            {changed ? <span className="text-ink-muted">{t('ds.listViews.changedMark')}</span> : null}
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className={menuClasses}>
          <DropdownMenuRadioGroup value={active?.key ?? ''} onValueChange={(key) => select(key || null)}>
            <DropdownMenuRadioItem value="" className={cn(menuItemClasses, 'pr-10')}>
              {t('ds.listViews.standard')}
            </DropdownMenuRadioItem>
            {personal.length ? <DropdownMenuLabel className="px-2 py-2 text-caption font-normal text-ink-muted">{t('ds.listViews.mine')}</DropdownMenuLabel> : null}
            {personal.map((view) => (
              <DropdownMenuRadioItem key={view.key} value={view.key} className={cn(menuItemClasses, 'pr-10')}>
                {view.name}
              </DropdownMenuRadioItem>
            ))}
            {shared.length ? <DropdownMenuLabel className="px-2 py-2 text-caption font-normal text-ink-muted">{t('ds.listViews.shared')}</DropdownMenuLabel> : null}
            {shared.map((view) => (
              <DropdownMenuRadioItem key={view.key} value={view.key} className={cn(menuItemClasses, 'pr-10')}>
                {view.name}
              </DropdownMenuRadioItem>
            ))}
          </DropdownMenuRadioGroup>
          <DropdownMenuSeparator className="mx-0 my-1" />
          {editable(active) && changed ? (
            <DropdownMenuItem className={menuItemClasses} disabled={!ready} onSelect={saveChanges}>
              {t('ds.listViews.saveChanges', { name: active.name })}
            </DropdownMenuItem>
          ) : null}
          <DropdownMenuItem className={menuItemClasses} onSelect={openSave}>
            {t('ds.listViews.saveAs')}
          </DropdownMenuItem>
          {editable(active) ? (
            <DropdownMenuItem className={cn(menuItemClasses, 'text-danger')} onSelect={() => setDialog('delete')}>
              {t('ds.listViews.delete', { name: active.name })}
            </DropdownMenuItem>
          ) : null}
        </DropdownMenuContent>
      </DropdownMenu>

      <Dialog
        open={dialog === 'save'}
        title={t('ds.listViews.saveTitle')}
        onClose={close}
        footer={
          <>
            <Button variant="ghost" onClick={close}>
              {t('common.cancel')}
            </Button>
            <Button variant="primary" disabled={!name.trim() || !ready} loading={busy} onClick={saveNew}>
              {t('ds.listViews.saveConfirm')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4 pt-1">
          {error ? <Alert tone="danger" title={error} /> : null}
          <p className="text-body text-ink-muted">{t('ds.listViews.saveBody')}</p>
          <TextField label={t('ds.listViews.name')} value={name} maxLength={80} required onChange={(event) => setName(event.target.value)} />
          {targets.length > 1 ? (
            <Select label={t('ds.listViews.visibleTo')} options={targets} value={target} onChange={(event) => setTarget(event.target.value)} />
          ) : null}
          <Checkbox
            label={scope.type === 'user' ? t('ds.listViews.defaultMine') : t('ds.listViews.defaultShared')}
            checked={makeDefault}
            onChange={(event) => setMakeDefault(event.target.checked)}
          />
        </div>
      </Dialog>

      <Dialog
        open={dialog === 'delete'}
        title={t('ds.listViews.deleteTitle', { name: active?.name })}
        onClose={close}
        footer={
          <>
            <Button variant="ghost" onClick={close}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" disabled={!ready} loading={busy} onClick={remove}>
              {t('ds.listViews.deleteConfirm')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          {error ? <Alert tone="danger" title={error} /> : null}
          <p>{active?.personal ? t('ds.listViews.deleteBodyMine') : t('ds.listViews.deleteBodyShared')}</p>
        </div>
      </Dialog>
    </>
  )
}
