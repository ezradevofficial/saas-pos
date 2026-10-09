import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { HistoryDialog } from '@/components/HistoryDialog'
import { Alert, Button, ListView, StatusBadge, Tabs } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { CUSTOM_FIELD_TYPES, useCustomFieldMeta } from '@/lib/customFields'
import { formatDateTime } from '@/lib/dates'
import { actionsColumn } from '@/lib/listColumns'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog } from './ConfirmDialog'
import { CustomFieldDialog } from './customFields/CustomFieldDialog'
import { useRoles } from './users/assignments'

const MANAGE = 'core.custom_field.manage'
const FALLBACK_ENTITIES = ['item', 'party']
const STATUSES = ['active', 'archived', 'all']

/**
 * CF-01, CF-03, RBAC-05: the custom fields of each entity (items,
 * customers and suppliers), in their order on forms. Search, filters (type,
 * status), sort, columns and export as on every list (EXP-01, LAY-04).
 * Adding, editing, archiving and restoring need core.custom_field.manage;
 * fields are archived, never deleted.
 */
export default function CustomFields() {
  const { t } = useTranslation()
  const locale = useLocale()
  const timeZone = useTimeZone()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const canManage = can(MANAGE)
  const meta = useCustomFieldMeta()
  const { roles } = useRoles()
  const [dialog, setDialog] = useState(null) // { record? }
  const [archiving, setArchiving] = useState(null)
  const [history, setHistory] = useState(null)

  const entities = meta.entities.length ? meta.entities : FALLBACK_ENTITIES.map((key) => ({ key, label: t(`customFields.entities.${key}`) }))
  const entityLabel = (key) => entities.find((entry) => entry.key === key)?.label ?? key
  const typeLabel = (type) => t(`customFields.types.${type}`, { defaultValue: type })
  const yesNo = (value) => (value ? t('customFields.yes') : t('customFields.no'))
  const types = meta.types.length ? meta.types : CUSTOM_FIELD_TYPES

  const archive = useMutation({
    mutationFn: (record) => api.post(`custom-fields/${record.id}/archive`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['custom-fields'] })
      setArchiving(null)
    },
  })
  const restore = useMutation({
    mutationFn: (record) => api.post(`custom-fields/${record.id}/restore`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['custom-fields'] }),
  })

  const columns = [
    { key: 'position', label: t('customFields.columns.position'), sortKey: 'position', exportKey: null, align: 'end', render: (field) => <span className="tabular-nums text-ink-muted">{field.position}</span> },
    { key: 'label', label: t('customFields.columns.label'), sortKey: 'label', hideable: false, render: (field) => <span className="font-medium text-ink">{field.label}</span> },
    { key: 'key', label: t('customFields.columns.key'), sortKey: 'key', render: (field) => <span className="font-mono text-caption text-ink-muted">{field.key}</span> },
    { key: 'type', label: t('customFields.columns.type'), sortKey: 'type', render: (field) => typeLabel(field.type) },
    { key: 'required', label: t('customFields.columns.required'), render: (field) => yesNo(field.required) },
    { key: 'show_on_pos', label: t('customFields.columns.showOnPos'), defaultHidden: true, render: (field) => yesNo(field.show_on_pos) },
    {
      key: 'status',
      label: t('customFields.columns.status'),
      render: (field) => <StatusBadge tone={field.archived_at ? 'neutral' : 'success'}>{field.archived_at ? t('customFields.status.archivedOne') : t('customFields.status.activeOne')}</StatusBadge>,
    },
    { key: 'created_at', label: t('customFields.columns.createdAt'), sortKey: 'created_at', defaultHidden: true, render: (field) => formatDateTime(field.created_at, locale, timeZone) ?? '' },
    { key: 'updated_at', label: t('customFields.columns.updatedAt'), sortKey: 'updated_at', defaultHidden: true, render: (field) => formatDateTime(field.updated_at, locale, timeZone) ?? '' },
    actionsColumn(t('customFields.columns.actions'), (field) => (
      <span className="flex flex-wrap justify-end gap-1">
        {canManage && !field.archived_at ? (
          <Button variant="ghost" icon="edit" onClick={() => setDialog({ record: field })} aria-label={t('customFields.editName', { label: field.label })}>
            {t('customFields.edit')}
          </Button>
        ) : null}
        <Button variant="ghost" icon="history" onClick={() => setHistory(field)} aria-label={t('history.openFor', { name: field.label })}>
          {t('history.open')}
        </Button>
        {canManage ? (
          field.archived_at ? (
            <Button
              variant="ghost"
              icon="restore"
              loading={restore.isPending && restore.variables?.id === field.id}
              onClick={() => restore.mutate(field)}
              aria-label={t('customFields.restoreName', { label: field.label })}
            >
              {t('customFields.restore')}
            </Button>
          ) : (
            <Button variant="ghost" icon="archive" onClick={() => setArchiving(field)} aria-label={t('customFields.archiveName', { label: field.label })}>
              {t('customFields.archive')}
            </Button>
          )
        ) : null}
      </span>
    )),
  ]

  const list = useServerList({
    id: 'custom-fields',
    endpoint: 'custom-fields',
    queryKey: ['custom-fields'],
    filters: { entity: FALLBACK_ENTITIES[0], status: 'active', type: '' },
    defaultSort: 'position',
    columns,
  })
  const { entity, status, type } = list.filters
  const filtered = Boolean(list.term || type)

  return (
    <>
      <PageHeader
        title={t('settings.customFields.title')}
        description={t('settings.customFields.description')}
        actions={
          canManage ? (
            <Button variant="primary" icon="plus" onClick={() => setDialog({})}>
              {t('customFields.add')}
            </Button>
          ) : null
        }
      />
      <Tabs items={entities.map((entry) => ({ value: entry.key, label: entry.label }))} value={entity} onChange={(next) => list.setFilter('entity', next)} />
      {restore.isError ? <Alert tone="danger" title={errorMessage(restore.error)} /> : null}
      <ListView
        list={list}
        title={t('customFields.listTitle', { entity: entityLabel(entity) })}
        searchPlaceholder={t('customFields.searchPlaceholder')}
        filterFields={[
          {
            name: 'type',
            label: t('customFields.filters.type'),
            options: [{ value: '', label: t('customFields.filters.allTypes') }, ...types.map((value) => ({ value, label: typeLabel(value) }))],
          },
          {
            name: 'status',
            label: t('customFields.filters.status'),
            options: STATUSES.map((value) => ({ value, label: t(`customFields.status.${value}`) })),
          },
        ]}
        emptyText={filtered ? t('customFields.emptyFiltered') : t(`customFields.empty.${status}`, { defaultValue: t('customFields.empty.active') })}
      />
      {dialog ? (
        <CustomFieldDialog
          key={dialog.record?.id ?? `new-${entity}`}
          entity={dialog.record?.entity ?? entity}
          entityLabel={entityLabel(dialog.record?.entity ?? entity)}
          record={dialog.record ?? null}
          meta={{ ...meta, types }}
          roles={roles}
          onClose={() => setDialog(null)}
        />
      ) : null}
      <HistoryDialog record={history} type="custom_field" name={history?.label ?? ''} timeZone={timeZone} onClose={() => setHistory(null)} />
      <ConfirmDialog
        open={Boolean(archiving)}
        title={archiving ? t('customFields.archiveTitle', { label: archiving.label }) : ''}
        confirmLabel={t('customFields.archiveConfirm')}
        cancelLabel={t('customFields.keep')}
        pending={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        failure={archive.error}
        onConfirm={() => archive.mutate(archiving)}
        onClose={() => {
          setArchiving(null)
          archive.reset()
        }}
      >
        {t('customFields.archiveText')}
      </ConfirmDialog>
    </>
  )
}
