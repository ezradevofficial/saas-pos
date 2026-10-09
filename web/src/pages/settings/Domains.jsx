import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Dialog, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'

const DOMAINS_KEY = ['branding', 'domains']
const SETTINGS_KEY = ['branding', 'settings']
const TONES = { pending: 'warning', verified: 'success', failed: 'danger' }

/** A value the owner copies into their DNS, in the code font. */
function RecordValue({ label, value }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <span className="text-caption text-ink-muted">{label}</span>
      <code className="font-mono text-caption break-all text-ink select-all">{value}</code>
    </div>
  )
}

function DomainRow({ domain, cnameTarget }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const [removing, setRemoving] = useState(false)
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['branding'] })
  const check = useMutation({ mutationFn: () => api.post(`branding/domains/${domain.id}/check`), onSuccess: refresh })
  const archive = useMutation({
    mutationFn: () => api.post(`branding/domains/${domain.id}/archive`),
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
          <span className="font-medium break-all text-ink">{domain.host}</span>
          <StatusBadge tone={TONES[domain.status] ?? 'neutral'}>{t(`domains.status.${domain.status}`)}</StatusBadge>
        </div>
        <div className="flex flex-wrap gap-2">
          {domain.status !== 'verified' ? (
            <Button icon="sync" loading={check.isPending} onClick={() => check.mutate()} aria-label={t('domains.checkFor', { host: domain.host })}>
              {t('domains.check')}
            </Button>
          ) : null}
          <Button variant="ghost" onClick={() => setRemoving(true)} aria-label={t('domains.removeFor', { host: domain.host })}>
            {t('domains.remove')}
          </Button>
        </div>
      </div>

      {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}

      {domain.status === 'verified' ? (
        <p className="text-caption text-ink-muted">
          {t('domains.verifiedText', { when: domain.verified_at ? formatWhen(domain.verified_at, locale) : '' })}
        </p>
      ) : (
        <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4">
          <p className="text-ink">{domain.status === 'failed' ? t('domains.failedText') : t('domains.pendingText')}</p>
          <div className="grid gap-3 sm:grid-cols-3">
            <RecordValue label={t('domains.record.type')} value={domain.record.type} />
            <RecordValue label={t('domains.record.name')} value={domain.record.name} />
            <RecordValue label={t('domains.record.value')} value={domain.record.value} />
          </div>
          {cnameTarget ? <p className="text-caption text-ink-muted">{t('domains.cname', { host: domain.host, target: cnameTarget })}</p> : null}
          {domain.checked_at ? (
            <p className="text-caption text-ink-muted">
              {t(`domains.failure.${domain.failure ?? 'record_missing'}`)} {t('domains.checkedAt', { when: formatWhen(domain.checked_at, locale) })}
            </p>
          ) : null}
        </div>
      )}

      <Dialog
        open={removing}
        title={t('domains.removeTitle', { host: domain.host })}
        onClose={() => setRemoving(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setRemoving(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" loading={archive.isPending} onClick={() => archive.mutate()}>
              {t('domains.removeConfirm')}
            </Button>
          </>
        }
      >
        <p>{t('domains.removeBody')}</p>
      </Dialog>
    </li>
  )
}

function CustomDomains() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [host, setHost] = useState('')
  const query = useQuery({ queryKey: DOMAINS_KEY, queryFn: () => api.get('branding/domains') })
  const add = useMutation({
    mutationFn: () => api.post('branding/domains', { host }),
    onSuccess: async () => {
      setHost('')
      await queryClient.invalidateQueries({ queryKey: DOMAINS_KEY })
    },
  })
  const errors = formErrors(add.error, ['host'])
  const domains = query.data?.data ?? []

  return (
    <Card title={t('domains.custom.title')} subtitle={t('domains.custom.text')}>
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
            label={t('domains.custom.host')}
            help={t('domains.custom.hostHelp')}
            placeholder="erp.company.co.ke"
            value={host}
            onChange={(event) => setHost(event.target.value)}
            error={errors.fields.host ?? errors.form}
            className="min-w-0 flex-1 max-w-field"
          />
          <div className="pt-6">
            <Button type="submit" variant="primary" icon="plus" loading={add.isPending} disabled={host.trim() === ''}>
              {t('domains.custom.add')}
            </Button>
          </div>
        </form>
        {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
        {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {!query.isPending && !query.isError && domains.length === 0 ? <p className="text-ink-muted">{t('domains.custom.empty')}</p> : null}
        <ul className="divide-y divide-border">
          {domains.map((domain) => (
            <DomainRow key={domain.id} domain={domain} cnameTarget={query.data?.meta?.cname_target} />
          ))}
        </ul>
      </div>
    </Card>
  )
}

/** One settings form: saves only its own fields (PUT branding/settings). */
function SettingsForm({ settings, fields, children, saveLabel }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [values, setValues] = useState(() => Object.fromEntries(fields.map((field) => [field, settings[field] ?? ''])))
  const save = useMutation({
    mutationFn: () => api.put('branding/settings', Object.fromEntries(fields.map((field) => [field, values[field] === '' ? null : values[field]]))),
    onSuccess: (response) => queryClient.setQueryData(SETTINGS_KEY, response),
  })
  const errors = formErrors(save.error, fields)
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }))

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        save.mutate()
      }}
    >
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      {save.isSuccess ? <Alert tone="success" title={t('domains.saved')} /> : null}
      {children({ values, set, errors: errors.fields })}
      <div>
        <Button type="submit" variant="primary" loading={save.isPending}>
          {saveLabel}
        </Button>
      </div>
    </form>
  )
}

