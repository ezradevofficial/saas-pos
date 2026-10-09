import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { DashboardGrid } from '@/components/dashboard/DashboardGrid'
import { Widget } from '@/components/dashboard/widgets'
import { Alert, Button, Card, Checkbox, Select, TextField, VersionBar } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useNavigation } from '@/layouts/useNavigation'
import { DASHBOARD_KEY, DEFAULT_DASHBOARD, newWidgetId, useDashboard, WIDGET_TYPES, widgetTitle } from '@/lib/dashboardData'
import { nextFreeRow, placeWidget } from '@/lib/dashboardGrid'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { parseScope, useLayoutScopes } from './scopes'

const DAYS = [7, 14, 30]
const LIMITS = [3, 5, 10]

/** The parameters a widget keeps when its type or source changes: only those the new source reads. */
function paramsFor(source, params = {}) {
  const keep = source?.params ?? []
  return Object.fromEntries(Object.entries(params).filter(([name]) => keep.includes(name)))
}

function Inspector({ widget, sources, onChange, onRemove, disabled }) {
  const { t } = useTranslation()
  const { groups } = useNavigation()
  if (!widget) return <p className="text-body text-ink-muted">{t('layouts.dashboard.pickWidget')}</p>
  const feeding = sources.filter((source) => source.widgets.includes(widget.type))
  const source = sources.find((entry) => entry.key === widget.source)
  const params = widget.params ?? {}
  const setParam = (name, value) => onChange({ ...widget, params: { ...params, [name]: value } })
  const links = params.links ?? []
  const pages = groups.flatMap((group) => group.items).filter((item) => item.to !== '/')

  return (
    <div className="flex flex-col gap-4">
      <TextField
        label={t('layouts.dashboard.widgetTitle')}
        placeholder={widgetTitle({ ...widget, title: null }, t)}
        value={widget.title ?? ''}
        maxLength={80}
        disabled={disabled}
        onChange={(event) => onChange({ ...widget, title: event.target.value || null })}
      />
      <Select
        label={t('layouts.dashboard.widgetType')}
        options={WIDGET_TYPES.map((type) => ({ value: type, label: t(`layouts.widgets.types.${type}`) }))}
        value={widget.type}
        disabled={disabled}
        onChange={(event) => {
          const type = event.target.value
          const next = sources.find((entry) => entry.key === widget.source && entry.widgets.includes(type)) ?? sources.find((entry) => entry.widgets.includes(type))
          onChange({ ...widget, type, source: next?.key ?? widget.source, params: paramsFor(next, params), chart: type === 'chart' ? (widget.chart ?? 'bar') : null })
        }}
      />
      <Select
        label={t('layouts.dashboard.source')}
        options={feeding.map((entry) => ({ value: entry.key, label: entry.label }))}
        placeholder={feeding.length ? undefined : t('layouts.dashboard.noSource')}
        value={source && feeding.includes(source) ? widget.source : ''}
        disabled={disabled || feeding.length === 0}
        onChange={(event) => {
          const next = sources.find((entry) => entry.key === event.target.value)
          onChange({ ...widget, source: event.target.value, params: paramsFor(next, params) })
        }}
      />
      {widget.type === 'chart' ? (
        <Select
          label={t('layouts.dashboard.chartType')}
          options={[
            { value: 'bar', label: t('layouts.dashboard.bar') },
            { value: 'line', label: t('layouts.dashboard.line') },
          ]}
          value={widget.chart ?? 'bar'}
          disabled={disabled}
          onChange={(event) => onChange({ ...widget, chart: event.target.value })}
        />
      ) : null}
      {source?.params.includes('days') ? (
        <Select
          label={t('layouts.dashboard.days')}
          options={DAYS.map((days) => ({ value: String(days), label: t('layouts.dashboard.lastDays', { count: days }) }))}
          value={String(params.days ?? 7)}
          disabled={disabled}
          onChange={(event) => setParam('days', Number(event.target.value))}
        />
      ) : null}
      {source?.params.includes('limit') ? (
        <Select
          label={t('layouts.dashboard.rows')}
          options={LIMITS.map((limit) => ({ value: String(limit), label: String(limit) }))}
          value={String(params.limit ?? 5)}
          disabled={disabled}
          onChange={(event) => setParam('limit', Number(event.target.value))}
        />
      ) : null}
      {source?.params.includes('links') ? (
        <fieldset className="flex flex-col gap-2">
          <legend className="pb-2 text-label text-ink">{t('layouts.dashboard.links')}</legend>
          {pages.map((item) => (
            <Checkbox
              key={item.to}
              label={item.label(t)}
              checked={links.includes(item.to)}
              disabled={disabled || (!links.includes(item.to) && links.length >= 12)}
              onChange={(event) => setParam('links', event.target.checked ? [...links, item.to] : links.filter((link) => link !== item.to))}
            />
          ))}
        </fieldset>
      ) : null}
      <p className="text-caption text-ink-muted">{t('layouts.grid.position', { column: widget.x + 1, row: widget.y + 1, width: widget.w, height: widget.h })}</p>
      {disabled ? null : (
        <Button variant="danger" icon="remove" onClick={onRemove}>
          {t('layouts.dashboard.removeWidget')}
        </Button>
      )}
    </div>
  )
}

/**
 * LAY-01: the dashboard designer. For the organisation, a role, or (with
 * `personal`, "Customise my dashboard") the user's own copy, which starts
 * as a copy of the dashboard they see now. Widgets sit on a 12-column grid
 * (drag to move, the corner to resize, or the arrow keys), each bound to a
 * data source the user may read, set in the inspector. Versioned (LAY-06).
 */
