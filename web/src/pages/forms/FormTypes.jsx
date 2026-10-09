import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Dialog, MultiSelect, StatusBadge, Switch, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { slugify, useCustomFieldSchema } from '@/lib/customFields'
import { typesKey, useCustomFormTypes } from '@/lib/customForms'
import { useRoles } from '@/pages/settings/users/assignments'

const blank = { key: '', name: '', description: '', workflow: true, has_lines: false, attachments: false, line_fields: [], role_ids: [] }

/** CF-04: a form type's settings; the key is set once, from the name unless typed. */
function TypeDialog({ type, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const creating = !type
  const { roles } = useRoles()
  const lineSchema = useCustomFieldSchema(type?.line_entity, { enabled: Boolean(type?.has_lines) })
  const [values, setValues] = useState(() => (type ? { ...blank, ...type, description: type.description ?? '', role_ids: type.role_ids ?? [] } : blank))
  const [keyTouched, setKeyTouched] = useState(false)
  const set = (patch) => setValues((current) => ({ ...current, ...patch }))
  const save = useMutation({
    mutationFn: () => {
      const body = {
        name: values.name.trim(),
        description: values.description.trim() || null,
        workflow: values.workflow,
        has_lines: values.has_lines,
        attachments: values.attachments,
        line_fields: values.line_fields,
        role_ids: values.role_ids,
      }
      return creating ? api.post('custom-form-types', { ...body, key: values.key }) : api.patch(`custom-form-types/${type.id}`, body)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: typesKey })
      await queryClient.invalidateQueries({ queryKey: ['custom-fields'] })
      onClose()
    },
  })
  const errors = formErrors(save.error, ['key', 'name', 'description', 'workflow', 'has_lines', 'attachments', 'line_fields', 'role_ids'])

  return (
    <Dialog
      open
      size="lg"
      title={creating ? t('customForms.types.newTitle') : t('customForms.types.editTitle', { name: type.name })}
      onClose={onClose}
      footer={
        <>
          <Button onClick={onClose}>{t('common.cancel')}</Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {creating ? t('customForms.types.create') : t('common.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <TextField
          label={t('customForms.types.name')}
          help={t('customForms.types.nameHelp')}
          value={values.name}
          maxLength={100}
          required
          error={errors.fields.name}
          onChange={(event) => set({ name: event.target.value, ...(creating && !keyTouched ? { key: slugify(event.target.value, 30) } : {}) })}
        />
        <TextField
          label={t('customForms.types.key')}
          help={creating ? t('customForms.types.keyHelp') : t('customForms.types.keyFixed')}
          value={values.key}
          maxLength={30}
          disabled={!creating}
          required
          error={errors.fields.key}
          onChange={(event) => {
            setKeyTouched(true)
            set({ key: event.target.value })
          }}
        />
        <TextField label={t('customForms.types.descriptionLabel')} value={values.description} maxLength={255} error={errors.fields.description} onChange={(event) => set({ description: event.target.value })} />
        <Switch label={t('customForms.types.workflow')} checked={values.workflow} onChange={(checked) => set({ workflow: checked })} />
        {errors.fields.workflow ? <p className="text-caption text-danger">{errors.fields.workflow}</p> : null}
        <Switch label={t('customForms.types.hasLines')} checked={values.has_lines} onChange={(checked) => set({ has_lines: checked })} />
        <Switch label={t('customForms.types.attachments')} checked={values.attachments} onChange={(checked) => set({ attachments: checked })} />
        {!creating && values.has_lines && lineSchema.fields.length ? (
          <MultiSelect
            label={t('customForms.types.lineColumns')}
            help={t('customForms.types.lineColumnsHelp')}
            options={lineSchema.fields.map((field) => ({ value: field.key, label: field.label }))}
            value={values.line_fields}
            error={errors.fields.line_fields}
            onChange={(next) => set({ line_fields: next })}
          />
        ) : null}
        <MultiSelect
          label={t('customForms.types.roles')}
          help={t('customForms.types.rolesHelp')}
          options={roles.filter((role) => !role.archived_at).map((role) => ({ value: role.id, label: role.name }))}
          value={values.role_ids}
          error={errors.fields.role_ids}
          onChange={(next) => set({ role_ids: next })}
        />
      </div>
    </Dialog>
  )
}

/** One form type: what it has and where its fields, layout, numbers and flow are set. */
function TypeCard({ type, onEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const archive = useMutation({
    mutationFn: () => api.post(`custom-form-types/${type.id}/${type.archived_at ? 'restore' : 'archive'}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: typesKey }),
  })
  const features = [
    type.workflow ? t('customForms.types.withWorkflow') : t('customForms.types.withoutWorkflow'),
    type.has_lines ? t('customForms.types.withLines') : null,
    type.attachments ? t('customForms.types.withAttachments') : null,
  ].filter(Boolean)
  const link = 'w-fit text-label text-primary hover:text-primary-hover'
  return (
    <Card
      title={type.name}
      subtitle={features.join(' · ')}
      actions={
        <>
          {type.archived_at ? <StatusBadge>{t('customForms.types.archived')}</StatusBadge> : null}
          <Button icon="edit" onClick={onEdit}>
            {t('customForms.types.edit')}
          </Button>
          <Button variant="ghost" icon={type.archived_at ? 'restore' : 'archive'} loading={archive.isPending} onClick={() => archive.mutate()}>
            {type.archived_at ? t('customForms.types.restore') : t('customForms.types.archive')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {archive.isError ? <Alert tone="danger" title={errorMessage(archive.error)} /> : null}
        {type.description ? <p className="text-ink-muted">{type.description}</p> : null}
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          <Link className={link} to={`/settings/custom-fields?entity=${encodeURIComponent(type.entity)}`}>
            {t('customForms.types.headerFields')}
          </Link>
          {type.has_lines ? (
            <Link className={link} to={`/settings/custom-fields?entity=${encodeURIComponent(type.line_entity)}`}>
              {t('customForms.types.lineFields')}
            </Link>
          ) : null}
          <Link className={link} to="/settings/layouts/forms">
            {t('customForms.types.layout')}
          </Link>
          <Link className={link} to="/settings/numbering">
            {t('customForms.types.numbering')}
          </Link>
          {type.workflow ? (
            <Link className={link} to="/settings/workflows">
              {t('customForms.types.flow')}
            </Link>
          ) : null}
          {type.archived_at ? null : (
            <Link className={link} to={`/forms/${type.key}`}>
              {t('customForms.types.open')}
            </Link>
          )}
        </div>
      </div>
    </Card>
  )
}

/**
 * CF-04: Settings → Forms. The organisation's own forms ("Petty cash
 * request"): name, numbering, workflow, a line table, attachments and the
 * roles that use each; fields are custom fields of the form (and of its
 * lines), edited in Custom fields. Archived, never deleted.
 */
export default function FormTypes() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('active')
  const { types, isPending, error } = useCustomFormTypes({ status })
  const [dialog, setDialog] = useState(null)

  return (
    <>
      <PageHeader
        title={t('customForms.types.title')}
        description={t('customForms.types.description')}
        actions={
          <Button variant="primary" icon="plus" onClick={() => setDialog({ type: null })}>
            {t('customForms.types.new')}
          </Button>
        }
      />
      <div className="flex flex-col gap-5">
        <Tabs
          items={['active', 'archived', 'all'].map((value) => ({ value, label: t(`customForms.types.statuses.${value}`) }))}
          value={status}
          onChange={setStatus}
        />
        {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
        {!isPending && types.length === 0 ? <p className="text-ink-muted">{t('customForms.types.empty')}</p> : null}
        {types.map((type) => (
          <TypeCard key={type.id} type={type} onEdit={() => setDialog({ type })} />
        ))}
      </div>
      {dialog ? <TypeDialog key={dialog.type?.id ?? 'new'} type={dialog.type} onClose={() => setDialog(null)} /> : null}
    </>
  )
}
