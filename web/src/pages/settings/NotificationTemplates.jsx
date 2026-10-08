import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Select, Switch, Tabs, TextField } from '@/components/ds'
import { Field } from '@/components/ds/Field'
import { Textarea } from '@/components/ui/textarea'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { cn } from '@/lib/utils'

const TEMPLATES_KEY = ['notification-templates']
const EVENT_TYPES_KEY = ['notification-event-types']
const LOCALES = ['en', 'fr']
/** Channels whose text is a short message without a subject. */
const SHORT = ['sms', 'whatsapp']
/** How long typing must pause before the preview asks the API again. */
const PREVIEW_DELAY_MS = 400

/** Replaces one event type's entry in the templates list with the API's answer. */
function replaceType(list, entry) {
  if (!list?.data || !entry) return list
  return { ...list, data: list.data.map((type) => (type.event_type === entry.event_type ? entry : type)) }
}

const firstError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/**
 * One text (event type, channel, language): subject and body with
 * placeholder chips that insert `{placeholder}` at the cursor, a live
 * preview with sample values, save, and reset to the default with the
 * confirmation in the page (NOT-03).
 */
function TemplateEditor({ type, channel, locale, canEdit }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const bodyId = useId()
  const template = type.templates.find((entry) => entry.channel === channel && entry.locale === locale) ?? { subject: '', body: '', source: 'default' }
  const [subject, setSubject] = useState(template.subject ?? '')
  const [body, setBody] = useState(template.body ?? '')
  const [confirmReset, setConfirmReset] = useState(false)
  const subjectRef = useRef(null)
  const bodyRef = useRef(null)
  const lastField = useRef('body')
  const short = SHORT.includes(channel)
  const dirty = subject !== (template.subject ?? '') || body !== (template.body ?? '')

  // Preview: once typing pauses, the text as it stands, with the event's sample values.
  const settled = useDebounced(JSON.stringify({ subject: short ? null : subject, body }), PREVIEW_DELAY_MS)
  const preview = useQuery({
    queryKey: [...TEMPLATES_KEY, 'preview', type.event_type, channel, locale, settled],
    queryFn: () => api.post('notification-templates/preview', { event_type: type.event_type, channel, locale, ...JSON.parse(settled) }),
    placeholderData: keepPreviousData,
    retry: false,
  })

  const save = useMutation({
    mutationFn: () =>
      api.put('notification-templates', { event_type: type.event_type, channel, locale, subject: short ? null : subject.trim() || null, body }),
    onSuccess: (response) => {
      queryClient.setQueryData(TEMPLATES_KEY, (list) => replaceType(list, response?.data))
      toast.success(t('notificationTemplates.saved'))
    },
  })

  const reset = useMutation({
    mutationFn: () => api.post('notification-templates/reset', { event_type: type.event_type, channel, locale }),
    onSuccess: (response) => {
      queryClient.setQueryData(TEMPLATES_KEY, (list) => replaceType(list, response?.data))
      const next = response?.data?.templates?.find((entry) => entry.channel === channel && entry.locale === locale)
      setSubject(next?.subject ?? '')
      setBody(next?.body ?? '')
      setConfirmReset(false)
      save.reset()
      toast.success(t('notificationTemplates.resetDone'))
    },
  })

  /** Puts `{name}` where the cursor was in the last field used (the body by default). */
  const insert = (name) => {
    const token = `{${name}}`
    const target = !short && lastField.current === 'subject' ? 'subject' : 'body'
    const element = target === 'subject' ? subjectRef.current : bodyRef.current
    const value = target === 'subject' ? subject : body
    const start = element?.selectionStart ?? value.length
    const end = element?.selectionEnd ?? start
    const next = value.slice(0, start) + token + value.slice(end)
    if (target === 'subject') setSubject(next)
    else setBody(next)
    const caret = start + token.length
    requestAnimationFrame(() => {
      element?.focus()
      element?.setSelectionRange?.(caret, caret)
    })
  }

  // Field errors: the save's, else the preview's (an unknown placeholder, NOT-03).
  const subjectError = firstError(save.error, 'subject') ?? firstError(preview.error, 'subject')
  const bodyError = firstError(save.error, 'body') ?? firstError(preview.error, 'body')
  const saveFailure = save.error && !firstError(save.error, 'subject') && !firstError(save.error, 'body') ? errorMessage(save.error) : null
  const shown = preview.data?.data

  return (
    <div className="flex flex-col gap-5">
      <p className="text-caption text-ink-muted">{t(`notificationTemplates.sources.${template.source ?? 'default'}`)}</p>
      {saveFailure ? <Alert tone="danger" title={saveFailure} /> : null}

      {!short ? (
        <TextField
          ref={subjectRef}
          label={t('notificationTemplates.subject')}
          value={subject}
          readOnly={!canEdit}
          error={subjectError}
          maxLength={255}
          onFocus={() => {
            lastField.current = 'subject'
          }}
          onChange={(event) => setSubject(event.target.value)}
        />
      ) : null}

      <Field id={bodyId} label={t('notificationTemplates.body')} error={bodyError}>
        <Textarea
          ref={bodyRef}
          id={bodyId}
          value={body}
          readOnly={!canEdit}
          maxLength={5000}
          aria-invalid={bodyError ? 'true' : undefined}
          aria-describedby={bodyError ? `${bodyId}-msg` : undefined}
          onFocus={() => {
            lastField.current = 'body'
          }}
          onChange={(event) => setBody(event.target.value)}
          className={cn(
            'min-h-textbox rounded-md border-border-strong bg-surface-200 px-3 py-2 text-body text-ink placeholder:text-ink-muted hover:border-ink-muted',
            'focus-visible:border-focus focus-visible:ring-0 focus-visible:outline-2 focus-visible:outline-solid focus-visible:outline-offset-1 focus-visible:outline-focus',
            'aria-invalid:border-danger aria-invalid:ring-0 read-only:bg-surface-300 md:text-body dark:bg-surface-200',
          )}
        />
      </Field>

      {canEdit && type.placeholders?.length ? (
        <div className="flex flex-col gap-2">
          <h4 className="text-label text-ink">{t('notificationTemplates.placeholders')}</h4>
          <p className="text-caption text-ink-muted">{t('notificationTemplates.placeholdersHelp')}</p>
          <ul className="flex flex-wrap gap-2">
            {type.placeholders.map((placeholder) => (
              <li key={placeholder.name}>
                <button
                  type="button"
                  // Keeps the cursor in the field the user was typing in.
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => insert(placeholder.name)}
                  aria-label={t('notificationTemplates.insert', { placeholder: `{${placeholder.name}}` })}
                  title={placeholder.sample ? t('notificationTemplates.sample', { sample: placeholder.sample }) : undefined}
                  className="rounded-md border border-border bg-surface-100 px-2 py-1 font-mono text-caption text-ink transition-colors hover:bg-surface-300"
                >
                  {`{${placeholder.name}}`}
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      <section aria-labelledby={`${bodyId}-preview`} className="flex flex-col gap-2 rounded-md border border-border bg-surface-100 p-4">
        <h4 id={`${bodyId}-preview`} className="text-label text-ink-muted">
          {t('notificationTemplates.preview')}
        </h4>
        {preview.isError && !shown ? (
          <p className="text-ink-muted">{t('notificationTemplates.previewUnavailable')}</p>
        ) : !shown ? (
          <p className="text-ink-muted">{t('common.loading')}</p>
        ) : (
          <div data-testid="template-preview" className={cn('flex flex-col gap-2', preview.isError && 'opacity-40')}>
            {!short && shown.subject ? <p className="font-medium text-ink">{shown.subject}</p> : null}
            <p className="whitespace-pre-wrap text-ink">{shown.body}</p>
          </div>
        )}
        {preview.isError && shown ? <p className="text-caption text-ink-muted">{t('notificationTemplates.previewStale')}</p> : null}
      </section>

      {confirmReset ? (
        <Alert
          tone="warning"
          title={t('notificationTemplates.resetTitle')}
          action={
            <div className="flex flex-wrap gap-2">
              <Button variant="ghost" onClick={() => setConfirmReset(false)}>
                {t('notificationTemplates.keepText')}
              </Button>
              <Button variant="danger" loading={reset.isPending} onClick={() => reset.mutate()}>
                {t('notificationTemplates.resetConfirm')}
              </Button>
            </div>
          }
        >
          {t('notificationTemplates.resetText')}
        </Alert>
      ) : null}
      {reset.isError ? <Alert tone="danger" title={errorMessage(reset.error)} /> : null}

      {canEdit ? (
        <div className="flex flex-wrap justify-end gap-2">
          {template.overridden && !confirmReset ? <Button onClick={() => setConfirmReset(true)}>{t('notificationTemplates.reset')}</Button> : null}
          <Button variant="primary" disabled={!dirty || !body.trim()} loading={save.isPending} onClick={() => save.mutate()}>
            {t('notificationTemplates.save')}
          </Button>
        </div>
      ) : null}
    </div>
  )
}

/**
 * The channels users of the organisation must keep on for one event type
 * (NOT-04); each switch saves at once. Only for admins with
 * core.notification_settings.edit.
 */
function MandatoryChannels({ eventType }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const update = useMutation({
    mutationFn: (channels) => api.put('notification-settings', { event_types: [{ event_type: eventType.event_type, mandatory_channels: channels }] }),
    onSuccess: (response) => {
      queryClient.setQueryData(EVENT_TYPES_KEY, response)
      queryClient.invalidateQueries({ queryKey: ['me', 'notification-preferences'] })
      toast.success(t('notificationTemplates.mandatory.saved'))
    },
  })
  const current = eventType.mandatory_channels ?? []

  return (
    <Card title={t('notificationTemplates.mandatory.title')} subtitle={t('notificationTemplates.mandatory.description')}>
      {update.isError ? <Alert tone="danger" title={errorMessage(update.error)} className="mb-4" /> : null}
      {!eventType.mandatory_allowed ? (
        <p className="text-ink-muted">{t('notificationTemplates.mandatory.notAllowed')}</p>
      ) : (
        <ul className="flex flex-col gap-3">
          {eventType.channels.map((channel) => (
            <li key={channel.channel}>
              <Switch
                label={channel.label}
                checked={current.includes(channel.channel)}
                disabled={update.isPending}
                onChange={(on) => update.mutate(on ? [...current, channel.channel] : current.filter((entry) => entry !== channel.channel))}
              />
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

/**
 * NOT-03, NOT-04: the organisation's notification texts. Pick an event
 * type, a language and a channel (or all channels); edit, preview, save or
 * reset its text; and, with core.notification_settings.edit, choose the
 * channels users must keep on.
 */
export default function NotificationTemplates() {
  const { t } = useTranslation()
  const permissions = usePermissions()
  const canEdit = permissions.tenantWide('core.notification_template.edit')
  const canSetMandatory = permissions.tenantWide('core.notification_settings.edit')
  const [params, setParams] = useSearchParams()
  const [locale, setLocale] = useState('en')
  const [channel, setChannel] = useState('all')

  const templates = useQuery({ queryKey: TEMPLATES_KEY, queryFn: () => api.get('notification-templates') })
  const eventTypes = useQuery({ queryKey: EVENT_TYPES_KEY, queryFn: () => api.get('notification-event-types'), enabled: canSetMandatory })
  const types = templates.data?.data ?? []
  const selected = types.find((type) => type.event_type === params.get('event')) ?? types[0]
  const settings = eventTypes.data?.data?.find((type) => type.event_type === selected?.event_type)
  const channels = selected ? ['all', ...selected.channels] : []
  const currentChannel = channels.includes(channel) ? channel : 'all'

  const choose = (eventType) => {
    setChannel('all')
    setParams({ event: eventType }, { replace: true })
  }

  return (
    <>
      <PageHeader title={t('notificationTemplates.title')} description={t('notificationTemplates.description')} />
      {templates.isError ? (
        <Alert tone="danger" title={errorMessage(templates.error)} action={<Button onClick={() => templates.refetch()}>{t('common.retry')}</Button>} />
      ) : null}
      {templates.isPending ? (
        <p className="text-ink-muted">{t('common.loading')}</p>
      ) : !selected ? (
        templates.isError ? null : <p className="text-ink-muted">{t('notificationTemplates.empty')}</p>
      ) : (
        <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
          <nav aria-label={t('notificationTemplates.eventTypes')} className="lg:w-sidebar lg:shrink-0">
            <ul className="flex flex-col gap-px">
              {types.map((type) => {
                const active = type.event_type === selected.event_type
                return (
                  <li key={type.event_type}>
                    <button
                      type="button"
                      aria-current={active ? 'true' : undefined}
                      onClick={() => choose(type.event_type)}
                      className={cn(
                        'flex w-full items-center rounded-md px-3 py-2 text-left text-body text-ink-muted transition-colors hover:bg-surface-300 hover:text-ink',
                        active && 'bg-surface-300 font-medium text-ink',
                      )}
                    >
                      {type.label}
                    </button>
                  </li>
                )
              })}
            </ul>
          </nav>
          <div className="flex min-w-0 flex-1 flex-col gap-6">
            <Card title={selected.label}>
              <div className="flex flex-col gap-5">
                <Tabs items={LOCALES.map((value) => ({ value, label: t(`notificationTemplates.locales.${value}`) }))} value={locale} onChange={setLocale} />
                <Select
                  label={t('notificationTemplates.channel')}
                  className="max-w-field"
                  options={channels.map((value) => ({ value, label: t(`notificationDeliveries.channels.${value}`) }))}
                  value={currentChannel}
                  onChange={(event) => setChannel(event.target.value)}
                />
                <TemplateEditor
                  key={`${selected.event_type}|${currentChannel}|${locale}`}
                  type={selected}
                  channel={currentChannel}
                  locale={locale}
                  canEdit={canEdit}
                />
              </div>
            </Card>
            {canSetMandatory && settings ? <MandatoryChannels eventType={settings} /> : null}
          </div>
        </div>
      )}
    </>
  )
}
