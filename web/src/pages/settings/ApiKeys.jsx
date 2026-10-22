import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Chart } from '@/components/Chart'
import { Alert, Button, Card, DataTable, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCalendarDate } from '@/lib/dates'
import { formatInteger, formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { ScopePicker } from './apiKeys/ScopePicker'
import { AssignmentFields } from './users/AssignmentFields'
import { emptyAssignment, offeredRow, scopeLabel, useGrantOptions } from './users/assignments'

const KEYS = ['api-keys']
const TONES = { active: 'success', expired: 'warning', revoked: 'neutral' }
const OVERLAPS = [0, 1, 24, 72, 168]

/** The plain key, shown once after create or rotate, with a copy button. */
function SecretDialog({ secret, onClose }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(secret)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }

  return (
    <Dialog
      open={Boolean(secret)}
      title={t('apiKeys.secret.title')}
      onClose={onClose}
      footer={
        <Button variant="primary" onClick={onClose}>
          {t('apiKeys.secret.done')}
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        <Alert tone="warning" title={t('apiKeys.secret.once')} />
        <code data-testid="api-key-secret" className="block rounded-md border border-border bg-surface-100 p-3 font-mono text-caption break-all text-ink select-all">
          {secret}
        </code>
        <div className="flex items-center gap-3">
          <Button icon="copy" onClick={copy}>
            {t('apiKeys.secret.copy')}
          </Button>
          {copied ? <span className="text-caption text-ink-muted" role="status">{t('apiKeys.secret.copied')}</span> : null}
        </div>
      </div>
    </Dialog>
  )
}

/** API-02: a new key: name, one role at one place (RBAC-04, no owner role), scopes and an optional expiry. */
function CreateKeyDialog({ open, onClose, onCreated }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const scopesQuery = useQuery({ queryKey: [...KEYS, 'scopes'], queryFn: () => api.get('api-keys/scopes'), enabled: open })
  const [name, setName] = useState('')
  const [row, setRow] = useState(() => emptyAssignment())
  const grants = useGrantOptions({ rows: [row] })
  const options = { ...grants, rolesFor: (value) => grants.rolesFor(value).filter((role) => !role.is_owner) }
  const [scopes, setScopes] = useState([])
  const [expires, setExpires] = useState('')
  const shownRow = offeredRow(row, options)

  const reset = () => {
    setName('')
    setRow(emptyAssignment(options.scopeTypes))
    setScopes([])
    setExpires('')
  }
  const create = useMutation({
    mutationFn: () =>
      api.post('api-keys', {
        name: name.trim(),
        role_id: shownRow.role_id,
        scope_type: shownRow.scope_type,
        scope_id: shownRow.scope_type === 'tenant' ? null : shownRow.scope_id,
        scopes,
        expires_at: expires ? new Date(`${expires}T23:59:59`).toISOString() : null,
      }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: KEYS })
      reset()
      create.reset()
      onCreated(response.secret)
    },
  })
  const errors = formErrors(create.error, ['name', 'role_id', 'scope_type', 'scope_id', 'scopes', 'expires_at'])

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('apiKeys.create.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={create.isPending} disabled={!name.trim() || !shownRow.role_id || scopes.length === 0} onClick={() => create.mutate()}>
            {t('apiKeys.create.submit')}
          </Button>
        </>
      }
    >
      <form
        noValidate
        className="flex flex-col gap-5"
        onSubmit={(event) => {
          event.preventDefault()
          create.mutate()
        }}
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('apiKeys.fields.name')} help={t('apiKeys.create.nameHelp')} value={name} onChange={(event) => setName(event.target.value)} error={errors.fields.name} required />
          <TextField
            label={t('apiKeys.fields.expires')}
            help={t('apiKeys.create.expiresHelp')}
            type="date"
            value={expires}
            onChange={(event) => setExpires(event.target.value)}
            error={errors.fields.expires_at}
          />
        </div>
        <div className="flex flex-col gap-2">
          <p className="text-ink-muted">{t('apiKeys.create.roleText')}</p>
          <AssignmentFields index={0} value={shownRow} onChange={setRow} options={options} errors={errors.fields} />
        </div>
        {scopesQuery.isError ? <Alert tone="danger" title={errorMessage(scopesQuery.error)} /> : null}
        <ScopePicker groups={scopesQuery.data?.data ?? []} value={scopes} onChange={setScopes} error={errors.fields.scopes} />
      </form>
    </Dialog>
  )
}

