import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, MoneyInput, MultiSelect, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { CATEGORIES, DEVELOPER, VERSION_TONES } from '@/pages/marketplace/marketplaceData'
import { TextArea } from '@/pages/marketplace/marketplaceParts'
import { SecretsDialog } from './DeveloperApps'

const CURRENCIES = ['KES', 'USD', 'CDF']

/** The form's copy of a version (lists as text, prices as minor-unit strings). */
function formOf(version) {
  const pricing = version.pricing ?? { model: 'free' }
  return {
    name: version.name ?? '',
    tagline: version.tagline ?? '',
    description: version.description ?? '',
    category: version.category ?? 'other',
    website: version.website ?? '',
    support_email: version.support_email ?? '',
    privacy_url: version.privacy_url ?? '',
    redirect_uris: (version.redirect_uris ?? []).join('\n'),
    scopes: version.scopes ?? [],
    webhook_url: version.webhook_url ?? '',
    webhook_events: version.webhook_events ?? [],
    model: pricing.model,
    monthly: Object.fromEntries(CURRENCIES.map((code) => [code, pricing.monthly?.[code] === undefined ? '' : String(pricing.monthly[code])])),
    yearly: Object.fromEntries(CURRENCIES.map((code) => [code, pricing.yearly?.[code] === undefined ? '' : String(pricing.yearly[code])])),
  }
}

/** The API body of the form. */
function bodyOf(form) {
  const prices = (map) => Object.fromEntries(Object.entries(map).filter(([, value]) => value !== '' && value !== null).map(([code, value]) => [code, Number(value)]))
  return {
    name: form.name.trim(),
    tagline: form.tagline.trim() || null,
    description: form.description.trim(),
    category: form.category,
    website: form.website.trim() || null,
    support_email: form.support_email.trim() || null,
    privacy_url: form.privacy_url.trim() || null,
    redirect_uris: form.redirect_uris.split('\n').map((line) => line.trim()).filter(Boolean),
    scopes: form.scopes,
    webhook_url: form.webhook_url.trim() || null,
    webhook_events: form.webhook_events,
    pricing: form.model === 'free' ? { model: 'free' } : { model: 'paid', monthly: prices(form.monthly), yearly: prices(form.yearly) },
  }
}

