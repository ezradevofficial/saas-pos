import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, Checkbox, DataTable, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { CATEGORIES, DEVELOPER, VERSION_TONES } from '@/pages/marketplace/marketplaceData'
import { SecretValue, TextArea } from '@/pages/marketplace/marketplaceParts'

/** Secrets shown once after creating an app or rotating one. */
export function SecretsDialog({ secrets, clientId, onClose }) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={Boolean(secrets)}
      title={t('developerApps.secrets.title')}
      onClose={onClose}
      footer={
        <Button variant="primary" onClick={onClose}>
          {t('developerApps.secrets.done')}
        </Button>
      }
    >
      <div className="flex flex-col gap-4">
        <Alert tone="warning" title={t('developerApps.secrets.once')} />
        {clientId ? <SecretValue label={t('developerApps.fields.clientId')} value={clientId} testId="client-id" /> : null}
        {secrets?.client_secret ? <SecretValue label={t('developerApps.fields.clientSecret')} value={secrets.client_secret} testId="client-secret" /> : null}
        {secrets?.webhook_secret ? <SecretValue label={t('developerApps.fields.webhookSecret')} value={secrets.webhook_secret} testId="webhook-secret" /> : null}
      </div>
    </Dialog>
  )
}

function RegisterCard({ developer, share }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [name, setName] = useState(developer?.name ?? '')
  const [email, setEmail] = useState(developer?.email ?? '')
  const [website, setWebsite] = useState(developer?.website ?? '')
  const [terms, setTerms] = useState(false)
  const save = useMutation({
    mutationFn: () => api.put('developer', { name: name.trim(), email: email.trim(), website: website.trim() || null, accept_terms: developer ? undefined : terms }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: DEVELOPER }),
  })
  const errors = formErrors(save.error, ['name', 'email', 'website', 'accept_terms'])

  return (
    <Card title={developer ? t('developerApps.profile.title') : t('developerApps.register.title')} subtitle={developer ? null : t('developerApps.register.text')}>
      <form
        noValidate
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault()
          save.mutate()
        }}
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-3">
          <TextField label={t('developerApps.profile.name')} help={t('developerApps.profile.nameHelp')} value={name} onChange={(event) => setName(event.target.value)} error={errors.fields.name} required />
          <TextField label={t('developerApps.profile.email')} type="email" value={email} onChange={(event) => setEmail(event.target.value)} error={errors.fields.email} required />
          <TextField label={t('developerApps.profile.website')} type="url" value={website} onChange={(event) => setWebsite(event.target.value)} error={errors.fields.website} />
        </div>
        {developer ? null : (
          <Checkbox label={t('developerApps.register.terms', { share })} checked={terms} onChange={(event) => setTerms(event.target.checked)} />
        )}
        {errors.fields.accept_terms ? <p className="text-caption text-danger">{errors.fields.accept_terms}</p> : null}
        <div>
          <Button type="submit" variant="primary" loading={save.isPending} disabled={!name.trim() || !email.trim() || (!developer && !terms)}>
            {developer ? t('developerApps.profile.save') : t('developerApps.register.submit')}
          </Button>
        </div>
      </form>
    </Card>
  )
}

function CreateAppDialog({ open, onClose, onCreated }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [slug, setSlug] = useState('')
  const [name, setName] = useState('')
  const [category, setCategory] = useState('productivity')
  const [description, setDescription] = useState('')
  const create = useMutation({
    mutationFn: () => api.post('developer/apps', { slug: slug.trim(), name: name.trim(), category, description: description.trim(), pricing: { model: 'free' } }),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: DEVELOPER })
      onCreated(response)
    },
  })
  const errors = formErrors(create.error, ['slug', 'name', 'category', 'description'])

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('developerApps.create.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={create.isPending} disabled={!slug.trim() || !name.trim() || !description.trim()} onClick={() => create.mutate()}>
            {t('developerApps.create.submit')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label={t('developerApps.fields.name')} value={name} onChange={(event) => setName(event.target.value)} error={errors.fields.name} required />
          <TextField label={t('developerApps.fields.slug')} help={t('developerApps.fields.slugHelp')} value={slug} onChange={(event) => setSlug(event.target.value.toLowerCase())} error={errors.fields.slug} required />
        </div>
        <Select
          label={t('developerApps.fields.category')}
          options={CATEGORIES.map((key) => ({ value: key, label: t(`marketplace.categories.${key}`) }))}
          value={category}
          onChange={(event) => setCategory(event.target.value)}
          className="max-w-field"
        />
        <TextArea label={t('developerApps.fields.description')} help={t('developerApps.fields.descriptionHelp')} value={description} onChange={(event) => setDescription(event.target.value)} error={errors.fields.description} />
      </div>
    </Dialog>
  )
}