function RotateDialog({ apiKey, onClose, onRotated }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [overlap, setOverlap] = useState('24')
  const rotate = useMutation({
    mutationFn: () => api.post(`api-keys/${apiKey.id}/rotate`, { overlap_hours: Number(overlap) }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: KEYS })
      onRotated(response.secret)
    },
  })

  return (
    <Dialog
      open={Boolean(apiKey)}
      title={t('apiKeys.rotate.title', { name: apiKey?.name ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={rotate.isPending} onClick={() => rotate.mutate()}>
            {t('apiKeys.rotate.confirm')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {rotate.error ? <Alert tone="danger" title={errorMessage(rotate.error)} /> : null}
        <p>{t('apiKeys.rotate.body')}</p>
        <Select
          label={t('apiKeys.rotate.overlap')}
          options={OVERLAPS.map((hours) => ({ value: String(hours), label: t('apiKeys.rotate.hours', { count: hours }) }))}
          value={overlap}
          onChange={(event) => setOverlap(event.target.value)}
          className="max-w-field"
        />
      </div>
    </Dialog>
  )
}

function RevokeDialog({ apiKey, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const revoke = useMutation({
    mutationFn: () => api.post(`api-keys/${apiKey.id}/revoke`),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: KEYS })
      onClose()
    },
  })

  return (
    <Dialog
      open={Boolean(apiKey)}
      title={t('apiKeys.revoke.title', { name: apiKey?.name ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="danger" loading={revoke.isPending} onClick={() => revoke.mutate()}>
            {t('apiKeys.revoke.confirm')}
          </Button>
        </>
      }
    >
      {revoke.error ? <Alert tone="danger" title={errorMessage(revoke.error)} /> : null}
      <p>{t('apiKeys.revoke.body')}</p>
    </Dialog>
  )
}

/** API-03: requests per UTC day, for every key or one. */
function UsageCard({ keys }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const [keyId, setKeyId] = useState('')
  const query = useQuery({ queryKey: [...KEYS, 'usage', keyId], queryFn: () => api.get(keyId ? `api-keys/${keyId}/usage` : 'api-keys/usage') })
  const points = (query.data?.data ?? []).map((day) => ({ label: day.day, value: day.requests }))

  return (
    <Card
      title={t('apiKeys.usage.title')}
      subtitle={query.data ? t('apiKeys.usage.total', { count: query.data.meta.total, total: formatInteger(query.data.meta.total, locale) }) : null}
    >
      <div className="flex flex-col gap-4">
        <Select
          label={t('apiKeys.usage.key')}
          options={[{ value: '', label: t('apiKeys.usage.allKeys') }, ...keys.map((key) => ({ value: key.id, label: key.name }))]}
          value={keyId}
          onChange={(event) => setKeyId(event.target.value)}
          className="max-w-field"
        />
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {query.data ? (
          // The chart scales to its box: kept to a readable width on wide screens.
          <div className="max-w-xl">
          <Chart
            type="bar"
            points={points}
            title={t('apiKeys.usage.title')}
            valueLabel={t('apiKeys.usage.requests')}
            formatValue={(value) => formatInteger(value, locale)}
            formatAxis={(value) => (Number.isInteger(value) ? formatInteger(value, locale) : '')}
            formatLabel={(label) => formatCalendarDate(label, locale)}
          />
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/** API-05 (review): what the sandbox would have sent by email, SMS or WhatsApp, newest first. */
function SandboxOutbox() {
  const { t } = useTranslation()
  const locale = useLocale()
  const query = useQuery({ queryKey: ['sandbox', 'outbox'], queryFn: () => api.get('sandbox/outbox') })
  const messages = query.data?.data ?? []

  return (
    <Card title={t('apiKeys.outbox.title')} subtitle={t('apiKeys.outbox.text')}>
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {!query.isPending && !query.isError && messages.length === 0 ? <p className="text-ink-muted">{t('apiKeys.outbox.empty')}</p> : null}
      <ul className="flex flex-col divide-y divide-border">
        {messages.map((message) => (
          <li key={message.id} className="flex flex-col gap-1 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <span className="font-medium text-ink">{message.subject || t(`apiKeys.outbox.channels.${message.channel}`, { defaultValue: message.channel })}</span>
              <span className="text-caption text-ink-muted">{formatWhen(message.created_at, locale)}</span>
            </div>
            <span className="text-caption text-ink-muted">
              {t('apiKeys.outbox.to', { channel: t(`apiKeys.outbox.channels.${message.channel}`, { defaultValue: message.channel }), recipient: message.recipient ?? '' })}
            </span>
            <p className="text-body whitespace-pre-line break-words text-ink">{message.body}</p>
          </li>
        ))}
      </ul>
    </Card>
  )
}

/** API-05: a developer sandbox's notice and its reset. */
function SandboxCard() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [confirming, setConfirming] = useState(false)
  const reset = useMutation({
    mutationFn: () => api.post('sandbox/reset'),
    onSuccess: async () => {
      setConfirming(false)
      await queryClient.invalidateQueries()
    },
  })

  return (
    <Card title={t('apiKeys.sandbox.title')} actions={<Button onClick={() => setConfirming(true)}>{t('apiKeys.sandbox.reset')}</Button>}>
      <div className="flex flex-col gap-3">
        <p className="text-ink-muted">{t('apiKeys.sandbox.text')}</p>
        {reset.isSuccess ? <Alert tone="success" title={t('apiKeys.sandbox.done')} /> : null}
      </div>
      <Dialog
        open={confirming}
        title={t('apiKeys.sandbox.confirmTitle')}
        onClose={() => setConfirming(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirming(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={reset.isPending} onClick={() => reset.mutate()}>
              {t('apiKeys.sandbox.confirm')}
            </Button>
          </>
        }
      >
        {reset.error ? <Alert tone="danger" title={errorMessage(reset.error)} /> : null}
        <p>{t('apiKeys.sandbox.confirmBody')}</p>
      </Dialog>
    </Card>
  )
}

/**
 * API-02, API-03, API-05: Settings → API keys (core.api_key.manage, tenant
 * scope). Each key acts as a service principal with one role at one place
 * and scopes that narrow it; the secret is shown once. Rotate with an
 * overlap, revoke, last use and requests per day. A sandbox can be reset.
 */
export default function ApiKeys() {
  const { t } = useTranslation()
  const locale = useLocale()
  const [creating, setCreating] = useState(false)
  const [secret, setSecret] = useState(null)
  const [rotating, setRotating] = useState(null)
  const [revoking, setRevoking] = useState(null)
  const query = useQuery({ queryKey: KEYS, queryFn: () => api.get('api-keys?per_page=200') })
  const keys = query.data?.data ?? []
  const sandbox = query.data?.meta?.mode === 'test'

  const columns = [
    {
      key: 'name',
      label: t('apiKeys.fields.name'),
      render: (key) => (
        <div className="flex min-w-0 flex-col">
          <span className="font-medium text-ink">{key.name}</span>
          <code className="font-mono text-caption text-ink-muted">{key.prefix}</code>
        </div>
      ),
    },
    { key: 'status', label: t('apiKeys.fields.status'), render: (key) => <StatusBadge tone={TONES[key.status] ?? 'neutral'}>{t(`apiKeys.status.${key.status}`)}</StatusBadge> },
    {
      key: 'role',
      label: t('apiKeys.fields.role'),
      wrap: true,
      render: (key) =>
        key.role ? (
          <div className="flex flex-col">
            <span className="text-ink">{key.role.name}</span>
            <span className="text-caption text-ink-muted">{key.scope.type === 'tenant' ? t('users.scopeTypes.tenant') : scopeLabel(key.scope.type, { name: key.scope.name })}</span>
          </div>
        ) : (
          <span className="text-ink-muted">{t('apiKeys.noRole')}</span>
        ),
    },
    { key: 'scopes', label: t('apiKeys.fields.scopes'), numeric: true, render: (key) => t('apiKeys.scopeCount', { count: key.scopes.length }) },
    {
      key: 'last_used_at',
      label: t('apiKeys.fields.lastUsed'),
      wrap: true,
      render: (key) => (
        <div className="flex flex-col">
          <span className="text-ink">{key.last_used_at ? formatWhen(key.last_used_at, locale) : t('apiKeys.neverUsed')}</span>
          {key.status === 'active' && key.previous_expires_at ? (
            <span className="text-caption text-ink-muted">{t('apiKeys.overlapUntil', { when: formatWhen(key.previous_expires_at, locale) })}</span>
          ) : null}
          {key.expires_at ? <span className="text-caption text-ink-muted">{t('apiKeys.expiresOn', { when: formatWhen(key.expires_at, locale) })}</span> : null}
        </div>
      ),
    },
    {
      key: 'actions',
      label: t('apiKeys.fields.actions'),
      align: 'end',
      render: (key) =>
        key.status === 'active' ? (
          <div className="flex justify-end gap-2">
            <Button onClick={() => setRotating(key)} aria-label={t('apiKeys.rotate.for', { name: key.name })}>
              {t('apiKeys.rotate.action')}
            </Button>
            <Button variant="ghost" onClick={() => setRevoking(key)} aria-label={t('apiKeys.revoke.for', { name: key.name })}>
              {t('apiKeys.revoke.action')}
            </Button>
          </div>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title={t('apiKeys.title')}
        description={t('apiKeys.description')}
        actions={
          <>
            <Link to="/developers" className="flex h-control items-center px-3 text-label text-primary hover:text-primary-hover">
              {t('apiKeys.portal')}
            </Link>
            <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
              {t('apiKeys.create.action')}
            </Button>
          </>
        }
      />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <div className="flex flex-col gap-5">
        {sandbox ? <SandboxCard /> : null}
        {sandbox ? <SandboxOutbox /> : null}
        <DataTable columns={columns} rows={keys} loading={query.isPending} caption={t('apiKeys.title')} emptyText={t('apiKeys.empty')} />
        <UsageCard keys={keys} />
      </div>
      <CreateKeyDialog open={creating} onClose={() => setCreating(false)} onCreated={(plain) => {
        setCreating(false)
        setSecret(plain)
      }} />
      {rotating ? (
        <RotateDialog
          apiKey={rotating}
          onClose={() => setRotating(null)}
          onRotated={(plain) => {
            setRotating(null)
            setSecret(plain)
          }}
        />
      ) : null}
      {revoking ? <RevokeDialog apiKey={revoking} onClose={() => setRevoking(null)} /> : null}
      <SecretDialog secret={secret} onClose={() => setSecret(null)} />
    </>
  )
}
