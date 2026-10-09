import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { FormLayoutRenderer } from '@/components/FormLayoutRenderer'
import { Alert, Button, Card, Checkbox, Dialog, Icon, MultiSelect, Select, TextField, VersionBar } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { dragStyle } from '@/lib/dragStyle'
import { useConfigDocument } from '@/lib/useConfigDocument'
import { cn } from '@/lib/utils'
import { canHide, editableLayout, layoutPayload, nextId } from './formLayoutDraft'
import { moveInTree } from './navigationTree'
import { parseScope, useLayoutScopes } from './scopes'

const COLUMNS = [1, 2, 3]

function DragHandle({ attributes, listeners, label, disabled }) {
  return (
    <button
      type="button"
      {...attributes}
      {...listeners}
      disabled={disabled}
      aria-label={label}
      className="flex size-6 shrink-0 cursor-grab touch-none items-center justify-center rounded-sm text-ink-muted hover:text-ink disabled:cursor-default"
    >
      <Icon name="drag" />
    </button>
  )
}

const nameOf = (entry) => entry.label?.trim() || entry.default_label || entry.id

function FieldRow({ entry, onEdit, onHide, disabled }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: `i:${entry.id}`, disabled })
  const name = nameOf(entry)
  return (
    <li
      ref={setNodeRef}
      style={dragStyle(transform, transition)}
      data-layout-field={entry.id}
      className={cn('flex items-center gap-2 rounded-md border border-border bg-surface-200 px-2 py-2', isDragging && 'relative z-10 border-primary')}
    >
      <DragHandle attributes={attributes} listeners={listeners} disabled={disabled} label={t('formLayouts.moveField', { name })} />
      <div className="min-w-0 flex-1">
        <div className="truncate text-body text-ink">{name}</div>
        <div className="truncate text-caption text-ink-muted">
          {[
            entry.source === 'custom' ? t('formLayouts.customField') : t('formLayouts.builtIn'),
            entry.required ? t('formLayouts.required') : null,
            entry.width === 'full' || entry.wide ? t('formLayouts.fullWidth') : null,
            entry.hidden_roles?.length ? t('formLayouts.hiddenForRoles', { count: entry.hidden_roles.length }) : null,
          ]
            .filter(Boolean)
            .join(' · ')}
        </div>
      </div>
      <Button variant="ghost" className="px-2" icon="edit" aria-label={t('formLayouts.editField', { name })} disabled={disabled} onClick={onEdit} />
      <Button
        variant="ghost"
        className="px-2"
        icon="hide"
        aria-label={t('formLayouts.hideField', { name })}
        title={canHide(entry) ? undefined : t('formLayouts.cannotHide')}
        disabled={disabled || !canHide(entry)}
        onClick={onHide}
      />
    </li>
  )
}

function SectionBlock({ section, tabs, onChange, onRemove, onEditField, disabled }) {
  const { t } = useTranslation()
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: `g:${section.id}`, disabled })
  const shown = section.fields.filter((entry) => !entry.hidden)
  const title = section.title?.trim() || t('formLayouts.untitled')
  return (
    <li
      ref={setNodeRef}
      style={dragStyle(transform, transition)}
      data-layout-section={section.id}
      className={cn('flex flex-col gap-3 rounded-lg border border-border p-3', isDragging && 'relative z-10 border-primary bg-surface-100')}
    >
      <div className="flex flex-wrap items-end gap-2">
        <DragHandle attributes={attributes} listeners={listeners} disabled={disabled} label={t('formLayouts.moveSection', { name: title })} />
        <TextField
          label={t('formLayouts.sectionTitle')}
          value={section.title ?? ''}
          maxLength={80}
          disabled={disabled}
          onChange={(event) => onChange({ ...section, title: event.target.value })}
          className="min-w-0 flex-1"
        />
        <Select
          label={t('formLayouts.columns')}
          options={COLUMNS.map((n) => ({ value: String(n), label: t('formLayouts.columnCount', { count: n }) }))}
          value={String(section.columns)}
          disabled={disabled}
          onChange={(event) => onChange({ ...section, columns: Number(event.target.value) })}
          className="w-full sm:w-palette"
        />
        {tabs.length ? (
          <Select
            label={t('formLayouts.tab')}
            options={tabs.map((tab) => ({ value: tab.id, label: tab.title || t('formLayouts.untitled') }))}
            value={section.tab ?? tabs[0].id}
            disabled={disabled}
            onChange={(event) => onChange({ ...section, tab: event.target.value })}
            className="w-full sm:w-palette"
          />
        ) : null}
        <Button
          variant="ghost"
          icon="remove"
          disabled={disabled || section.fields.length > 0}
          title={section.fields.length > 0 ? t('formLayouts.removeSectionHelp') : undefined}
          onClick={onRemove}
          aria-label={t('formLayouts.removeSection', { name: title })}
        />
      </div>
      <SortableContext items={shown.map((entry) => `i:${entry.id}`)} strategy={verticalListSortingStrategy}>
        <ul className="flex flex-col gap-2" aria-label={title}>
          {shown.length === 0 ? <li className="px-2 py-2 text-caption text-ink-muted">{t('formLayouts.emptySection')}</li> : null}
          {shown.map((entry) => (
            <FieldRow
              key={entry.id}
              entry={entry}
              disabled={disabled}
              onEdit={() => onEditField(entry.id)}
              onHide={() => onChange({ ...section, fields: section.fields.map((one) => (one.id === entry.id ? { ...one, hidden: true } : one)) })}
            />
          ))}
        </ul>
      </SortableContext>
    </li>
  )
}