/**
 * BR-04 to BR-07: Settings → Domains (Owner, Admin): the business's
 * subdomain, custom domains verified by a DNS TXT record, the email sender
 * with SPF and DKIM guidance, the SMS sender ID, and whether "Powered by"
 * is shown.
 */
export default function Domains() {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: SETTINGS_KEY, queryFn: () => api.get('branding/settings') })
  const settings = query.data?.data
  const mail = query.data?.meta?.mail ?? {}

  return (
    <>
      <PageHeader title={t('domains.title')} description={t('domains.description')} />
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {settings ? (
        <div className="flex flex-col gap-5">
          <Card title={t('domains.subdomain.title')} subtitle={t('domains.subdomain.text')}>
            {settings.base_domain ? (
              <SettingsForm settings={settings} fields={['slug']} saveLabel={t('domains.subdomain.save')}>
                {({ values, set, errors }) => (
                  <TextField
                    label={t('domains.subdomain.label')}
                    help={settings.host ? t('domains.subdomain.current', { host: settings.host }) : t('domains.subdomain.help')}
                    suffix={`.${settings.base_domain}`}
                    value={values.slug}
                    onChange={set('slug')}
                    error={errors.slug}
                    className="max-w-field"
                  />
                )}
              </SettingsForm>
            ) : (
              <p className="text-ink-muted">{t('domains.subdomain.off')}</p>
            )}
          </Card>

          <CustomDomains />

          <Card title={t('domains.email.title')} subtitle={t('domains.email.text')}>
            <SettingsForm settings={settings} fields={['email_from_name', 'email_from_address']} saveLabel={t('domains.email.save')}>
              {({ values, set, errors }) => (
                <>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <TextField label={t('domains.email.name')} value={values.email_from_name} onChange={set('email_from_name')} error={errors.email_from_name} />
                    <TextField
                      label={t('domains.email.address')}
                      help={t('domains.email.addressHelp')}
                      type="email"
                      value={values.email_from_address}
                      onChange={set('email_from_address')}
                      error={errors.email_from_address}
                    />
                  </div>
                  <div className="flex items-center gap-3">
                    <StatusBadge tone={settings.email_sender_active ? 'success' : 'neutral'}>
                      {settings.email_sender_active ? t('domains.email.active') : t('domains.email.inactive')}
                    </StatusBadge>
                  </div>
                  <div className="flex flex-col gap-2 rounded-md border border-border bg-surface-100 p-4">
                    <p className="font-medium text-ink">{t('domains.email.guidanceTitle')}</p>
                    <p className="text-ink-muted">{t('domains.email.guidanceText')}</p>
                    <ul className="flex list-disc flex-col gap-2 pl-5 text-ink">
                      <li>
                        {t('domains.email.spf')}{' '}
                        <code className="font-mono text-caption break-all">{mail.spf_include ? `v=spf1 include:${mail.spf_include} ~all` : t('domains.email.askProvider')}</code>
                      </li>
                      <li>
                        {t('domains.email.dkim')}{' '}
                        <code className="font-mono text-caption break-all">
                          {mail.dkim_selector && mail.dkim_target ? `${mail.dkim_selector}._domainkey CNAME ${mail.dkim_target}` : t('domains.email.askProvider')}
                        </code>
                      </li>
                    </ul>
                  </div>
                </>
              )}
            </SettingsForm>
          </Card>

          <Card title={t('domains.sms.title')} subtitle={t('domains.sms.text')}>
            <SettingsForm settings={settings} fields={['sms_sender_id']} saveLabel={t('domains.sms.save')}>
              {({ values, set, errors }) => (
                <TextField
                  label={t('domains.sms.label')}
                  help={t('domains.sms.help')}
                  maxLength={11}
                  value={values.sms_sender_id}
                  onChange={set('sms_sender_id')}
                  error={errors.sms_sender_id}
                  className="max-w-field"
                />
              )}
            </SettingsForm>
          </Card>

          <Card title={t('domains.platform.title')}>
            <div className="flex flex-wrap items-center gap-3">
              <StatusBadge tone="neutral">{settings.hide_platform ? t('domains.platform.hidden') : t('domains.platform.shown')}</StatusBadge>
              <span className="text-ink-muted">{t('domains.platform.text', { name: t('app.name') })}</span>
            </div>
          </Card>
        </div>
      ) : null}
    </>
  )
}
