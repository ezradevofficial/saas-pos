import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, DataTable, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { platformApi } from '../platformClient'
import { usePlatformAuth } from '../usePlatformAuth'

const KEY = ['platform', 'staff']
const STAFF_ROLES = ['owner', 'support', 'sales', 'finance', 'compliance', 'engineering']
const TONES = { active: 'success', pending: 'warning', deactivated: 'neutral' }

/**
 * ADM-07: platform staff and their roles (platform owners). Inviting
 * shows a one-time setup link; two-factor is set up from it.
 */
export default function StaffConsole() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { user } = usePlatformAuth()
  const [inviting, setInviting] = useState(false)
  const staff = useQuery({ queryKey: KEY, queryFn: () => platformApi.get('platform/staff') })
  const change = useMutation({
    mutationFn: ({ id, role, action }) => (action ? platformApi.post(`platform/staff/${id}/${action}`, {}) : platformApi.patch(`platform/staff/${id}`, { role })),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }),
  })
  const roleOptions = STAFF_ROLES.map((role) => ({ value: role, label: t(`platform.roles.${role}`) }))
  const columns = [
    { key: 'name', label: t('admin.staff.name'), render: (row) => <span className="text-ink">{row.name}</span> },
    { key: 'email', label: t('admin.staff.email'), render: (row) => row.email },
    {
      key: 'role',
      label: t('admin.staff.role'),
      render: (row) => (
        <Select aria-label={t('admin.staff.roleOf', { name: row.name })} value={row.role} options={roleOptions} onChange={(event) => change.mutate({ id: row.id, role: event.target.value })} />
      ),
    },
    { key: 'status', label: t('admin.staff.status'), render: (row) => <StatusBadge tone={TONES[row.status]}>{t(`admin.staff.statuses.${row.status}`)}</StatusBadge> },
    { key: 'last', label: t('admin.staff.lastSignIn'), render: (row) => (row.last_sign_in_at ? formatDateTime(row.last_sign_in_at, locale) : '') },
    {
      key: 'actions',
      label: t('subscription.invoices.columns.actions'),
      render: (row) =>
        row.id === user?.id ? null : row.status === 'deactivated' ? (
          <Button variant="ghost" onClick={() => change.mutate({ id: row.id, action: 'reactivate' })}>{t('admin.staff.reactivate')}</Button>
        ) : (
          <Button variant="ghost" onClick={() => change.mutate({ id: row.id, action: 'deactivate' })}>{t('admin.staff.deactivate')}</Button>
        ),
    },
  ]
  return (
    <>
      <PageHeader title={t('admin.staff.title')} description={t('admin.staff.description')} actions={<Button variant="primary" onClick={() => setInviting(true)}>{t('admin.staff.invite')}</Button>} />
      {staff.error ? <Alert tone="danger" title={errorMessage(staff.error)} /> : null}
      {change.error ? <Alert tone="danger" title={errorMessage(change.error)} /> : null}
      <DataTable caption={t('admin.staff.title')} columns={columns} rows={staff.data?.data ?? []} loading={staff.isPending} />
      {inviting ? <InviteDialog roleOptions={roleOptions} onClose={() => setInviting(false)} /> : null}
    </>
  )
}

function InviteDialog({ roleOptions, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState({ name: '', email: '', role: 'support' })
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))
  const invite = useMutation({ mutationFn: () => platformApi.post('platform/staff', values), onSuccess: () => queryClient.invalidateQueries({ queryKey: KEY }) })
  const errors = formErrors(invite.error)
  const link = invite.data?.data?.setup_url
  return (
    <Dialog
      open
      title={t('admin.staff.inviteTitle')}
      onClose={onClose}
      footer={
        link ? (
          <Button variant="primary" onClick={onClose}>{t('admin.close')}</Button>
        ) : (
          <>
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button variant="primary" loading={invite.isPending} disabled={!values.name.trim() || !values.email.trim()} onClick={() => invite.mutate()}>{t('admin.staff.sendInvite')}</Button>
          </>
        )
      }
    >
      {link ? (
        <div className="flex flex-col gap-3">
          <Alert tone="success" title={t('admin.staff.invited', { hours: invite.data.data.setup_expires_hours })} />
          <TextField label={t('admin.staff.setupLink')} value={link} readOnly onFocus={(event) => event.target.select()} />
        </div>
      ) : (
        <div className="flex flex-col gap-4">
          <TextField label={t('admin.staff.name')} value={values.name} error={errors.name} onChange={set('name')} />
          <TextField type="email" label={t('admin.staff.email')} value={values.email} error={errors.email} onChange={set('email')} />
          <Select label={t('admin.staff.role')} value={values.role} options={roleOptions} onChange={set('role')} />
          {invite.error && !errors.name && !errors.email ? <Alert tone="danger" title={errorMessage(invite.error)} /> : null}
        </div>
      )}
    </Dialog>
  )
}
