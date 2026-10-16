import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Dialog, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatWhen } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { DeliveryLog } from './DeliveryLog'
import { SecretReveal } from './SecretReveal'
import { WebhookFormDialog } from './WebhookFormDialog'
import { CATALOGUE_KEY, DELIVERIES_KEY, eventLabel, subscriptionState, WEBHOOKS_KEY } from './webhookShared'

function Fact({ label, children }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="min-w-0 text-body break-words text-ink">{children}</dd>
    </div>
  )
}

/** A yes-or-cancel dialog for a consequential action (rotate, archive). */
function Confirm({ open, title, body, action, variant = 'danger', loading, error, onConfirm, onClose }) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant={variant} loading={loading} onClick={onConfirm}>
            {action}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3 pt-1">
        {error ? <Alert tone="danger" title={error} /> : null}
        <p className="text-body text-ink">{body}</p>
      </div>
    </Dialog>
  )
}

/**
 * HOOK-01: one webhook: what it sends and where, switching it on or off,
 * editing it, sending a test event, rotating its signing secret (the new
 * one is shown once; the old one keeps signing for the overlap), archiving
 * it, and its own delivery log.
 */
export default function WebhookDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { webhookId } = useParams()
  const [editing, setEditing] = useState(false)
  const [rotating, setRotating] = useState(false)
  const [archiving, setArchiving] = useState(false)
  const [secret, setSecret] = useState(null)
  const query = useQuery({ queryKey: [...WEBHOOKS_KEY, webhookId], queryFn: () => api.get(`webhooks/${webhookId}`) })
  const catalogue = useQuery({ queryKey: CATALOGUE_KEY, queryFn: () => api.get('webhooks/catalogue') })
  const companies = useQuery({ queryKey: ['companies', 'webhooks'], queryFn: () => api.get('companies?per_page=200') })
  const branches = useQuery({ queryKey: ['branches', 'webhooks'], queryFn: () => api.get('branches?per_page=200') })
  const webhook = query.data?.data
  const meta = catalogue.data?.meta ?? {}
  const refresh = () => queryClient.invalidateQueries({ queryKey: WEBHOOKS_KEY })

  const toggle = useMutation({ mutationFn: (active) => api.patch(`webhooks/${webhookId}`, { active }), onSuccess: refresh })
  const test = useMutation({
    mutationFn: () => api.post(`webhooks/${webhookId}/test`),
    onSuccess: async () => {
      toast.success(t('webhooks.detail.testSent'))
      await queryClient.invalidateQueries({ queryKey: DELIVERIES_KEY })
    },
  })
  const rotate = useMutation({
    mutationFn: () => api.post(`webhooks/${webhookId}/rotate-secret`),
    onSuccess: async (response) => {
      setSecret(response.data.secret)
      setRotating(false)
      await refresh()
    },
  })
  const archive = useMutation({
    mutationFn: () => api.post(`webhooks/${webhookId}/archive`),
    onSuccess: async () => {
      await refresh()
      navigate('/settings/webhooks')
    },
  })

  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <div className="flex flex-col gap-4">
        <Alert tone="danger" title={query.error?.status === 404 ? t('webhooks.detail.notFound') : errorMessage(query.error)} />
        <Link to="/settings/webhooks" className="text-body text-primary hover:underline">
          {t('webhooks.detail.back')}
        </Link>
      </div>
    )
  }

  const state = subscriptionState(webhook)
  const companyName = (companies.data?.data ?? []).find((company) => company.id === webhook.company_id)?.name
  const branchName = (branches.data?.data ?? []).find((branch) => branch.id === webhook.branch_id)?.name
  const filter = webhook.branch_id ? branchName ?? t('webhooks.fields.filterBranch') : webhook.company_id ? companyName ?? t('webhooks.fields.filterCompany') : t('webhooks.fields.filterAll')
  const failure = toggle.error ?? test.error

  return (
    <>
      <PageHeader
        eyebrow={
          <Link to="/settings/webhooks" className="hover:underline">
            {t('webhooks.detail.back')}
          </Link>
        }
        title={webhook.description || t('webhooks.title')}
        description={<span className="break-all">{webhook.url_display}</span>}
        actions={
          <>
            <Button icon="edit" onClick={() => setEditing(true)}>
              {t('webhooks.detail.edit')}
            </Button>
            <Button icon="mail" loading={test.isPending} onClick={() => test.mutate()}>
              {t('webhooks.detail.test')}
            </Button>
            <Button variant="ghost" icon="archive" onClick={() => setArchiving(true)}>
              {t('webhooks.detail.archive')}
            </Button>
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {failure ? <Alert tone="danger" title={errorMessage(failure)} /> : null}
        {webhook.disabled_reason === 'failing' && !webhook.active ? (
          <Alert tone="danger" title={t('webhooks.detail.disabledTitle', { count: webhook.consecutive_failures })}>
            {t('webhooks.detail.disabledBody')}
          </Alert>
        ) : null}
        {!webhook.principal.active ? <Alert tone="warning" title={t('webhooks.detail.principalInactive')} /> : null}
        {secret ? (
          <Card title={t('webhooks.secret.title')}>
            <SecretReveal secret={secret} />
          </Card>
        ) : null}

        <Card
          title={t('webhooks.detail.settings')}
          actions={
            <Button loading={toggle.isPending} onClick={() => toggle.mutate(!webhook.active)}>
              {webhook.active ? t('webhooks.detail.switchOff') : t('webhooks.detail.switchOn')}
            </Button>
          }
        >
          <dl className="grid gap-4 sm:grid-cols-2">
            <Fact label={t('webhooks.deliveries.columns.status')}>
              <StatusBadge tone={state.tone}>{t(`webhooks.status.${state.key}`)}</StatusBadge>
            </Fact>
            <Fact label={t('webhooks.detail.sendsAs')}>{webhook.principal.name}</Fact>
            <Fact label={t('webhooks.detail.filter')}>{filter}</Fact>
            <Fact label={t('webhooks.secret.title')}>
              <span className="flex flex-col gap-1">
                {webhook.secret_rotated_at ? <span>{t('webhooks.secret.rotatedAt', { when: formatWhen(webhook.secret_rotated_at, locale) })}</span> : null}
                {webhook.previous_secret_expires_at ? (
                  <span className="text-caption text-ink-muted">{t('webhooks.secret.overlapUntil', { when: formatWhen(webhook.previous_secret_expires_at, locale) })}</span>
                ) : null}
                <Button icon="key" className="mt-1 self-start" onClick={() => setRotating(true)}>
                  {t('webhooks.secret.rotate')}
                </Button>
              </span>
            </Fact>
            <div className="sm:col-span-2">
              <Fact label={t('webhooks.detail.events')}>
                <ul className="flex flex-wrap gap-x-4 gap-y-1">
                  {webhook.events.map((type) => (
                    <li key={type}>{eventLabel(t, type)}</li>
                  ))}
                </ul>
              </Fact>
            </div>
          </dl>
        </Card>

        <Card title={t('webhooks.deliveries.title')}>
          <DeliveryLog webhookId={webhook.id} retentionDays={meta.retention_days} />
        </Card>
      </div>

      {editing ? <WebhookFormDialog open webhook={webhook} onClose={() => setEditing(false)} /> : null}
      <Confirm
        open={rotating}
        title={t('webhooks.secret.rotateTitle')}
        body={t('webhooks.secret.rotateBody', { hours: meta.rotation_overlap_hours ?? 24 })}
        action={t('webhooks.secret.rotateNow')}
        loading={rotate.isPending}
        error={rotate.error ? errorMessage(rotate.error) : null}
        onConfirm={() => rotate.mutate()}
        onClose={() => setRotating(false)}
      />
      <Confirm
        open={archiving}
        title={t('webhooks.detail.archiveTitle')}
        body={t('webhooks.detail.archiveBody')}
        action={t('webhooks.detail.archiveNow')}
        loading={archive.isPending}
        error={archive.error ? errorMessage(archive.error) : null}
        onConfirm={() => archive.mutate()}
        onClose={() => setArchiving(false)}
      />
    </>
  )
}
