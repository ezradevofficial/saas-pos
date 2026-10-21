import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { goToIdp, readFragment, ssoReason } from '@/auth/sso'
import { Alert, Button, Card, Dialog, Select, StatusBadge, Switch, TextField } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { Textarea } from '@/components/ui/textarea'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { cn } from '@/lib/utils'
import { AssignmentFields } from './users/AssignmentFields'
import { offeredRow, useGrantOptions } from './users/assignments'

const SSO_KEY = ['sso']
const DOMAIN_TONES = { pending: 'warning', verified: 'success', failed: 'danger' }
const PROVIDERS = ['entra', 'google', 'saml']

/** A value the owner copies into their DNS or identity provider, in the code font. */
function CopyValue({ label, value }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <span className="text-caption text-ink-muted">{label}</span>
      <code className="font-mono text-caption break-all text-ink select-all">{value}</code>
    </div>
  )
}

function useRefresh() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: SSO_KEY })
}

/** A labelled multi-line field (metadata XML, certificates). */
function TextArea({ id, label, help, error, value, onChange, rows = 4 }) {
  return (
    <Field id={id} label={label} help={help} error={error}>
      <Textarea
        id={id}
        rows={rows}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error || help ? `${id}-msg` : undefined}
        className={cn('rounded-md border-border-strong bg-surface-200 font-mono text-caption text-ink', error && 'border-danger')}
      />
    </Field>
  )
}