/** One field's settings: label, help, width, the section it is in and the roles it is hidden for. */
function FieldDialog({ entry, sections, sectionId, roles, onClose, onSave }) {
  const { t } = useTranslation()
  const [values, setValues] = useState(() => ({
    label: entry.label ?? '',
    help: entry.help ?? '',
    width: entry.width === 'full',
    hidden_roles: entry.hidden_roles ?? [],
    section: sectionId,
  }))
  const hideable = canHide(entry)
  return (
    <Dialog
      open
      title={entry.default_label ?? entry.id}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" onClick={() => onSave(values)}>
            {t('formLayouts.applyField')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        <TextField label={t('formLayouts.label')} help={t('formLayouts.labelHelp')} placeholder={entry.default_label} value={values.label} maxLength={100} onChange={(event) => setValues({ ...values, label: event.target.value })} />
        <TextField label={t('formLayouts.help')} value={values.help} maxLength={255} onChange={(event) => setValues({ ...values, help: event.target.value })} />
        <Select
          label={t('formLayouts.section')}
          options={sections.map((section) => ({ value: section.id, label: section.title?.trim() || t('formLayouts.untitled') }))}
          value={values.section}
          onChange={(event) => setValues({ ...values, section: event.target.value })}
        />
        <Checkbox label={t('formLayouts.fullWidthLabel')} checked={values.width || Boolean(entry.wide)} disabled={Boolean(entry.wide)} onChange={(event) => setValues({ ...values, width: event.target.checked })} />
        <MultiSelect
          label={t('formLayouts.hiddenRoles')}
          help={hideable ? t('formLayouts.hiddenRolesHelp') : t('formLayouts.cannotHide')}
          options={roles.map((role) => ({ value: role.id, label: role.name }))}
          value={values.hidden_roles}
          disabled={!hideable}
          onChange={(next) => setValues({ ...values, hidden_roles: next })}
        />
      </div>
    </Dialog>
  )
}

/** A stand-in control for the preview: the field's label, help and a disabled input. */
const previewField = (entry) => (props) => <TextField label={props.label ?? entry.default_label} help={props.help} disabled value="" onChange={() => {}} />

/**
 * LAY-03: the form layout designer. For one form (item, party, or a custom
 * form) and the organisation or one role: sections (titled, 1 to 3
 * columns, in tabs when the form has tabs), fields dragged within and
 * between sections (or moved from the field's settings), hidden into the
 * palette or for some roles, relabelled and given help text. Required
 * fields without a default can't be hidden. A preview shows the form as
 * laid out. Hiding is presentation only: field rules still decide (RBAC-05).
 * Versioned (LAY-06).
 */
export default function FormLayoutDesigner() {
  const { t } = useTranslation()
  const scopes = useLayoutScopes()
  const [formKey, setFormKey] = useState('item')
  const [scopeValue, setScopeValue] = useState('tenant:')
  const scope = parseScope(scopeValue)
  const forms = useQuery({ queryKey: ['form-layouts'], queryFn: () => api.get('form-layouts'), staleTime: 60_000 })
  const catalogue = useQuery({ queryKey: ['form-layouts', formKey], queryFn: () => api.get(`form-layouts?form=${encodeURIComponent(formKey)}`), staleTime: 30_000 })
  const roles = useQuery({ queryKey: ['roles', 'options'], queryFn: () => api.get('roles?per_page=200&sort=name'), enabled: scopes.canView, staleTime: 60_000 })
  const config = useConfigDocument('form_layout', formKey, scope)
  const loading = config.isLoading || catalogue.isLoading
  const rights = loading ? { edit: false, publish: false } : scopes.can(scope)
  const roleList = (roles.data?.data ?? []).filter((role) => !role.archived_at)

  // The draft being edited, per form, scope and stored version; edits stay until saved.
  const loadedKey = `${formKey}|${scopeValue}|${config.draft?.version ?? ''}|${config.draft?.revision ?? ''}|${config.published?.version ?? ''}|${loading}`
  const [edit, setEdit] = useState({ key: null, draft: null, dirty: false })
  if (!loading && catalogue.data && edit.key !== loadedKey && !(edit.dirty && edit.key?.startsWith(`${formKey}|${scopeValue}|`))) {
    setEdit({ key: loadedKey, draft: editableLayout(config.payload, catalogue.data.data.fields, catalogue.data.data.defaults), dirty: false })
  }
  const draft = edit.draft ?? { tabs: [], sections: [] }
  const change = (next) => setEdit((current) => ({ ...current, draft: { ...draft, ...next }, dirty: true }))
  const setSection = (next) => change({ sections: draft.sections.map((section) => (section.id === next.id ? next : section)) })
  const [editing, setEditing] = useState(null)
  const editingEntry = editing ? draft.sections.flatMap((section) => section.fields).find((entry) => entry.id === editing) : null
  const hidden = draft.sections.flatMap((section) => section.fields.filter((entry) => entry.hidden).map((entry) => ({ entry, section })))

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }))
  const onDragEnd = ({ active, over }) => {
    const tree = moveInTree(
      draft.sections.map((section) => ({ ...section, items: section.fields })),
      active.id,
      over?.id,
    )
    change({ sections: tree.map(({ items, ...section }) => ({ ...section, fields: items })) })
  }
  const addSection = () =>
    change({ sections: [...draft.sections, { id: nextId('section', draft.sections.map((section) => section.id)), title: t('formLayouts.newSection'), tab: draft.tabs[0]?.id ?? null, columns: 2, fields: [] }] })
  const addTab = () => {
    const id = nextId('tab', draft.tabs.map((tab) => tab.id))
    // The first tab takes every section; later ones start empty.
    change({ tabs: [...draft.tabs, { id, title: t('formLayouts.newTab') }], sections: draft.tabs.length ? draft.sections : draft.sections.map((section) => ({ ...section, tab: id })) })
  }
  const removeTab = (id) => {
    const tabs = draft.tabs.filter((tab) => tab.id !== id)
    change({ tabs, sections: draft.sections.map((section) => (section.tab === id ? { ...section, tab: tabs[0]?.id ?? null } : section)) })
  }
  const applyField = (values) => {
    const moving = { ...editingEntry, label: values.label, help: values.help, width: values.width ? 'full' : null, hidden_roles: values.hidden_roles }
    change({
      sections: draft.sections.map((section) => {
        const without = section.fields.filter((entry) => entry.id !== moving.id)
        if (section.id === values.section) {
          const at = section.fields.findIndex((entry) => entry.id === moving.id)
          return { ...section, fields: at >= 0 ? section.fields.map((entry) => (entry.id === moving.id ? moving : entry)) : [...without, moving] }
        }
        return { ...section, fields: without }
      }),
    })
    setEditing(null)
  }

  const [saving, setSaving] = useState(false)
  const [error, setError] = useState(null)
  const save = async () => {
    setError(null)
    setSaving(true)
    try {
      await config.saveDraft(layoutPayload(draft))
      setEdit((current) => ({ ...current, dirty: false }))
      toast.success(t('layouts.saved'))
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setSaving(false)
    }
  }

  // The preview: the draft as a member of the chosen role sees it (the organisation's: as everyone).
  const previewLayout = {
    tabs: draft.tabs,
    sections: draft.sections.map((section) => ({
      ...section,
      tab: draft.tabs.length ? (section.tab ?? draft.tabs[0].id) : null,
      fields: section.fields.map((entry) => ({ ...entry, hidden: entry.hidden || (scope.type === 'role' && (entry.hidden_roles ?? []).includes(scope.id)) })),
    })),
  }
  const previewFields = Object.fromEntries(draft.sections.flatMap((section) => section.fields).map((entry) => [entry.id, previewField(entry)]))
  const previewCustom = draft.sections
    .flatMap((section) => section.fields)
    .filter((entry) => entry.source === 'custom')
    .map((entry) => ({ key: entry.id.slice('custom.'.length), type: 'text', label: entry.default_label, readonly: true }))

  return (
    <>
      <PageHeader title={t('formLayouts.title')} description={t('formLayouts.description')} />
      <div className="flex flex-col gap-5">
        <div className="flex flex-wrap items-end gap-3">
          <Select
            label={t('formLayouts.form')}
            options={(forms.data?.data ?? [{ key: 'item', label: t('formLayouts.item') }]).map((form) => ({ value: form.key, label: form.label }))}
            value={formKey}
            onChange={(event) => {
              setFormKey(event.target.value)
              setEdit({ key: null, draft: null, dirty: false })
            }}
            className="w-full max-w-field"
          />
          <Select label={t('layouts.scopes.label')} options={scopes.options} value={scopeValue} onChange={(event) => setScopeValue(event.target.value)} className="w-full max-w-field" />
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
        {catalogue.error ? <Alert tone="danger" title={errorMessage(catalogue.error)} /> : null}
        <div className="grid gap-5 lg:grid-cols-3">
          <Card
            title={t('formLayouts.canvas')}
            subtitle={t('formLayouts.canvasHelp')}
            className="lg:col-span-2"
            actions={
              rights.edit ? (
                <div className="flex flex-wrap gap-2">
                  <Button icon="plus" onClick={addSection}>
                    {t('formLayouts.addSection')}
                  </Button>
                  <Button variant="primary" disabled={!edit.dirty} loading={saving} onClick={save}>
                    {t('layouts.saveDraft')}
                  </Button>
                </div>
              ) : null
            }
          >
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
              <SortableContext items={draft.sections.map((section) => `g:${section.id}`)} strategy={verticalListSortingStrategy}>
                <ul className="flex flex-col gap-3" aria-label={t('formLayouts.canvas')}>
                  {draft.sections.map((section) => (
                    <SectionBlock
                      key={section.id}
                      section={section}
                      tabs={draft.tabs}
                      disabled={!rights.edit}
                      onChange={setSection}
                      onRemove={() => change({ sections: draft.sections.filter((one) => one.id !== section.id) })}
                      onEditField={setEditing}
                    />
                  ))}
                </ul>
              </SortableContext>
            </DndContext>
          </Card>
          <div className="flex flex-col gap-5">
            <Card title={t('formLayouts.palette')} subtitle={t('formLayouts.paletteHelp')}>
              {hidden.length === 0 ? (
                <p className="text-caption text-ink-muted">{t('formLayouts.paletteEmpty')}</p>
              ) : (
                <ul className="flex flex-col gap-2" aria-label={t('formLayouts.palette')}>
                  {hidden.map(({ entry, section }) => (
                    <li key={entry.id} className="flex items-center gap-2 rounded-md border border-border px-2 py-2">
                      <span className="min-w-0 flex-1 truncate">{nameOf(entry)}</span>
                      <Button
                        variant="ghost"
                        icon="show"
                        disabled={!rights.edit}
                        onClick={() => setSection({ ...section, fields: section.fields.map((one) => (one.id === entry.id ? { ...one, hidden: false } : one)) })}
                      >
                        {t('formLayouts.place')}
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            <Card
              title={t('formLayouts.tabs')}
              subtitle={t('formLayouts.tabsHelp')}
              actions={
                rights.edit ? (
                  <Button icon="plus" onClick={addTab}>
                    {t('formLayouts.addTab')}
                  </Button>
                ) : null
              }
            >
              {draft.tabs.length === 0 ? (
                <p className="text-caption text-ink-muted">{t('formLayouts.noTabs')}</p>
              ) : (
                <ul className="flex flex-col gap-2">
                  {draft.tabs.map((tab) => (
                    <li key={tab.id} className="flex items-end gap-2">
                      <TextField
                        label={t('formLayouts.tabTitle')}
                        value={tab.title}
                        maxLength={60}
                        disabled={!rights.edit}
                        onChange={(event) => change({ tabs: draft.tabs.map((one) => (one.id === tab.id ? { ...one, title: event.target.value } : one)) })}
                        className="min-w-0 flex-1"
                      />
                      <Button variant="ghost" icon="remove" disabled={!rights.edit} onClick={() => removeTab(tab.id)} aria-label={t('formLayouts.removeTab', { name: tab.title })} />
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        </div>
        <section className="flex flex-col gap-3" aria-label={t('formLayouts.preview')}>
          <h2 className="text-h3 text-ink">{t('formLayouts.preview')}</h2>
          <p className="text-caption text-ink-muted">{scopes.options.find((option) => option.value === scopeValue)?.label}</p>
          <FormLayoutRenderer layout={previewLayout} fields={previewFields} custom={{ entity: formKey, fields: previewCustom, values: { values: {}, set: () => {} }, errors: {} }} readOnly />
        </section>
      </div>
      {editingEntry ? (
        <FieldDialog
          entry={editingEntry}
          sections={draft.sections}
          sectionId={draft.sections.find((section) => section.fields.some((entry) => entry.id === editingEntry.id))?.id}
          roles={roleList}
          onClose={() => setEditing(null)}
          onSave={applyField}
        />
      ) : null}
    </>
  )
}