/**
 * MKT-01: the developer portal in the app (core.developer.manage): the
 * organisation's developer account (once, with the terms and the revenue
 * share), its apps and their latest version's review state.
 */
export default function DeveloperApps() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)
  const [created, setCreated] = useState(null)
  const profile = useQuery({ queryKey: [...DEVELOPER, 'profile'], queryFn: () => api.get('developer') })
  const apps = useQuery({ queryKey: [...DEVELOPER, 'apps'], queryFn: () => api.get('developer/apps'), enabled: Boolean(profile.data?.data?.developer) })
  const developer = profile.data?.data?.developer
  const columns = [
    {
      key: 'name',
      label: t('developerApps.fields.name'),
      render: (row) => (
        <div className="flex flex-col">
          <span className="font-medium text-ink">{row.name}</span>
          <code className="font-mono text-caption text-ink-muted">{row.slug}</code>
        </div>
      ),
    },
    { key: 'listed', label: t('developerApps.fields.listing'), render: (row) => (row.status === 'suspended' ? <StatusBadge tone="danger">{t('developerApps.appStatus.suspended')}</StatusBadge> : row.status === 'archived' ? <StatusBadge tone="neutral">{t('developerApps.appStatus.archived')}</StatusBadge> : row.listed_version_id ? <StatusBadge tone="success">{t('developerApps.appStatus.listed')}</StatusBadge> : <StatusBadge tone="neutral">{t('developerApps.appStatus.unlisted')}</StatusBadge>) },
    { key: 'version', label: t('developerApps.fields.latest'), render: (row) => (row.versions[0] ? <StatusBadge tone={VERSION_TONES[row.versions[0].status]}>{t('developerApps.version', { version: row.versions[0].version, status: t(`developerApps.versionStatus.${row.versions[0].status}`) })}</StatusBadge> : null) },
    { key: 'created_at', label: t('developerApps.fields.created'), render: (row) => formatWhen(row.created_at, locale) },
  ]

  return (
    <>
      <PageHeader
        title={t('developerApps.title')}
        description={t('developerApps.description')}
        actions={
          <>
            <Link to="/developers" className="flex h-control items-center px-3 text-label text-primary hover:text-primary-hover">
              {t('developerApps.portal')}
            </Link>
            {developer ? (
              <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
                {t('developerApps.create.action')}
              </Button>
            ) : null}
          </>
        }
      />
      {profile.isError ? <Alert tone="danger" title={errorMessage(profile.error)} /> : null}
      <div className="flex flex-col gap-5">
        {profile.data ? <RegisterCard key={developer?.id ?? 'new'} developer={developer} share={profile.data.data.catalogue.share_percent} /> : null}
        {developer ? (
          <DataTable
            columns={columns}
            rows={apps.data?.data ?? []}
            loading={apps.isPending}
            caption={t('developerApps.title')}
            emptyText={t('developerApps.empty')}
            onRowClick={(row) => navigate(`/settings/developer-apps/${row.id}`)}
          />
        ) : null}
      </div>
      <CreateAppDialog
        open={creating}
        onClose={() => setCreating(false)}
        onCreated={(response) => {
          setCreating(false)
          setCreated(response)
        }}
      />
      <SecretsDialog
        secrets={created?.secrets}
        clientId={created?.data?.client_id}
        onClose={() => {
          const id = created?.data?.id
          setCreated(null)
          if (id) navigate(`/settings/developer-apps/${id}`)
        }}
      />
    </>
  )
}
