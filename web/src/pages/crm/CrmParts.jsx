// Components the CRM screens share: the status word, instants in the
// record's company time zone, amounts per currency, the board (kanban),
// the customer picker, a reason dialog, and activities (list and dialog).
// Generic detail parts (amounts, back link, flow panel) are Inventory's,
// as Procurement uses them.
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Dialog, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { formatCompanyTime } from '@/lib/companyTime'
import { localDateTimeIn, zonedToUtc } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { cn } from '@/lib/utils'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { useCrmAction } from './crmHelpers'
import { ACTIVITY_KINDS, ACTIVITY_MANAGE, companyById, companyOfBranch, crmKey, fieldError, namedOptions, STATUS_TONES, statusLabel, useAssignees, usePlaces } from './crmData'

export { Amount, Back, CalendarDate, Detail, DetailState, DocumentFlow, ItemPicker } from '@/pages/inventory/InventoryParts'
export { TextAreaField }

/** A lead, opportunity, quote or activity status as a dot and a word. */
export function Status({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** CRM-27: an open activity past its time, as a red dot and a word. */
export function Overdue() {
  const { t } = useTranslation()
  return <StatusBadge tone="danger">{t('crm.activity.overdue')}</StatusBadge>
}

/** An instant in the record's company time zone, or a dash. */
export function When({ value, companyId }) {
  const locale = useLocale()
  const companyOf = useCompanyOfRecord()
  if (!value) return <span className="text-ink-muted">—</span>
  return <span className="tabular-nums">{formatCompanyTime(value, locale, companyOf(companyId))}</span>
}

/** Amounts per currency (`[{currency, <field>}]`), each with its code first; a dash for none. */
export function PerCurrency({ values, field = 'amount_minor', className }) {
  const shown = (values ?? []).filter((value) => value && value[field] !== null && value[field] !== undefined)
  if (!shown.length) return <span className="text-ink-muted">—</span>
  return (
    <span className={cn('flex flex-col', className)}>
      {shown.map((value) => (
        <Money key={value.currency} amount={value[field]} currency={value.currency} />
      ))}
    </span>
  )
}

/**
 * CRM-13, CRM-16: a board of cards in columns ({id, title, meta}). A card
 * moves by dragging it to another column, or with its move buttons (the
 * keyboard and screen-reader way); `onMove(card, columnId)` saves it.
 */
export function Board({ label, columns, cards, columnOf, cardLabel, renderCard, onMove, busyId }) {
  const { t } = useTranslation()
  const [dragged, setDragged] = useState(null)
  const [over, setOver] = useState(null)
  const drop = (columnId) => {
    const card = cards.find((entry) => entry.id === dragged)
    setDragged(null)
    setOver(null)
    if (card && columnOf(card) !== columnId) onMove(card, columnId)
  }
  return (
    <div role="region" aria-label={label} className="flex gap-4 overflow-x-auto pb-2">
      {columns.map((column, index) => {
        const inColumn = cards.filter((card) => columnOf(card) === column.id)
        const titleId = `board-${column.id}`
        return (
          // NFR-09: dragging is a shortcut; every card has move buttons for keyboard and screen reader users.
          // oxlint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
          <section
            key={column.id}
            aria-labelledby={titleId}
            data-column={column.id}
            onDragOver={(event) => {
              if (!dragged) return
              event.preventDefault()
              setOver(column.id)
            }}
            onDragLeave={() => setOver((current) => (current === column.id ? null : current))}
            onDrop={(event) => {
              event.preventDefault()
              drop(column.id)
            }}
            className={cn('flex w-lane shrink-0 flex-col gap-3 rounded-lg border border-border bg-surface-100 p-3', over === column.id && 'border-primary')}
          >
            <header className="flex flex-col gap-1">
              <div className="flex items-baseline justify-between gap-2">
                <h3 id={titleId} className="text-label text-ink">{column.title}</h3>
                <span className="text-caption text-ink-muted tabular-nums">{inColumn.length}</span>
              </div>
              {column.meta ? <div className="text-caption text-ink-muted">{column.meta}</div> : null}
            </header>
            {inColumn.length === 0 ? <p className="text-caption text-ink-muted">{t('crm.board.empty')}</p> : null}
            <ul className="flex flex-col gap-2">
              {inColumn.map((card) => (
                // oxlint-disable-next-line jsx-a11y/no-noninteractive-element-interactions
                <li
                  key={card.id}
                  draggable
                  aria-busy={busyId === card.id ? 'true' : undefined}
                  onDragStart={(event) => {
                    setDragged(card.id)
                    event.dataTransfer?.setData('text/plain', card.id)
                    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
                  }}
                  onDragEnd={() => {
                    setDragged(null)
                    setOver(null)
                  }}
                  className={cn('flex cursor-grab flex-col gap-2 rounded-md border border-border bg-surface-200 p-3', busyId === card.id && 'opacity-40')}
                >
                  {renderCard(card)}
                  <div className="flex justify-between gap-2">
                    {index > 0 ? (
                      <Button variant="ghost" icon="chevronLeft" aria-label={t('crm.board.moveTo', { name: cardLabel(card), column: columns[index - 1].title })} onClick={() => onMove(card, columns[index - 1].id)} />
                    ) : (
                      <span />
                    )}
                    {index < columns.length - 1 ? (
                      <Button variant="ghost" icon="chevronRight" aria-label={t('crm.board.moveTo', { name: cardLabel(card), column: columns[index + 1].title })} onClick={() => onMove(card, columns[index + 1].id)} />
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
          </section>
        )
      })}
    </div>
  )
}

/** MD-01: a customer (core party with the customer role), searched on the server. `value` is the party ({id, name}). */
export function CustomerPicker({ label, value, onChange, error, required, disabled, className }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['parties', 'customer', 'crm-picker', search],
    queryFn: () => api.get(`parties?role=customer&per_page=20${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    staleTime: 30_000,
    retry: false,
  })
  const rows = (query.data?.data ?? []).filter((party) => !party.archived_at)
  const known = value && !rows.some((party) => party.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label ?? t('crm.fields.customer')}
      placeholder={t('crm.pickers.customer')}
      options={namedOptions(known)}
      value={value?.id ?? ''}
      error={error}
      required={required}
      disabled={disabled}
      onSearchChange={onSearchChange}
      onChange={(event) => onChange(known.find((party) => party.id === event.target.value) ?? null)}
      className={className}
    />
  )
}

/**
 * A dialog asking one line of text (a reason, an outcome) and posting
 * `{[field]: text}` to `path`; `required` keeps the confirm button off
 * until something is typed. `onDone(response)` gets the API's answer.
 */
export function ReasonDialog({ title, help, label, field = 'reason', required = true, confirm, variant = 'primary', path, onClose, onDone }) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const save = useMutation({ mutationFn: () => api.post(path, text.trim() ? { [field]: text.trim() } : {}), onSuccess: onDone })
  return (
    <Dialog
      open
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('crm.actions.back')}
          </Button>
          <Button variant={variant} loading={save.isPending} disabled={required && !text.trim()} onClick={() => save.mutate()}>
            {confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {save.isError && !fieldError(save.error, field) ? <Alert tone="danger" title={errorMessage(save.error)} /> : null}
        <TextAreaField label={label} value={text} maxLength={1000} required={required} error={fieldError(save.error, field)} onChange={(event) => setText(event.target.value)} />
      </div>
    </Dialog>
  )
}

const ACTIVITY_FIELDS = ['branch_id', 'kind', 'subject', 'notes', 'starts_at', 'ends_at', 'remind_at', 'owner_id', 'party_id']

/**
 * CRM-26: a new activity (or an open one's changes): kind, subject, start,
 * end, reminder, owner and notes, at a branch, linked to `links` (lead,
 * opportunity, quote, customer) or, from the calendar, to a customer
 * picked here. Times are typed in the branch's company time zone.
 */
export function ActivityDialog({ activity, branchId: fixedBranch, links = {}, pickCustomer = false, start, onClose, onSaved }) {
  const { t } = useTranslation()
  const places = usePlaces()
  const editing = Boolean(activity)
  const [branch, setBranch] = useState(activity?.branch?.id ?? fixedBranch ?? '')
  const zone = companyById(places, companyOfBranch(places, branch) ?? activity?.company?.id)?.timezone
  const local = (iso) => (iso ? localDateTimeIn(zone, new Date(iso)) : '')
  const [values, setValues] = useState(() => ({
    kind: activity?.kind ?? 'call',
    subject: activity?.subject ?? '',
    notes: activity?.notes ?? '',
    owner_id: activity?.owner?.id ?? '',
  }))
  // Times stay as typed (datetime-local); an untouched one shows the record's own in the company zone.
  const [times, setTimes] = useState({})
  const [party, setParty] = useState(activity?.party ?? null)
  const owners = useAssignees(branch, Boolean(branch))
  const shownTime = (name, fallback) => times[name] ?? (activity ? local(activity[name]) : (fallback ?? ''))
  const startsAt = shownTime('starts_at', start ?? '')
  const set = (name, value) => setValues((current) => ({ ...current, [name]: value }))
  const save = useMutation({
    mutationFn: () => {
      const utc = (text) => (text ? zonedToUtc(text, zone) : null)
      const body = {
        ...(editing ? {} : { branch_id: branch, ...links }),
        kind: values.kind,
        subject: values.subject.trim(),
        notes: values.notes.trim() || null,
        starts_at: utc(startsAt),
        ends_at: utc(shownTime('ends_at')),
        remind_at: utc(shownTime('remind_at')),
        ...(values.owner_id ? { owner_id: values.owner_id } : {}),
        ...(pickCustomer && party ? { party_id: party.id } : {}),
      }
      return editing ? api.patch(`crm/activities/${activity.id}`, body) : api.post('crm/activities', body)
    },
    onSuccess: (response) => {
      toast.success(t(editing ? 'crm.activity.saved' : 'crm.activity.created'))
      onSaved?.(response)
    },
  })
  const errors = formErrors(save.error, ACTIVITY_FIELDS)
  const ready = branch && values.subject.trim() && startsAt

  return (
    <Dialog
      open
      size="lg"
      title={t(editing ? 'crm.activity.editTitle' : 'crm.activity.newTitle')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('crm.actions.back')}
          </Button>
          <Button variant="primary" loading={save.isPending} disabled={!ready} onClick={() => save.mutate()}>
            {t(editing ? 'crm.activity.save' : 'crm.activity.create')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 pt-1 sm:grid-cols-2">
        {errors.form ? <Alert tone="danger" title={errors.form} className="sm:col-span-2" /> : null}
        {!fixedBranch && !editing ? (
          <Select
            label={t('crm.fields.branch')}
            placeholder={t('crm.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            error={errors.fields.branch_id}
            onChange={(event) => {
              setBranch(event.target.value)
              set('owner_id', '')
            }}
          />
        ) : null}
        <Select
          label={t('crm.fields.kind')}
          options={ACTIVITY_KINDS.map((kind) => ({ value: kind, label: t(`crm.activityKinds.${kind}`) }))}
          value={values.kind}
          error={errors.fields.kind}
          onChange={(event) => set('kind', event.target.value)}
        />
        <TextField label={t('crm.fields.subject')} value={values.subject} required maxLength={200} error={errors.fields.subject} onChange={(event) => set('subject', event.target.value)} className="sm:col-span-2" />
        <TextField type="datetime-local" label={t('crm.fields.startsAt')} value={startsAt} required error={errors.fields.starts_at} onChange={(event) => setTimes((current) => ({ ...current, starts_at: event.target.value }))} />
        <TextField type="datetime-local" label={t('crm.fields.endsAt')} value={shownTime('ends_at')} error={errors.fields.ends_at} onChange={(event) => setTimes((current) => ({ ...current, ends_at: event.target.value }))} />
        <TextField
          type="datetime-local"
          label={t('crm.fields.remindAt')}
          help={t('crm.activity.remindHelp')}
          value={shownTime('remind_at')}
          error={errors.fields.remind_at}
          onChange={(event) => setTimes((current) => ({ ...current, remind_at: event.target.value }))}
        />
        <Select
          label={t('crm.fields.owner')}
          options={[{ value: '', label: t('crm.pickers.me') }, ...namedOptions(owners)]}
          value={values.owner_id}
          disabled={!branch}
          error={errors.fields.owner_id}
          onChange={(event) => set('owner_id', event.target.value)}
        />
        {pickCustomer ? <CustomerPicker value={party} onChange={setParty} error={errors.fields.party_id} /> : null}
        <TextAreaField label={t('crm.fields.notes')} value={values.notes} maxLength={5000} error={errors.fields.notes} onChange={(event) => set('notes', event.target.value)} className="sm:col-span-2" />
      </div>
    </Dialog>
  )
}

/** What an activity is linked to, as words ("Lead · Wanjiku Kamau"). */
export function ActivityLinks({ activity }) {
  const { t } = useTranslation()
  const parts = [
    activity.party ? `${t('crm.fields.customer')} · ${activity.party.name}` : null,
    activity.opportunity ? `${t('crm.fields.opportunity')} · ${activity.opportunity.name}` : null,
    activity.lead ? `${t('crm.fields.lead')} · ${activity.lead.name}` : null,
    activity.quote ? `${t('crm.fields.quote')} · ${activity.quote.number ?? t('crm.quote.draftNumber')}` : null,
    activity.email ? `${t('crm.activity.emailTo', { to: activity.email.to })}` : null,
  ].filter(Boolean)
  return parts.length ? <span className="text-caption text-ink-muted">{parts.join(' · ')}</span> : null
}

/**
 * CRM-26, CRM-27: activities with their kind, subject, time, owner and
 * status (overdue marked), and Complete, Cancel, Reopen and Edit for an
 * activity the reader may change (`can.edit`). `onChanged` runs after a change.
 */
export function ActivityList({ activities, emptyText, onChanged }) {
  const { t } = useTranslation()
  const { can } = usePermissions()
  const [completing, setCompleting] = useState(null)
  const [editing, setEditing] = useState(null)
  const queryClient = useQueryClient()
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: crmKey })
    onChanged?.()
  }
  const action = useCrmAction(null, { onDone: () => onChanged?.() })
  if (!activities?.length) return <p className="text-body text-ink-muted">{emptyText ?? t('crm.activity.none')}</p>
  const manage = can(ACTIVITY_MANAGE)
  return (
    <>
      <ul className="flex flex-col divide-y divide-border">
        {activities.map((activity) => {
          const open = activity.status === 'open'
          const editable = manage && activity.can?.edit !== false
          return (
            <li key={activity.id} className="flex flex-wrap items-start justify-between gap-3 py-3">
              <div className="flex min-w-0 flex-col gap-1">
                <span className="flex flex-wrap items-center gap-2">
                  <span className="text-caption text-ink-muted">{t(`crm.activityKinds.${activity.kind}`, { defaultValue: activity.kind })}</span>
                  <span className="font-medium text-ink">{activity.subject}</span>
                  {open && activity.overdue ? <Overdue /> : <Status status={activity.status} />}
                </span>
                <span className="text-caption text-ink-muted">
                  <When value={activity.starts_at} companyId={activity.company?.id} />
                  {activity.owner ? ` · ${activity.owner.name}` : ''}
                </span>
                <ActivityLinks activity={activity} />
                {activity.outcome ? <span className="text-caption text-ink">{t('crm.activity.outcomeText', { outcome: activity.outcome })}</span> : null}
              </div>
              {editable ? (
                <div className="flex flex-wrap gap-2">
                  {open ? (
                    <>
                      <Button variant="ghost" icon="edit" aria-label={t('crm.activity.editNamed', { subject: activity.subject })} onClick={() => setEditing(activity)} />
                      <Button onClick={() => setCompleting(activity)}>{t('crm.activity.complete')}</Button>
                      <Button variant="ghost" loading={action.isPending && action.variables?.path === `crm/activities/${activity.id}/cancel`} onClick={() => action.mutate({ path: `crm/activities/${activity.id}/cancel`, done: 'crm.activity.cancelled' })}>
                        {t('crm.activity.cancel')}
                      </Button>
                    </>
                  ) : (
                    <Button variant="ghost" onClick={() => action.mutate({ path: `crm/activities/${activity.id}/reopen`, done: 'crm.activity.reopened' })}>
                      {t('crm.activity.reopen')}
                    </Button>
                  )}
                </div>
              ) : null}
            </li>
          )
        })}
      </ul>
      {completing ? (
        <ReasonDialog
          title={t('crm.activity.completeTitle', { subject: completing.subject })}
          label={t('crm.fields.outcome')}
          field="outcome"
          required={false}
          confirm={t('crm.activity.complete')}
          path={`crm/activities/${completing.id}/complete`}
          onClose={() => setCompleting(null)}
          onDone={() => {
            setCompleting(null)
            toast.success(t('crm.activity.completed'))
            refresh()
          }}
        />
      ) : null}
      {editing ? (
        <ActivityDialog
          activity={editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      ) : null}
    </>
  )
}
