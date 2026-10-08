import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Select, Switch } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'

const PREFERENCES_KEY = ['me', 'notification-preferences']
const DIGESTS = ['immediate', 'daily', 'weekly']

/** The channels a provider must be set up for are hidden until one is (NOT-01); `available` comes from the event types. */
function availability(eventTypes) {
  const map = new Map()
  for (const type of eventTypes ?? []) {
    for (const channel of type.channels ?? []) map.set(`${type.event_type}|${channel.channel}`, channel.available !== false)
  }
  return map
}

/** The user's choices per event type: `{ [event_type]: { channels: {channel: bool}, digest } }`. */
function choicesOf(rows) {
  return Object.fromEntries(
    rows.map((row) => [row.event_type, { channels: Object.fromEntries(row.channels.map((c) => [c.channel, Boolean(c.enabled)])), digest: row.digest }]),
  )
}

/**
 * The PUT body: every event type with its switchable channels (mandatory
 * ones are left out: the API keeps them on) and, when email may wait, the
 * email timing (NOT-04, NOT-05).
 */
function preferencesPayload(rows, choices) {
  return {
    preferences: rows.map((row) => {
      const choice = choices[row.event_type]
      const entry = {
        event_type: row.event_type,
        channels: Object.fromEntries(row.channels.filter((c) => !c.mandatory).map((c) => [c.channel, Boolean(choice.channels[c.channel])])),
      }
      if (row.digest_allowed) entry.digest = choice.digest
      return entry
    }),
  }
}

function EventTypeRow({ row, choice, available, onChange }) {
  const { t } = useTranslation()
  const channels = row.channels.filter((channel) => channel.mandatory || available(row.event_type, channel.channel))
  const hasEmail = channels.some((channel) => channel.channel === 'email')
  const emailOn = Boolean(choice.channels.email)

  return (
    <li className="flex flex-col gap-4 border-b border-border py-4 first:pt-0 last:border-b-0 last:pb-0 md:flex-row md:items-start md:justify-between">
      <div className="min-w-0 md:flex-1">
        <h3 className="text-body font-medium text-ink">{row.label}</h3>
      </div>
      <div className="flex flex-col gap-3 md:flex-1">
        <ul aria-label={t('notificationPreferences.channelsFor', { event: row.label })} className="flex flex-col gap-3">
          {channels.map((channel) => (
            <li key={channel.channel} className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <Switch
                label={channel.label}
                checked={channel.mandatory ? true : choice.channels[channel.channel]}
                disabled={channel.mandatory}
                onChange={(next) => onChange({ ...choice, channels: { ...choice.channels, [channel.channel]: next } })}
              />
              {channel.mandatory ? <span className="text-caption text-ink-muted">{t('notificationPreferences.required')}</span> : null}
            </li>
          ))}
        </ul>
        {hasEmail ? (
          <Select
            label={t('notificationPreferences.emailTiming', { event: row.label })}
            className="max-w-field"
            options={DIGESTS.map((value) => ({ value, label: t(`notificationPreferences.digests.${value}`) }))}
            value={row.digest_allowed ? choice.digest : 'immediate'}
            disabled={!row.digest_allowed || !emailOn}
            help={!row.digest_allowed ? t('notificationPreferences.digestRequired') : undefined}
            onChange={(event) => onChange({ ...choice, digest: event.target.value })}
          />
        ) : null}
      </div>
    </li>
  )
}

/**
 * NOT-04, NOT-05: the signed-in user's own notification settings. Per
 * event type, each channel on or off (channels the organisation requires
 * stay on) and whether email comes at once or in a daily or weekly digest.
 */
export default function NotificationPreferences() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const preferences = useQuery({ queryKey: PREFERENCES_KEY, queryFn: () => api.get('me/notification-preferences') })
  const eventTypes = useQuery({ queryKey: ['notification-event-types'], queryFn: () => api.get('notification-event-types') })
  const rows = preferences.data?.data ?? []
  const [draft, setDraft] = useState(null)
  const saved = choicesOf(rows)
  const choices = draft ?? saved
  const dirty = draft !== null && JSON.stringify(draft) !== JSON.stringify(saved)

  const known = availability(eventTypes.data?.data)
  // Until the event types load, every channel the API lists shows.
  const available = (eventType, channel) => known.get(`${eventType}|${channel}`) ?? true

  const save = useMutation({
    mutationFn: () => api.put('me/notification-preferences', preferencesPayload(rows, choices)),
    onSuccess: (response) => {
      queryClient.setQueryData(PREFERENCES_KEY, response)
      setDraft(null)
      toast.success(t('notificationPreferences.saved'))
    },
  })

  const change = (eventType, next) => setDraft({ ...choices, [eventType]: next })

  return (
    <>
      <PageHeader title={t('notificationPreferences.title')} description={t('notificationPreferences.description')} />
      {preferences.isError ? (
        <Alert tone="danger" title={errorMessage(preferences.error)} action={<Button onClick={() => preferences.refetch()}>{t('common.retry')}</Button>} />
      ) : null}
      {save.isError ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
      {preferences.isPending ? (
        <p className="text-ink-muted">{t('common.loading')}</p>
      ) : rows.length === 0 && !preferences.isError ? (
        <p className="text-ink-muted">{t('notificationPreferences.empty')}</p>
      ) : (
        <Card>
          <ul className="flex flex-col">
            {rows.map((row) => (
              <EventTypeRow
                key={row.event_type}
                row={row}
                choice={choices[row.event_type]}
                available={available}
                onChange={(next) => change(row.event_type, next)}
              />
            ))}
          </ul>
        </Card>
      )}
      {rows.length > 0 ? (
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="ghost" disabled={!dirty || save.isPending} onClick={() => setDraft(null)}>
            {t('notificationPreferences.discard')}
          </Button>
          <Button variant="primary" disabled={!dirty} loading={save.isPending} onClick={() => save.mutate()}>
            {t('common.save')}
          </Button>
        </div>
      ) : null}
    </>
  )
}