function DomainRow({ domain }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const refresh = useRefresh()
  const [removing, setRemoving] = useState(false)
  const check = useMutation({ mutationFn: () => api.post(`sso/domains/${domain.id}/check`), onSuccess: refresh })
  const archive = useMutation({
    mutationFn: () => api.post(`sso/domains/${domain.id}/archive`),
    onSuccess: async () => {
      await refresh()
      setRemoving(false)
    },
  })
  const failure = check.error ?? archive.error

  return (
    <li className="flex flex-col gap-3 py-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 flex-wrap items-center gap-3">
          <span className="font-medium break-all text-ink">{domain.domain}</span>
          <StatusBadge tone={DOMAIN_TONES[domain.status] ?? 'neutral'}>{t(`sso.domains.status.${domain.status}`)}</StatusBadge>
        </div>
        <div className="flex flex-wrap gap-2">
          {domain.status !== 'verified' ? (
            <Button icon="sync" loading={check.isPending} onClick={() => check.mutate()} aria-label={t('sso.domains.checkFor', { domain: domain.domain })}>
              {t('sso.domains.check')}
            </Button>
          ) : null}
          <Button variant="ghost" onClick={() => setRemoving(true)} aria-label={t('sso.domains.removeFor', { domain: domain.domain })}>
            {t('sso.domains.remove')}
          </Button>
        </div>
      </div>
      {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
      {domain.status === 'verified' ? (
        <p className="text-caption text-ink-muted">{t('sso.domains.verifiedText', { when: domain.verified_at ? formatWhen(domain.verified_at, locale) : '' })}</p>
      ) : (
        <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4">
          <p className="text-ink">{domain.status === 'failed' ? t('sso.domains.failedText') : t('sso.domains.pendingText')}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            <CopyValue label={t('sso.domains.record.type')} value={domain.record.type} />
            <CopyValue label={t('sso.domains.record.name')} value={domain.record.name} />
            <CopyValue label={t('sso.domains.record.value')} value={domain.record.value} />
          </div>
          {domain.checked_at ? (
            <p className="text-caption text-ink-muted">
              {t(`sso.domains.failure.${domain.failure ?? 'record_missing'}`)} {t('sso.domains.checkedAt', { when: formatWhen(domain.checked_at, locale) })}
            </p>
          ) : null}
        </div>
      )}
      <Dialog
        open={removing}
        title={t('sso.domains.removeTitle', { domain: domain.domain })}
        onClose={() => setRemoving(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setRemoving(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
              {t('sso.domains.removeConfirm')}
            </Button>
          </>
        }
      >
        <p>{t('sso.domains.removeBody')}</p>
      </Dialog>
    </li>
  )
}

function Domains({ domains }) {
  const { t } = useTranslation()
  const refresh = useRefresh()
  const [domain, setDomain] = useState('')
  const add = useMutation({
    mutationFn: () => api.post('sso/domains', { domain }),
    onSuccess: async () => {
      setDomain('')
      await refresh()
    },
  })
  const errors = formErrors(add.error, ['domain'])

  return (
    <Card title={t('sso.domains.title')} subtitle={t('sso.domains.text')}>
      <div className="flex flex-col gap-4">
        <form
          noValidate
          className="flex flex-wrap items-start gap-3"
          onSubmit={(event) => {
            event.preventDefault()
            add.mutate()
          }}
        >
          <TextField
            label={t('sso.domains.label')}
            help={t('sso.domains.help')}
            placeholder="company.co.ke"
            value={domain}
            onChange={(event) => setDomain(event.target.value)}
            error={errors.fields.domain ?? errors.form}
            className="min-w-0 max-w-field flex-1"
          />
          <div className="pt-6">
            <Button type="submit" variant="primary" icon="plus" loading={add.isPending} disabled={domain.trim() === ''}>
              {t('sso.domains.add')}
            </Button>
          </div>
        </form>
        {domains.length === 0 ? <p className="text-ink-muted">{t('sso.domains.empty')}</p> : null}
        <ul className="divide-y divide-border">
          {domains.map((row) => (
            <DomainRow key={row.id} domain={row} />
          ))}
        </ul>
      </div>
    </Card>
  )
}

const FIELDS = ['provider', 'name', 'client_id', 'client_secret', 'directory_id', 'hosted_domain', 'metadata_url', 'metadata_xml', 'idp_entity_id', 'idp_sso_url', 'idp_certificates']

/** Add or edit a connection; the secret is write-only (left empty to keep it). */
function ConnectionDialog({ connection, onClose }) {
  const { t } = useTranslation()
  const refresh = useRefresh()
  const editing = Boolean(connection)
  const [values, setValues] = useState(() => ({
    provider: connection?.provider ?? 'entra',
    name: connection?.name ?? '',
    client_id: connection?.settings?.client_id ?? '',
    client_secret: '',
    directory_id: connection?.settings?.directory_id ?? '',
    hosted_domain: connection?.settings?.hosted_domain ?? '',
    metadata_url: '',
    metadata_xml: '',
    idp_entity_id: connection?.settings?.idp_entity_id ?? '',
    idp_sso_url: connection?.settings?.idp_sso_url ?? '',
    certificate: connection?.settings?.idp_certificates?.[0] ?? '',
  }))
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target?.value ?? event }))
  const saml = values.provider === 'saml'

  const body = () => {
    const filled = (value) => (value.trim() === '' ? undefined : value.trim())
    if (saml) {
      const fromMetadata = filled(values.metadata_url) || filled(values.metadata_xml)
      return {
        ...(editing ? {} : { provider: 'saml' }),
        name: values.name.trim(),
        metadata_url: filled(values.metadata_url),
        metadata_xml: filled(values.metadata_xml),
        ...(fromMetadata
          ? {}
          : { idp_entity_id: values.idp_entity_id.trim(), idp_sso_url: values.idp_sso_url.trim(), idp_certificates: values.certificate.trim() ? [values.certificate.trim()] : [] }),
      }
    }
    return {
      ...(editing ? {} : { provider: values.provider }),
      name: values.name.trim(),
      client_id: values.client_id.trim(),
      client_secret: filled(values.client_secret),
      ...(values.provider === 'entra' ? { directory_id: values.directory_id.trim() } : { hosted_domain: values.hosted_domain.trim() || null }),
    }
  }

  const save = useMutation({
    mutationFn: () => (editing ? api.patch(`sso/connections/${connection.id}`, body()) : api.post('sso/connections', body())),
    onSuccess: async () => {
      await refresh()
      onClose()
    },
  })
  const errors = formErrors(save.error, FIELDS, { 'idp_certificates.0': 'idp_certificates' })

  return (
    <Dialog
      open
      size="lg"
      title={editing ? t('sso.form.editTitle', { name: connection.name }) : t('sso.form.addTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={() => save.mutate()}>
            {t('sso.form.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('sso.form.provider')}
            options={PROVIDERS.map((provider) => ({ value: provider, label: t(`sso.connections.provider.${provider}`) }))}
            value={values.provider}
            onChange={set('provider')}
            error={errors.fields.provider}
            disabled={editing}
          />
          <TextField label={t('sso.form.name')} help={t('sso.form.nameHelp')} value={values.name} onChange={set('name')} error={errors.fields.name} required />
        </div>
        {saml ? (
          <>
            <TextField label={t('sso.form.metadataUrl')} help={t('sso.form.metadataUrlHelp')} value={values.metadata_url} onChange={set('metadata_url')} error={errors.fields.metadata_url} />
            <TextArea id="sso-metadata-xml" label={t('sso.form.metadataXml')} value={values.metadata_xml} onChange={set('metadata_xml')} error={errors.fields.metadata_xml} />
            <p className="text-label text-ink">{t('sso.form.manual')}</p>
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField label={t('sso.form.idpEntityId')} value={values.idp_entity_id} onChange={set('idp_entity_id')} error={errors.fields.idp_entity_id} />
              <TextField label={t('sso.form.idpSsoUrl')} value={values.idp_sso_url} onChange={set('idp_sso_url')} error={errors.fields.idp_sso_url} />
            </div>
            <TextArea
              id="sso-certificate"
              label={t('sso.form.certificate')}
              help={t('sso.form.certificateHelp')}
              value={values.certificate}
              onChange={set('certificate')}
              error={errors.fields.idp_certificates}
              rows={6}
            />
          </>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField label={t('sso.form.clientId')} value={values.client_id} onChange={set('client_id')} error={errors.fields.client_id} required />
            <TextField
              label={t('sso.form.clientSecret')}
              help={connection?.has_secret ? t('sso.form.clientSecretKeep') : t('sso.form.clientSecretHelp')}
              type="password"
              autoComplete="off"
              value={values.client_secret}
              onChange={set('client_secret')}
              error={errors.fields.client_secret}
              required={!editing}
            />
            {values.provider === 'entra' ? (
              <TextField
                label={t('sso.form.directoryId')}
                help={t('sso.form.directoryIdHelp')}
                value={values.directory_id}
                onChange={set('directory_id')}
                error={errors.fields.directory_id}
                required
              />
            ) : (
              <TextField
                label={t('sso.form.hostedDomain')}
                help={t('sso.form.hostedDomainHelp')}
                placeholder="company.co.ke"
                value={values.hosted_domain}
                onChange={set('hosted_domain')}
                error={errors.fields.hosted_domain}
              />
            )}
          </div>
        )}
      </div>
    </Dialog>
  )
}

function ConnectionRow({ connection, onEdit }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const refresh = useRefresh()
  const [removing, setRemoving] = useState(false)
  const action = useMutation({ mutationFn: (verb) => api.post(`sso/connections/${connection.id}/${verb}`), onSuccess: refresh })
  const test = useMutation({
    mutationFn: () => api.post(`sso/connections/${connection.id}/test`, { origin: window.location.origin }),
    onSuccess: (response) => goToIdp(response.data),
  })
  const archive = useMutation({
    mutationFn: () => api.post(`sso/connections/${connection.id}/archive`),
    onSuccess: async () => {
      await refresh()
      setRemoving(false)
    },
  })
  const last = connection.last_test
  const failure = action.error ?? test.error ?? archive.error
  const name = connection.name
  const when = last?.at ? formatWhen(last.at, locale) : ''

  return (
    <li className="flex flex-col gap-3 py-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex min-w-0 flex-wrap items-center gap-3">
          <span className="font-medium text-ink">{name}</span>
          <span className="text-caption text-ink-muted">{t(`sso.connections.provider.${connection.provider}`)}</span>
          <StatusBadge tone={connection.enabled ? 'success' : 'neutral'}>{connection.enabled ? t('sso.connections.enabled') : t('sso.connections.disabled')}</StatusBadge>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button loading={test.isPending} onClick={() => test.mutate()} aria-label={t('sso.connections.testFor', { name })}>
            {t('sso.connections.test')}
          </Button>
          {connection.enabled ? (
            <Button loading={action.isPending} onClick={() => action.mutate('disable')} aria-label={t('sso.connections.disableFor', { name })}>
              {t('sso.connections.disable')}
            </Button>
          ) : (
            <Button
              variant="primary"
              disabled={!connection.tested_at}
              loading={action.isPending}
              onClick={() => action.mutate('enable')}
              aria-label={t('sso.connections.enableFor', { name })}
            >
              {t('sso.connections.enable')}
            </Button>
          )}
          <Button variant="ghost" onClick={onEdit} aria-label={t('sso.connections.editFor', { name })}>
            {t('sso.connections.edit')}
          </Button>
          <Button variant="ghost" onClick={() => setRemoving(true)} aria-label={t('sso.connections.removeFor', { name })}>
            {t('sso.connections.remove')}
          </Button>
        </div>
      </div>
      {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
      <div className="flex flex-col gap-1 text-caption text-ink-muted">
        {!last ? <p>{t('sso.connections.untested')}</p> : null}
        {last?.ok ? (
          <>
            <p className="text-ink">{t('sso.connections.testOk', { when, email: last.email })}</p>
            {last.action ? <p>{t(`sso.connections.testAction.${last.action}`)}</p> : null}
            <p>{last.mfa ? t('sso.connections.testMfa') : t('sso.connections.testNoMfa')}</p>
          </>
        ) : null}
        {last && !last.ok ? <p className="text-danger">{t('sso.connections.testFailed', { when, reason: ssoReason(t, last.reason) })}</p> : null}
      </div>
      <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4">
        <p className="text-label text-ink">{t('sso.connections.register')}</p>
        {connection.provider === 'saml' ? (
          <div className="grid gap-3 sm:grid-cols-2">
            <CopyValue label={t('sso.connections.entityId')} value={connection.sp.entity_id} />
            <CopyValue label={t('sso.connections.acsUrl')} value={connection.sp.acs_url} />
          </div>
        ) : (
          <CopyValue label={t('sso.connections.redirectUri')} value={connection.sp.redirect_uri} />
        )}
      </div>
      <Dialog
        open={removing}
        title={t('sso.connections.removeTitle', { name })}
        onClose={() => setRemoving(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setRemoving(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
              {t('sso.connections.removeConfirm')}
            </Button>
          </>
        }
      >
        <p>{t('sso.connections.removeBody')}</p>
      </Dialog>
    </li>
  )
}

function Connections({ connections }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState(null)

  return (
    <Card
      title={t('sso.connections.title')}
      subtitle={t('sso.connections.text')}
      actions={
        <Button variant="primary" icon="plus" onClick={() => setEditing('new')}>
          {t('sso.connections.add')}
        </Button>
      }
    >
      {connections.length === 0 ? <p className="text-ink-muted">{t('sso.connections.empty')}</p> : null}
      <ul className="divide-y divide-border">
        {connections.map((connection) => (
          <ConnectionRow key={connection.id} connection={connection} onEdit={() => setEditing(connection)} />
        ))}
      </ul>
      {editing ? <ConnectionDialog connection={editing === 'new' ? null : editing} onClose={() => setEditing(null)} /> : null}
    </Card>
  )
}

/** Enforcement and JIT provisioning with its default role and place (RBAC-04: only grants the user may make). */
function Rules({ settings }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const options = useGrantOptions({ invite: true })
  const [enforce, setEnforce] = useState(settings.enforce)
  const [jit, setJit] = useState(settings.jit_provisioning)
  const [row, setRow] = useState(() => ({
    role_id: settings.jit_role_id ?? '',
    scope_type: settings.jit_scope_type ?? 'tenant',
    scope_id: settings.jit_scope_id ?? '',
  }))
  const shown = offeredRow(row, options)
  // Owner roles are never a default for new accounts.
  const jitOptions = { ...options, rolesFor: (value) => options.rolesFor(value).filter((role) => !role.is_owner) }

  const save = useMutation({
    mutationFn: () =>
      api.put('sso/settings', {
        enforce,
        jit_provisioning: jit,
        jit_role_id: shown.role_id || null,
        jit_scope_type: shown.role_id ? shown.scope_type : null,
        jit_scope_id: shown.role_id && shown.scope_type !== 'tenant' ? shown.scope_id : null,
      }),
    onSuccess: (response) => queryClient.setQueryData(SSO_KEY, response),
  })
  const errors = formErrors(save.error, ['enforce', 'jit_provisioning', 'jit_role_id', 'jit_scope_type', 'jit_scope_id'])
  const hasFieldErrors = Object.keys(save.error?.errors ?? {}).length > 0

  return (
    <Card title={t('sso.rules.title')}>
      <form
        noValidate
        className="flex flex-col gap-5"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {save.error ? <Alert tone="danger" title={hasFieldErrors ? (errors.fields.enforce ?? errors.form ?? errorMessage(save.error)) : errorMessage(save.error)} /> : null}
        {save.isSuccess ? <Alert tone="success" title={t('sso.rules.saved')} /> : null}
        <div className="flex flex-col gap-1">
          <Switch label={t('sso.rules.enforce')} checked={enforce} onChange={setEnforce} />
          <p className="pl-12 text-caption text-ink-muted">{t('sso.rules.enforceHelp')}</p>
        </div>
        <div className="flex flex-col gap-1">
          <Switch label={t('sso.rules.jit')} checked={jit} onChange={setJit} />
          <p className="pl-12 text-caption text-ink-muted">{t('sso.rules.jitHelp')}</p>
        </div>
        {jit ? (
          <div className="flex flex-col gap-2">
            <p className="text-label text-ink">{t('sso.rules.defaultRole')}</p>
            <AssignmentFields
              index={0}
              value={shown}
              onChange={setRow}
              options={jitOptions}
              errors={{ role_id: errors.fields.jit_role_id, scope_type: errors.fields.jit_scope_type, scope_id: errors.fields.jit_scope_id }}
            />
          </div>
        ) : null}
        <div className="flex justify-end border-t border-border pt-4">
          <Button type="submit" variant="primary" loading={save.isPending}>
            {t('sso.rules.save')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

/**
 * AUTH-11, ADR 023: Settings, Security, Single sign-on (core.sso.manage,
 * the Owner's): email domains proven by DNS, connections with the values to
 * register at the identity provider and a test sign-in, then the rules.
 */
export default function SingleSignOn() {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: SSO_KEY, queryFn: () => api.get('sso') })
  // Back from a test sign-in: the outcome is in the fragment, the details on the connection.
  const [testOutcome] = useState(() => {
    const { test } = readFragment(window.location.hash)
    return test ? (test === 'ok' ? 'ok' : 'failed') : null
  })

  useEffect(() => {
    if (testOutcome) window.history.replaceState(window.history.state, '', window.location.pathname)
  }, [testOutcome])

  const data = query.data?.data

  return (
    <>
      <PageHeader title={t('sso.title')} description={t('sso.description')} />
      {testOutcome ? <Alert tone={testOutcome === 'ok' ? 'success' : 'danger'} title={t(`sso.test.${testOutcome}`)} /> : null}
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {data ? (
        <div className="flex flex-col gap-5">
          <Domains domains={data.domains} />
          <Connections connections={data.connections} />
          <Rules settings={data.settings} />
        </div>
      ) : null}
    </>
  )
}
