import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { usePermissions } from '@/auth/usePermissions'
import { HistoryPanel } from '@/components/HistoryPanel'
import { Alert, Button, Card, Icon, ListView, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useErrorFocus } from '@/lib/useErrorFocus'
import { useLocale } from '@/lib/useLocale'
import { actionsColumn } from '@/lib/listColumns'
import { useServerList } from '@/lib/useServerList'
import { useTimeZone } from '@/lib/useTimeZone'
import { ConfirmDialog } from './ConfirmDialog'
import { AssignmentFields } from './users/AssignmentFields'
import { assignmentBody, emptyAssignment, offeredRow, USER_TONES, useGrantOptions } from './users/assignments'

const LANGUAGES = ['en', 'fr']

function firstName(name) {
  return (name ?? '').trim().split(/\s+/)[0] ?? ''
}

/** Name and language (PATCH users/{id}). */
function ProfileCard({ user, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const formRef = useRef(null)
  const alertRef = useRef(null)
  const [values, setValues] = useState({ name: user.name ?? '', locale: user.locale ?? 'en' })
  const mutation = useMutation({
    mutationFn: () => api.patch(`users/${user.id}`, { name: values.name.trim(), locale: values.locale }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users'] }),
  })
  const errors = formErrors(mutation.error, ['name', 'locale'])
  const formError = errors.form ? errorMessage(mutation.error) : null
  useErrorFocus(formRef, alertRef, mutation.error)

  return (
    <Card title={t('users.detail.profile')}>
      <form
        ref={formRef}
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          mutation.mutate()
        }}
        className="flex flex-col gap-4"
      >
        {formError ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={formError} />
          </div>
        ) : null}
        {mutation.isSuccess ? <Alert tone="success" title={t('users.detail.saved')} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField
            label={t('users.fields.name')}
            value={values.name}
            onChange={(event) => setValues((current) => ({ ...current, name: event.target.value }))}
            error={errors.fields.name}
            disabled={!canEdit}
            required
          />
          <Select
            label={t('users.fields.language')}
            options={LANGUAGES.map((code) => ({ value: code, label: t(`shell.languages.${code}`) }))}
            value={values.locale}
            onChange={(event) => setValues((current) => ({ ...current, locale: event.target.value }))}
            error={errors.fields.locale}
            disabled={!canEdit}
          />
        </div>
        {canEdit ? (
          <div className="flex justify-end">
            <Button variant="primary" type="submit" loading={mutation.isPending}>
              {t('common.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </Card>
  )
}

/** Roles held and where, with add and remove (RBAC-04, RBAC-10, RBAC-12). */
function RolesCard({ user, canAssign }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const options = useGrantOptions()
  const [row, setRow] = useState(null)
  const [removing, setRemoving] = useState(null)
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['users'] })

  const effective = offeredRow(row, options)
  const add = useMutation({
    mutationFn: () => api.post(`users/${user.id}/assignments`, assignmentBody(effective)),
    onSuccess: async () => {
      await refresh()
      setRow(null)
    },
  })
  const remove = useMutation({
    mutationFn: (assignment) => api.delete(`assignments/${assignment.id}`),
    onSuccess: async () => {
      await refresh()
      setRemoving(null)
    },
  })

  const addErrors = formErrors(add.error, ['role_id', 'scope_type', 'scope_id'])
  const where = (assignment) => assignment.scope.name ?? t(`users.scopeTypes.${assignment.scope.type}`)
  const columns = [
    { key: 'role', label: t('users.columns.role'), sortKey: 'role', hideable: false, render: (a) => <span className="font-medium text-ink">{a.role.name}</span> },
    {
      key: 'scope',
      label: t('users.columns.where'),
      sortKey: 'scope_type',
      render: (a) => (
        <span className="flex flex-col">
          <span>{where(a)}</span>
          <span className="text-caption text-ink-muted">{t(`users.scopeTypes.${a.scope.type}`)}</span>
        </span>
      ),
    },
    {
      key: 'granted',
      label: t('users.columns.granted'),
      sortKey: 'granted_at',
      exportKey: 'granted_at',
      render: (a) => (
        <span className="flex flex-col">
          <span>{formatDate(a.granted_at, locale)}</span>
          {a.granted_by ? <span className="text-caption text-ink-muted">{t('users.grantedBy', { name: a.granted_by.name })}</span> : null}
        </span>
      ),
    },
    { key: 'granted_by', label: t('users.columns.grantedBy'), defaultHidden: true, render: (a) => a.granted_by?.name ?? '' },
    ...(canAssign
      ? [
          actionsColumn(t('users.columns.actions'), (a) => (
            <Button variant="secondary" onClick={() => setRemoving(a)} aria-label={t('users.detail.removeRoleFor', { role: a.role.name, scope: where(a) })}>
              {t('users.detail.removeRole')}
            </Button>
          )),
        ]
      : []),
  ]
  // RBAC-04: only the roles held where the reader can see; every role unless paged.
  const list = useServerList({ id: 'user-assignments', endpoint: `users/${user.id}/assignments`, queryKey: ['users', 'assignments', user.id], columns })

  return (
    <Card
      title={t('users.detail.roles')}
      actions={
        canAssign && !row ? (
          <Button icon="plus" onClick={() => setRow(emptyAssignment(options.scopeTypes))}>
            {t('users.detail.addRole')}
          </Button>
        ) : null
      }
    >
      <div className="flex flex-col gap-4">
        <ListView
          list={list}
          title={t('users.detail.roles')}
          searchLabel={t('users.detail.searchRoles')}
          emptyText={list.term ? t('users.detail.noRolesFound') : t('users.detail.noRoles')}
        />
        {effective ? (
          <form
            noValidate
            onSubmit={(event) => {
              event.preventDefault()
              add.mutate()
            }}
            className="flex flex-col gap-3"
          >
            {addErrors.form ? <Alert tone="danger" title={errorMessage(add.error)} /> : null}
            <AssignmentFields index={user.roles?.length ?? 0} value={effective} onChange={setRow} options={options} errors={addErrors.fields} />
            <div className="flex flex-wrap justify-end gap-2">
              <Button
                variant="ghost"
                onClick={() => {
                  setRow(null)
                  add.reset()
                }}
              >
                {t('common.cancel')}
              </Button>
              <Button type="submit" loading={add.isPending}>
                {t('users.detail.giveRole')}
              </Button>
            </div>
          </form>
        ) : null}
      </div>
      <ConfirmDialog
        open={Boolean(removing)}
        title={removing ? t('users.detail.removeTitle', { role: removing.role.name, scope: where(removing), name: user.name }) : ''}
        confirmLabel={t('users.detail.removeConfirm')}
        cancelLabel={t('users.detail.keepRole')}
        pending={remove.isPending}
        error={errorMessage(remove.error)}
        failure={remove.error}
        onConfirm={() => remove.mutate(removing)}
        onClose={() => {
          setRemoving(null)
          remove.reset()
        }}
      >
        {t('users.detail.removeText', { name: firstName(user.name) })}
      </ConfirmDialog>
    </Card>
  )
}

/** AUTH-13, AUTH-09, RBAC-04: one user's profile, roles, status and sessions. */
export default function UserDetail() {
  const { t } = useTranslation()
  const { userId } = useParams()
  const queryClient = useQueryClient()
  const { user: me } = useAuth()
  const { can } = usePermissions()
  const [confirm, setConfirm] = useState(null) // 'deactivate' | 'reactivate' | 'signOut'
  const [signedOut, setSignedOut] = useState(false)
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'history' ? 'history' : 'details'
  const timeZone = useTimeZone()
  const query = useQuery({ queryKey: ['users', 'detail', userId], queryFn: () => api.get(`users/${userId}`) })
  const user = query.data?.data

  const action = useMutation({
    mutationFn: (kind) => api.post(`users/${userId}/${kind === 'signOut' ? 'sign-out-everywhere' : kind}`),
    onSuccess: async (_, kind) => {
      setConfirm(null)
      await queryClient.invalidateQueries({ queryKey: ['users'] })
      setSignedOut(kind === 'signOut')
    },
  })

  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <>
        <Link to="/settings/users" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
          <Icon name="back" />
          {t('users.backToUsers')}
        </Link>
        <Alert tone="danger" title={query.error.status === 404 ? t('users.detail.notFound') : errorMessage(query.error)} />
      </>
    )
  }

  const name = firstName(user.name)
  const self = me?.id === user.id
  const deactivated = user.status === 'deactivated'
  const dialogs = {
    deactivate: {
      title: t('users.detail.deactivateTitle', { name: user.name }),
      text: self ? t('users.detail.deactivateSelfText') : t('users.detail.deactivateText'),
      confirm: t('users.detail.deactivateConfirm', { name }),
      cancel: t('users.detail.keepActive'),
      tone: 'danger',
    },
    reactivate: {
      title: t('users.detail.reactivateTitle', { name: user.name }),
      text: t('users.detail.reactivateText'),
      confirm: t('users.detail.reactivateConfirm', { name }),
      cancel: t('common.cancel'),
      tone: 'primary',
    },
    signOut: {
      title: t('users.detail.signOutTitle', { name: user.name }),
      text: self ? t('users.detail.signOutSelfText') : t('users.detail.signOutText'),
      confirm: t('users.detail.signOutConfirm'),
      cancel: t('users.detail.keepSignedIn'),
      tone: 'danger',
    },
  }
  const current = confirm ? dialogs[confirm] : null

  return (
    <>
      <Link to="/settings/users" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
        <Icon name="back" />
        {t('users.backToUsers')}
      </Link>
      <PageHeader
        title={user.name}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>{user.email ?? user.phone}</span>
            <StatusBadge tone={USER_TONES[user.status]}>{t(`users.status.${user.status}`)}</StatusBadge>
          </span>
        }
        actions={
          <>
            {can('core.user.edit') && !deactivated ? <Button onClick={() => setConfirm('signOut')}>{t('users.detail.signOutEverywhere')}</Button> : null}
            {can('core.user.deactivate') ? (
              deactivated ? (
                <Button onClick={() => setConfirm('reactivate')}>{t('users.detail.reactivate')}</Button>
              ) : (
                <Button variant="danger" onClick={() => setConfirm('deactivate')}>
                  {t('users.detail.deactivate')}
                </Button>
              )
            ) : null}
          </>
        }
      />
      {signedOut ? <Alert tone="success" title={t('users.detail.signedOut', { name })} /> : null}
      <Tabs
        items={[
          { value: 'details', label: t('common.tabs.details') },
          { value: 'history', label: t('common.tabs.history') },
        ]}
        value={tab}
        onChange={(next) => setParams(next === 'history' ? { tab: 'history' } : {}, { replace: true })}
      />
      {tab === 'details' ? (
        <div className="flex flex-col gap-5">
          <ProfileCard key={user.id} user={user} canEdit={can('core.user.edit')} />
          <RolesCard user={user} canAssign={can('core.role.assign')} />
        </div>
      ) : (
        <Card>
          <HistoryPanel type="user" recordId={user.id} timeZone={timeZone} />
        </Card>
      )}
      <ConfirmDialog
        open={Boolean(current)}
        title={current?.title ?? ''}
        confirmLabel={current?.confirm}
        cancelLabel={current?.cancel}
        tone={current?.tone}
        pending={action.isPending}
        error={errorMessage(action.error)}
        failure={action.error}
        onConfirm={() => action.mutate(confirm)}
        onClose={() => {
          setConfirm(null)
          action.reset()
        }}
      >
        {current?.text}
      </ConfirmDialog>
    </>
  )
}
