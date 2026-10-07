import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Icon, StatusBadge, Switch, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { ConfirmDialog } from './ConfirmDialog'
import { CopyRoleDialog } from './roles/CopyRoleDialog'
import { PermissionMatrix } from './roles/PermissionMatrix'

/**
 * Two-factor for a saved role applies at once (a Switch, design system):
 * holders must enrol at their next sign-in (RBAC-02, AUTH-03).
 */
function TwoFactorSwitch({ role, disabled }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const mutation = useMutation({
    mutationFn: (next) => api.patch(`roles/${role.id}`, { requires_two_factor: next }),
    onSuccess: (response) => queryClient.setQueryData(['roles', 'detail', role.id], response),
    onSettled: () => queryClient.invalidateQueries({ queryKey: ['roles', 'list'] }),
  })
  return (
    <div className="flex flex-col gap-2">
      <Switch label={t('roles.fields.twoFactor')} checked={role.requires_two_factor} onChange={(next) => mutation.mutate(next)} disabled={disabled || mutation.isPending} />
      <p className="text-caption text-ink-muted">{t('roles.fields.twoFactorHelp')}</p>
      {mutation.isSuccess ? (
        <p role="status" className="text-caption text-ink-muted">
          {role.requires_two_factor ? t('roles.twoFactorOn') : t('roles.twoFactorOff')}
        </p>
      ) : null}
      {mutation.isError ? <Alert tone="danger" title={errorMessage(mutation.error)} /> : null}
    </div>
  )
}

/** The editable fields of a role and its permissions; `role` is null when creating. */
function RoleForm({ role, catalogue, editable }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const creating = !role
  const [values, setValues] = useState({ name: role?.name ?? '', description: role?.description ?? '', requires_two_factor: false })
  const [selected, setSelected] = useState(() => new Set(role?.permissions ?? []))
  const [saved, setSaved] = useState(false)

  const toggle = (name) => {
    setSaved(false)
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(name)) next.delete(name)
      else next.add(name)
      return next
    })
  }

  const mutation = useMutation({
    mutationFn: () => {
      const body = { name: values.name.trim(), description: values.description.trim() || null, permissions: [...selected].sort() }
      return creating ? api.post('roles', { ...body, requires_two_factor: values.requires_two_factor }) : api.patch(`roles/${role.id}`, body)
    },
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      if (creating) navigate(`/settings/roles/${response.data.id}`, { replace: true })
      else setSaved(true)
    },
  })
  const errors = formErrors(mutation.error, ['name', 'description'])
  const formError = errors.form ? errorMessage(mutation.error) : null
  useErrorFocus(formRef, alertRef, mutation.error)
  const set = (field) => (event) => {
    setSaved(false)
    setValues((current) => ({ ...current, [field]: event.target.value }))
  }

  return (
    <form
      ref={formRef}
      noValidate
      onSubmit={(event) => {
        event.preventDefault()
        mutation.mutate()
      }}
      className="flex flex-col gap-5"
    >
      {formError ? (
        <div ref={alertRef} tabIndex={-1} className="rounded-md">
          <Alert tone="danger" title={formError} />
        </div>
      ) : null}
      {saved ? <Alert tone="success" title={t('roles.saved')} /> : null}
      <Card title={t('roles.details')}>
        <div className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('roles.fields.name')} value={values.name} onChange={set('name')} error={errors.fields.name} disabled={!editable} maxLength={100} required />
            <TextField
              label={t('roles.fields.description')}
              value={values.description}
              onChange={set('description')}
              error={errors.fields.description}
              disabled={!editable}
              maxLength={1000}
            />
          </div>
          {creating ? (
            <Checkbox
              label={t('roles.fields.twoFactor')}
              help={t('roles.fields.twoFactorHelp')}
              checked={values.requires_two_factor}
              onChange={(event) => setValues((current) => ({ ...current, requires_two_factor: event.target.checked }))}
            />
          ) : (
            <TwoFactorSwitch role={role} disabled={!editable} />
          )}
        </div>
      </Card>
      <Card title={t('roles.permissions')} subtitle={t('roles.permissionCount', { count: selected.size })}>
        <PermissionMatrix catalogue={catalogue} selected={selected} onToggle={toggle} disabled={!editable} />
      </Card>
      {editable ? (
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="ghost" onClick={() => navigate('/settings/roles')}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" type="submit" loading={mutation.isPending}>
            {creating ? t('roles.create') : t('common.save')}
          </Button>
        </div>
      ) : null}
    </form>
  )
}