export default function DashboardDesigner({ personal = false }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const scopes = useLayoutScopes({ personal })
  const [scopeValue, setScopeValue] = useState(null)
  const chosen = scopeValue ?? (personal ? scopes.mine : 'tenant:')
  const scope = parseScope(chosen)
  const config = useConfigDocument('dashboard', 'default', scope)
  const current = useDashboard()
  const rights = scopes.can(scope)
  const sources = useQuery({ queryKey: ['dashboard', 'sources'], queryFn: () => api.get('dashboard/sources'), staleTime: 60_000 })
  const sourceList = sources.data?.data ?? []

  // The draft being edited: the stored one, else (a personal copy) what the user sees now, else the default.
  const start = config.payload ?? (scope.type === 'user' ? current.dashboard : null) ?? DEFAULT_DASHBOARD
  const loadedKey = `${chosen}|${config.draft?.version ?? ''}|${config.published?.version ?? ''}|${config.isLoading || current.isLoading}`
  const [edit, setEdit] = useState({ key: null, widgets: [], title: null, dirty: false })
  const loading = config.isLoading || current.isLoading
  if (!loading && edit.key !== loadedKey && !(edit.dirty && edit.key?.startsWith(`${chosen}|`))) {
    setEdit({ key: loadedKey, widgets: (start.widgets ?? []).filter((widget) => WIDGET_TYPES.includes(widget.type)), title: start.title ?? null, dirty: false })
  }
  const [selectedId, setSelectedId] = useState(null)
  const change = (next) => setEdit((state) => ({ ...state, ...next, dirty: true }))
  const widgets = edit.widgets
  const selected = widgets.find((widget) => widget.id === selectedId) ?? null
  const payload = { title: edit.title?.trim() || null, widgets }

  const addWidget = () => {
    const source = sourceList.find((entry) => entry.widgets.includes('kpi')) ?? sourceList[0]
    if (!source) return
    const type = source.widgets[0]
    const id = newWidgetId(widgets)
    change({ widgets: [...widgets, { id, type, source: source.key, params: {}, title: null, chart: type === 'chart' ? 'bar' : null, x: 0, y: Math.min(nextFreeRow(widgets), 46), w: 4, h: 2 }] })
    setSelectedId(id)
  }

  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const save = async () => {
    setError(null)
    setSaving(true)
    try {
      await config.saveDraft(payload)
      setEdit((state) => ({ ...state, dirty: false }))
      toast.success(t('layouts.saved'))
    } catch (failure) {
      setError(errorMessage(failure))
      throw failure
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader
        title={personal ? t('layouts.dashboard.customiseTitle') : t('layouts.dashboard.title')}
        description={personal ? t('layouts.dashboard.customiseDescription') : t('layouts.dashboard.description')}
      />
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-end gap-3">
          {scopes.options.length > 1 ? (
            <Select label={t('layouts.scopes.label')} options={scopes.options} value={chosen} onChange={(event) => setScopeValue(event.target.value)} className="w-full max-w-field" />
          ) : null}
          <TextField
            label={t('layouts.dashboard.dashboardTitle')}
            placeholder={t('layouts.dashboard.titlePlaceholder')}
            value={edit.title ?? ''}
            maxLength={80}
            disabled={!rights.edit}
            onChange={(event) => change({ title: event.target.value })}
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
            await queryClient.invalidateQueries({ queryKey: DASHBOARD_KEY })
            toast.success(t('layouts.published'))
          }}
          onDiscard={config.discardDraft}
          onRollback={config.rollback}
        />
        {error ? <Alert tone="danger" title={error} /> : null}
        {config.problems.length ? (
          <Alert tone="warning" title={t('layouts.problems')}>
            <ul className="list-disc pl-5">
              {config.problems.map((problem) => (
                <li key={`${problem.path}-${problem.code}`}>{problem.message}</li>
              ))}
            </ul>
          </Alert>
        ) : null}
        <div className="flex flex-col gap-5 lg:flex-row lg:items-start">
          <div className="flex min-w-0 flex-1 flex-col gap-3">
            {rights.edit ? (
              <div className="flex flex-wrap gap-2">
                <Button icon="plus" disabled={!sourceList.length || widgets.length >= 24} onClick={addWidget}>
                  {t('layouts.dashboard.addWidget')}
                </Button>
                <Button variant="primary" disabled={!edit.dirty} loading={saving} onClick={() => save().catch(() => {})}>
                  {t('layouts.saveDraft')}
                </Button>
              </div>
            ) : null}
            {widgets.length === 0 ? <p className="text-body text-ink-muted">{t('layouts.dashboard.empty')}</p> : null}
            <DashboardGrid
              editing={rights.edit}
              label={t('layouts.dashboard.label')}
              widgets={widgets.map((widget) => ({ ...widget, label: widgetTitle(widget, t) }))}
              selectedId={selectedId}
              onSelect={setSelectedId}
              onChange={(next) => change({ widgets: next.map(({ label: _label, ...widget }) => widget) })}
              renderWidget={(widget) => <Widget widget={widget} framed={!rights.edit} />}
            />
          </div>
          <Card title={t('layouts.dashboard.inspector')} className="w-full lg:w-inspector lg:shrink-0">
            <div className="p-5">
              <Inspector
                widget={selected}
                sources={sourceList}
                disabled={!rights.edit}
                onChange={(next) => change({ widgets: placeWidget(widgets.map((widget) => (widget.id === next.id ? next : widget)), next.id, {}) })}
                onRemove={() => {
                  change({ widgets: widgets.filter((widget) => widget.id !== selectedId) })
                  setSelectedId(null)
                }}
              />
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}