function DraftEditor({ app, draft, catalogue }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(() => formOf(draft))
  const set = (field) => (value) => setForm((current) => ({ ...current, [field]: value }))
  const invalidate = () => queryClient.invalidateQueries({ queryKey: DEVELOPER })
  const save = useMutation({ mutationFn: () => api.patch(`developer/apps/${app.id}/draft`, bodyOf(form)), onSuccess: invalidate })
  const submit = useMutation({
    mutationFn: async () => {
      await api.patch(`developer/apps/${app.id}/draft`, bodyOf(form))
      return api.post(`developer/apps/${app.id}/submit`)
    },
    onSuccess: invalidate,
  })
  const logo = useMutation({
    mutationFn: (file) => {
      const data = new FormData()
      data.append('logo', file)
      return api.upload(`developer/apps/${app.id}/logo`, data)
    },
    onSuccess: invalidate,
  })
  const fields = ['name', 'tagline', 'description', 'category', 'website', 'support_email', 'privacy_url', 'redirect_uris', 'scopes', 'webhook_url', 'webhook_events', 'pricing']
  const errors = formErrors(submit.error ?? save.error, fields)
  const fieldError = (name) => errors.fields[name] ?? Object.entries(errors.fields).find(([key]) => key.startsWith(`${name}.`))?.[1]

  return (
    <Card title={t('developerApps.draft.title', { version: draft.version })} subtitle={t('developerApps.draft.text')}>
      <form
        noValidate
        className="flex flex-col gap-5"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {save.isSuccess && !save.isPending ? <p role="status" className="text-caption text-ink-muted">{t('developerApps.draft.saved')}</p> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('developerApps.fields.name')} value={form.name} onChange={(event) => set('name')(event.target.value)} error={fieldError('name')} required />
          <TextField label={t('developerApps.fields.tagline')} value={form.tagline} onChange={(event) => set('tagline')(event.target.value)} error={fieldError('tagline')} />
        </div>
        <TextArea label={t('developerApps.fields.description')} help={t('developerApps.fields.descriptionHelp')} rows={6} value={form.description} onChange={(event) => set('description')(event.target.value)} error={fieldError('description')} />
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('developerApps.fields.category')}
            options={CATEGORIES.map((key) => ({ value: key, label: t(`marketplace.categories.${key}`) }))}
            value={form.category}
            onChange={(event) => set('category')(event.target.value)}
          />
          <TextField label={t('developerApps.fields.supportEmail')} type="email" value={form.support_email} onChange={(event) => set('support_email')(event.target.value)} error={fieldError('support_email')} />
          <TextField label={t('developerApps.fields.website')} type="url" value={form.website} onChange={(event) => set('website')(event.target.value)} error={fieldError('website')} />
          <TextField label={t('developerApps.fields.privacy')} type="url" value={form.privacy_url} onChange={(event) => set('privacy_url')(event.target.value)} error={fieldError('privacy_url')} />
        </div>
        <div className="flex flex-col gap-2">
          <span className="text-label text-ink">{t('developerApps.fields.logo')}</span>
          <input
            type="file"
            accept="image/png,image/jpeg,image/webp"
            aria-label={t('developerApps.fields.logo')}
            className="text-caption text-ink-muted"
            onChange={(event) => event.target.files?.[0] && logo.mutate(event.target.files[0])}
          />
          <span className="text-caption text-ink-muted">{draft.has_logo ? t('developerApps.fields.logoSet') : t('developerApps.fields.logoHelp')}</span>
          {logo.error ? <Alert tone="danger" title={errorMessage(logo.error)} /> : null}
        </div>
        <TextArea label={t('developerApps.fields.redirectUris')} help={t('developerApps.fields.redirectHelp')} rows={3} mono value={form.redirect_uris} onChange={(event) => set('redirect_uris')(event.target.value)} error={fieldError('redirect_uris')} />
        <MultiSelect
          label={t('developerApps.fields.scopes')}
          help={t('developerApps.fields.scopesHelp')}
          options={catalogue.scopes.map((scope) => ({ value: scope, label: scope }))}
          value={form.scopes}
          onChange={set('scopes')}
          error={fieldError('scopes')}
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('developerApps.fields.webhookUrl')} type="url" value={form.webhook_url} onChange={(event) => set('webhook_url')(event.target.value)} error={fieldError('webhook_url')} />
          <MultiSelect
            label={t('developerApps.fields.webhookEvents')}
            options={catalogue.webhook_events.map((event) => ({ value: event, label: event }))}
            value={form.webhook_events}
            onChange={set('webhook_events')}
            error={fieldError('webhook_events')}
          />
        </div>
        <section className="flex flex-col gap-3">
          <Select
            label={t('developerApps.fields.pricing')}
            options={[{ value: 'free', label: t('developerApps.pricing.free') }, { value: 'paid', label: t('developerApps.pricing.paid') }]}
            value={form.model}
            onChange={(event) => set('model')(event.target.value)}
            className="max-w-field"
            error={fieldError('pricing')}
          />
          {form.model === 'paid' ? (
            <>
              <p className="text-caption text-ink-muted">{t('developerApps.pricing.help', { share: catalogue.share_percent })}</p>
              <div className="grid gap-4 sm:grid-cols-3">
                {CURRENCIES.map((code) => (
                  <MoneyInput
                    key={`m-${code}`}
                    label={t('developerApps.pricing.monthly', { currency: code })}
                    currency={code}
                    value={form.monthly[code]}
                    onChange={(value) => setForm((current) => ({ ...current, monthly: { ...current.monthly, [code]: value ?? '' } }))}
                    error={errors.fields[`pricing.monthly.${code}`]}
                  />
                ))}
                {CURRENCIES.map((code) => (
                  <MoneyInput
                    key={`y-${code}`}
                    label={t('developerApps.pricing.yearly', { currency: code })}
                    help={t('developerApps.pricing.yearlyHelp')}
                    currency={code}
                    value={form.yearly[code]}
                    onChange={(value) => setForm((current) => ({ ...current, yearly: { ...current.yearly, [code]: value ?? '' } }))}
                    error={errors.fields[`pricing.yearly.${code}`]}
                  />
                ))}
              </div>
            </>
          ) : null}
        </section>
        <div className="flex flex-wrap gap-3">
          <Button type="submit" loading={save.isPending}>
            {t('developerApps.draft.save')}
          </Button>
          <Button variant="primary" loading={submit.isPending} onClick={() => submit.mutate()}>
            {t('developerApps.draft.submit')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

/**
 * MKT-01: one of the developer's apps: the draft editor, the review state
 * of each version (with the reviewer's note), and the client and webhook
 * secrets (rotated here, shown once), archive and restore.
 */
export default function DeveloperApp() {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const { appId } = useParams()
  const [secrets, setSecrets] = useState(null)
  const query = useQuery({ queryKey: [...DEVELOPER, 'apps', appId], queryFn: () => api.get(`developer/apps/${appId}`) })
  const profile = useQuery({ queryKey: [...DEVELOPER, 'profile'], queryFn: () => api.get('developer') })
  const invalidate = () => queryClient.invalidateQueries({ queryKey: DEVELOPER })
  const action = useMutation({
    mutationFn: (path) => api.post(`developer/apps/${appId}/${path}`),
    onSuccess: async (response) => {
      await invalidate()
      if (response?.secrets) setSecrets(response.secrets)
    },
  })
  const app = query.data?.data
  const catalogue = profile.data?.data?.catalogue

  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  if (!app || !catalogue) return <p className="text-ink-muted">{t('common.loading')}</p>
  const draft = app.versions.find((version) => version.status === 'draft')
  const submitted = app.versions.find((version) => version.status === 'submitted')
  const columns = [
    { key: 'version', label: t('developerApps.fields.version'), render: (row) => <span className="tabular-nums">{row.version}</span> },
    { key: 'status', label: t('developerApps.fields.status'), render: (row) => <StatusBadge tone={VERSION_TONES[row.status]}>{t(`developerApps.versionStatus.${row.status}`)}</StatusBadge> },
    { key: 'scopes', label: t('developerApps.fields.scopes'), numeric: true, render: (row) => row.scopes.length },
    { key: 'submitted', label: t('developerApps.fields.submitted'), render: (row) => (row.submitted_at ? formatWhen(row.submitted_at, locale) : '') },
    { key: 'note', label: t('developerApps.fields.reviewNote'), wrap: true, render: (row) => row.review_note ?? '' },
  ]

  return (
    <>
      <PageHeader
        title={app.name}
        description={t('developerApps.clientIdIs', { id: app.client_id })}
        actions={
          <>
            {!draft && !submitted ? <Button onClick={() => action.mutate('versions')}>{t('developerApps.actions.newVersion')}</Button> : null}
            {submitted ? <Button onClick={() => action.mutate('withdraw')}>{t('developerApps.actions.withdraw')}</Button> : null}
            {app.status === 'archived' ? <Button onClick={() => action.mutate('restore')}>{t('developerApps.actions.restore')}</Button> : null}
            {app.status === 'active' ? (
              <Button variant="ghost" onClick={() => action.mutate('archive')}>
                {t('developerApps.actions.archive')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {action.error ? <Alert tone="danger" title={errorMessage(action.error)} /> : null}
        {app.status === 'suspended' ? <Alert tone="danger" title={t('developerApps.suspendedTitle')}>{app.suspended_reason}</Alert> : null}
        {submitted ? <Alert tone="info" title={t('developerApps.inReview', { version: submitted.version })} /> : null}
        {draft ? <DraftEditor key={draft.id} app={app} draft={draft} catalogue={catalogue} /> : null}
        <DataTable columns={columns} rows={app.versions} caption={t('developerApps.versions')} emptyText="" />
        <Card title={t('developerApps.credentials.title')} subtitle={t('developerApps.credentials.text')}>
          <div className="flex flex-wrap gap-3">
            <Button onClick={() => action.mutate('client-secret')}>{t('developerApps.credentials.rotateClient')}</Button>
            <Button onClick={() => action.mutate('webhook-secret')}>{t('developerApps.credentials.rotateWebhook')}</Button>
          </div>
        </Card>
      </div>
      <SecretsDialog secrets={secrets} onClose={() => setSecrets(null)} />
    </>
  )
}