/** RBAC-02..RBAC-04, RBAC-12: one role: details, two-factor and the permission matrix. System roles are read-only. */
export default function RoleDetail() {
  const { t } = useTranslation()
  const { roleId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { tenantWide } = usePermissions()
  const [copying, setCopying] = useState(false)
  const [archiving, setArchiving] = useState(false)
  const creating = !roleId

  const role = useQuery({ queryKey: ['roles', 'detail', roleId], queryFn: () => api.get(`roles/${roleId}`), enabled: !creating })
  const catalogue = useQuery({ queryKey: ['permissions'], queryFn: () => api.get('permissions'), staleTime: 5 * 60_000 })
  const archive = useMutation({
    mutationFn: () => api.post(`roles/${roleId}/archive`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['roles'] })
      navigate('/settings/roles')
    },
  })

  const back = (
    <Link to="/settings/roles" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t('roles.backToRoles')}
    </Link>
  )
  const failed = role.error ?? catalogue.error
  if (failed) {
    return (
      <>
        {back}
        <Alert tone="danger" title={failed.status === 404 ? t('roles.notFound') : errorMessage(failed)} />
      </>
    )
  }
  if ((!creating && role.isPending) || catalogue.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>

  const data = creating ? null : role.data.data
  const archived = Boolean(data?.archived_at)
  const editable = creating ? tenantWide('core.role.create') : !data.is_system && !archived && tenantWide('core.role.edit')
  const canCopy = !creating && tenantWide('core.role.create')
  const canArchive = !creating && !data.is_system && !archived && tenantWide('core.role.archive')

  return (
    <>
      {back}
      <PageHeader
        title={creating ? t('roles.newTitle') : data.name}
        description={
          data?.is_system ? (
            <StatusBadge tone="neutral">{t('roles.system')}</StatusBadge>
          ) : archived ? (
            <StatusBadge tone="neutral">{t('roles.archived')}</StatusBadge>
          ) : creating ? (
            t('roles.newText')
          ) : null
        }
        actions={
          <>
            {canCopy ? (
              <Button icon="copy" onClick={() => setCopying(true)}>
                {t('roles.copy')}
              </Button>
            ) : null}
            {canArchive ? (
              <Button variant="danger" onClick={() => setArchiving(true)}>
                {t('roles.archive')}
              </Button>
            ) : null}
          </>
        }
      />
      {data?.is_system ? (
        <Alert tone="info" title={t('roles.systemTitle')}>
          {t('roles.systemText')}
        </Alert>
      ) : null}
      <RoleForm key={data?.id ?? 'new'} role={data} catalogue={catalogue.data?.data ?? {}} editable={editable} />
      {copying ? <CopyRoleDialog role={data} onClose={() => setCopying(false)} /> : null}
      <ConfirmDialog
        open={archiving}
        title={data ? t('roles.archiveTitle', { name: data.name }) : ''}
        confirmLabel={t('roles.archiveConfirm')}
        cancelLabel={t('roles.keepRole')}
        pending={archive.isPending}
        error={errorMessage(archive.error)}
        failure={archive.error}
        onConfirm={() => archive.mutate()}
        onClose={() => {
          setArchiving(false)
          archive.reset()
        }}
      >
        {t('roles.archiveText')}
      </ConfirmDialog>
    </>
  )
}
