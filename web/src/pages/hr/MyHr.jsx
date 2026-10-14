import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, DataTable, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatDate } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { EmergencyContacts } from './EmployeeProfile'
import { BalancesTable, LeaveRequestsTable, PunchTable, TimesheetTable } from './EmployeeTabs'
import { CalendarDate, ConfirmDialog, Detail, DetailGrid, Empty, EmployeePicker, FormDialog, HrStatus, LeaveRequestFields, PersonalForm, TextAreaField } from './HrParts'

import { addDays, daysFrom, enumLabel, hrKey, meKey, RATINGS, today, useHrMe, weekStart } from './hrData'
import { ADDRESS_FIELDS, blankLeave, LEAVE_FIELDS, leaveBody, PERSONAL_FIELDS, personalBody, personalForm, useClock, useDay, useHrMutation } from './hrForms'

/**
 * HR-31: a self-service page. The signed-in user's link to an employee is
 * the authorisation; a user who is not linked sees why, and what to ask.
 */
function MyPage({ title, description, actions, children }) {
  const { t } = useTranslation()
  const { query, me, notLinked } = useHrMe()
  return (
    <>
      <PageHeader title={title} description={description} actions={me ? actions?.(me) : null} />
      {query.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
      {notLinked ? <Alert tone="info" title={t('hr.me.notLinkedTitle')}>{t('hr.me.notLinkedHelp')}</Alert> : null}
      {query.isError && !notLinked ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      {me ? children(me) : null}
    </>
  )
}

/** HR-31: the user's own record; identifiers and pay accounts always masked; personal contacts editable. */
export function MyProfile() {
  const { t } = useTranslation()
  const [editing, setEditing] = useState(false)
  return (
    <MyPage
      title={t('hr.me.profile.title')}
      description={t('hr.me.profile.description')}
      actions={() => (
        <Button icon="edit" onClick={() => setEditing(true)}>
          {t('hr.me.profile.edit')}
        </Button>
      )}
    >
      {(me) => (
        <>
          <Card title={me.name} subtitle={me.number}>
            <DetailGrid>
              <Detail label={t('hr.fields.company')}>{me.company?.name ?? '—'}</Detail>
              <Detail label={t('hr.fields.branch')}>{me.branch?.name ?? '—'}</Detail>
              <Detail label={t('hr.fields.department')}>{me.department?.name ?? '—'}</Detail>
              <Detail label={t('hr.fields.position')}>{me.position?.title ?? '—'}</Detail>
              <Detail label={t('hr.fields.lineManager')}>{me.reports_to ? me.reports_to.name : '—'}</Detail>
              <Detail label={t('hr.fields.hireDate')}>
                <CalendarDate value={me.hire_date} />
              </Detail>
              <Detail label={t('hr.fields.workEmail')}>{me.work_email ?? '—'}</Detail>
              <Detail label={t('hr.fields.workPhone')}>{me.work_phone ?? '—'}</Detail>
              {'date_of_birth' in me ? (
                <Detail label={t('hr.fields.dateOfBirth')}>
                  <CalendarDate value={me.date_of_birth} />
                </Detail>
              ) : null}
            </DetailGrid>
          </Card>
          <Card title={t('hr.me.profile.contacts')}>
            <DetailGrid>
              <Detail label={t('hr.fields.personalEmail')}>{me.personal_email ?? '—'}</Detail>
              <Detail label={t('hr.fields.personalPhone')}>{me.personal_phone ?? '—'}</Detail>
              <Detail label={t('hr.personal.address')}>{ADDRESS_FIELDS.map((key) => me.address?.[key]).filter(Boolean).join(', ') || '—'}</Detail>
            </DetailGrid>
          </Card>
          <EmergencyContacts contacts={me.emergency_contacts ?? []} />
          <Card title={t('hr.me.profile.identity')} subtitle={t('hr.me.profile.maskedHelp')}>
            {(me.identifiers ?? []).length === 0 && (me.pay_accounts ?? []).length === 0 ? <Empty>{t('hr.me.profile.noIdentity')}</Empty> : null}
            <DetailGrid>
              {(me.identifiers ?? []).map((row) => (
                <Detail key={row.type} label={row.label ?? row.type}>
                  <span className="font-mono tabular-nums">{row.masked}</span>
                </Detail>
              ))}
              {(me.pay_accounts ?? [])
                .filter((row) => !row.archived_at)
                .map((row) => (
                  <Detail key={row.id} label={`${enumLabel(t, 'payAccountKinds', row.kind)} · ${row.provider}`}>
                    <span className="font-mono tabular-nums">{`${row.masked} · ${row.currency}`}</span>
                  </Detail>
                ))}
            </DetailGrid>
          </Card>
          {editing ? <EditMyContacts me={me} onClose={() => setEditing(false)} /> : null}
        </>
      )}
    </MyPage>
  )
}

function EditMyContacts({ me, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(() => personalForm(me))
  const save = useHrMutation((body) => api.patch('hr/me', body), { success: t('hr.me.profile.saved'), onDone: onClose, keys: [meKey] })
  const errors = save.errors(PERSONAL_FIELDS)
  return (
    <FormDialog open size="lg" title={t('hr.me.profile.editTitle')} onClose={onClose} submitLabel={t('hr.actions.saveChanges')} pending={save.isPending} errors={errors} onSubmit={() => save.mutate(personalBody(form))}>
      <PersonalForm form={form} setForm={setForm} errors={errors} />
    </FormDialog>
  )
}

/** HR-31: the user's balances and requests; ask for leave and cancel it. */
export function MyLeave() {
  const { t } = useTranslation()
  const [dialog, setDialog] = useState(null)
  const { linked } = useHrMe()
  const query = useQuery({ queryKey: [...hrKey, 'me', 'leave'], queryFn: () => api.get('hr/me/leave'), enabled: linked })
  const cancel = useHrMutation(({ row, reason }) => api.post(`hr/me/leave-requests/${row.id}/cancel`, { reason: reason || null }), { success: t('hr.leave.cancelled'), onDone: () => setDialog(null) })
  const balances = query.data?.data?.balances ?? []
  return (
    <MyPage
      title={t('hr.me.leave.title')}
      description={t('hr.me.leave.description')}
      actions={() => (
        <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'request' })}>
          {t('hr.me.leave.request')}
        </Button>
      )}
    >
      {() => (
        <>
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          <Card title={t('hr.balances.title')}>
            <BalancesTable caption={t('hr.balances.title')} balances={balances} />
          </Card>
          <Card title={t('hr.leave.requestsTitle')}>
            <LeaveRequestsTable caption={t('hr.leave.requestsTitle')} loading={query.isLoading} rows={query.data?.data?.requests ?? []} onCancel={(row) => setDialog({ kind: 'cancel', row })} />
          </Card>
          {dialog?.kind === 'request' ? <MyLeaveRequest types={balances.filter((row) => !row.days_needed).map((row) => row.leave_type)} onClose={() => setDialog(null)} /> : null}
          {dialog?.kind === 'cancel' ? (
            <ConfirmDialog
              open
              title={t('hr.leave.cancelTitle', { number: dialog.row.number })}
              text={t('hr.me.leave.cancelHelp')}
              confirmLabel={t('hr.leave.cancel')}
              reasonLabel={t('hr.fields.reason')}
              tone="danger"
              pending={cancel.isPending}
              error={cancel.error}
              onConfirm={(reason) => cancel.mutate({ row: dialog.row, reason })}
              onClose={() => setDialog(null)}
            />
          ) : null}
        </>
      )}
    </MyPage>
  )
}

function MyLeaveRequest({ types, onClose }) {
  const { t } = useTranslation()
  const [form, setForm] = useState(blankLeave)
  const save = useHrMutation((body) => api.post('hr/me/leave-requests', body), { success: (response) => t('hr.leave.requested', { number: response?.data?.number ?? '' }), onDone: onClose })
  const errors = save.errors(LEAVE_FIELDS)
  return (
    <FormDialog open title={t('hr.me.leave.requestTitle')} onClose={onClose} submitLabel={t('hr.leave.sendRequest')} pending={save.isPending} disabled={!form.leave_type_id || !form.start_date || !form.end_date} errors={errors} onSubmit={() => save.mutate(leaveBody(form))}>
      <p className="text-caption text-ink-muted">{t('hr.me.leave.requestHelp')}</p>
      <LeaveRequestFields form={form} setForm={setForm} types={types} errors={errors} />
    </FormDialog>
  )
}

/** A punch older than this no longer decides the next one (an "in" left open overnight). */
const CLOCK_WINDOW = 18 * 60 * 60 * 1000

/** A week chooser (previous, this week, next) for the self-service pages. */
function WeekNav({ from, setFrom }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const to = addDays(from, 6)
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button variant="ghost" icon="chevronLeft" aria-label={t('hr.week.previous')} onClick={() => setFrom(addDays(from, -7))} />
      <h2 className="text-h3 text-ink tabular-nums">{t('hr.week.range', { from: formatDate(`${from}T12:00:00Z`, locale), to: formatDate(`${to}T12:00:00Z`, locale) })}</h2>
      <Button variant="ghost" icon="chevronRight" aria-label={t('hr.week.next')} onClick={() => setFrom(addDays(from, 7))} />
      <Button variant="ghost" onClick={() => setFrom(weekStart(today()))}>
        {t('hr.week.thisWeek')}
      </Button>
    </div>
  )
}

/** HR-31: the user's planned shifts, a week at a time. */
export function MyRoster() {
  const { t } = useTranslation()
  const locale = useLocale()
  const clock = useClock()
  const { linked } = useHrMe()
  const [from, setFrom] = useState(() => weekStart(today()))
  const to = addDays(from, 6)
  const query = useQuery({ queryKey: [...hrKey, 'me', 'roster', from], queryFn: () => api.get(`hr/me/roster?from=${from}&to=${to}`), enabled: linked })
  const shifts = query.data?.data ?? []
  return (
    <MyPage title={t('hr.me.roster.title')} description={t('hr.me.roster.description')}>
      {() => (
        <>
          <WeekNav from={from} setFrom={setFrom} />
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          <ul className="flex flex-col divide-y divide-border rounded-lg border border-border bg-surface-200">
            {daysFrom(from, 7).map((date) => {
              const shift = shifts.find((entry) => entry.date === date)
              return (
                <li key={date} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                  <span className="text-body text-ink">{new Intl.DateTimeFormat(locale === 'en' ? 'en-GB' : locale, { weekday: 'long', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`))}</span>
                  {shift ? (
                    <span className="flex items-center gap-3 text-body">
                      <span className="font-medium text-ink">{shift.shift?.name ?? shift.shift?.code}</span>
                      <span className="text-ink-muted tabular-nums">{`${clock(shift.start_at)} – ${clock(shift.end_at)}`}</span>
                      {shift.holiday ? <HrStatus status="holiday" /> : null}
                    </span>
                  ) : (
                    <span className="text-body text-ink-muted">{t('hr.me.roster.off')}</span>
                  )}
                </li>
              )
            })}
          </ul>
        </>
      )}
    </MyPage>
  )
}

/**
 * HR-31, HR-22: the user's days and punches; Clock in or Clock out on the
 * web when `web` is one of their clock-in methods (`can_clock`).
 */
export function MyAttendance() {
  const { t } = useTranslation()
  const clock = useClock()
  const { linked } = useHrMe()
  const [from, setFrom] = useState(() => weekStart(today()))
  const to = addDays(from, 6)
  const query = useQuery({ queryKey: [...hrKey, 'me', 'attendance', from], queryFn: () => api.get(`hr/me/attendance?from=${from}&to=${to}`), enabled: linked })
  const data = query.data?.data
  const punches = data?.punches ?? []
  // The clock card reads today alone (with the night before), whatever week is shown below.
  const now = useQuery({ queryKey: [...hrKey, 'me', 'attendance', 'today'], queryFn: () => api.get(`hr/me/attendance?from=${today()}&to=${today()}`), enabled: linked })
  const recent = (now.data?.data?.punches ?? []).filter((entry) => now.dataUpdatedAt - new Date(entry.occurred_at).getTime() < CLOCK_WINDOW)
  const last = recent[recent.length - 1] ?? null
  const next = last?.kind === 'in' ? 'out' : 'in'
  const punch = useHrMutation(() => api.post('hr/me/clock', { kind: next }), {
    success: (response) => t(response?.data?.kind === 'out' ? 'hr.me.attendance.clockedOut' : 'hr.me.attendance.clockedIn', { time: clock(response?.data?.occurred_at) }),
    keys: [[...hrKey, 'me', 'attendance']],
  })
  const canClock = now.data?.data?.can_clock ?? data?.can_clock
  return (
    <MyPage title={t('hr.me.attendance.title')} description={t('hr.me.attendance.description')}>
      {() => (
        <>
          {canClock ? (
            <Card title={t('hr.me.attendance.clockTitle')} subtitle={last ? t(last.kind === 'in' ? 'hr.me.attendance.inSince' : 'hr.me.attendance.outSince', { time: clock(last.occurred_at) }) : t('hr.me.attendance.notYet')}>
              {punch.isError ? <Alert tone="danger" title={errorMessage(punch.error)} className="mb-3" /> : null}
              <Button variant="pay" size="lg" loading={punch.isPending} onClick={() => punch.mutate()}>
                {next === 'out' ? t('hr.me.attendance.clockOut') : t('hr.me.attendance.clockIn')}
              </Button>
            </Card>
          ) : canClock === false ? (
            <Alert tone="info" title={t('hr.me.attendance.noWebClock')} />
          ) : null}
          <WeekNav from={from} setFrom={setFrom} />
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          <Card title={t('hr.me.attendance.days')}>
            <TimesheetTable caption={t('hr.me.attendance.days')} loading={query.isLoading} rows={data?.days ?? []} />
          </Card>
          <Card title={t('hr.me.attendance.punches')}>
            <PunchTable caption={t('hr.me.attendance.punches')} loading={query.isLoading} rows={punches} />
          </Card>
        </>
      )}
    </MyPage>
  )
}

/**
 * HR-19, HR-31: the user's swaps: ask to swap one of their future shifts
 * with a peer's (or give it away), and accept or decline the ones asked of
 * them.
 */
export function MySwaps() {
  const { t } = useTranslation()
  const day = useDay()
  const { linked, me } = useHrMe()
  const [dialog, setDialog] = useState(null)
  const query = useQuery({ queryKey: [...hrKey, 'me', 'swaps'], queryFn: () => api.get('hr/me/swaps'), enabled: linked })
  const act = useHrMutation(({ row, action }) => api.post(`hr/me/swaps/${row.id}/${action}`, {}), { success: (_, { action }) => t(`hr.swaps.${action}Done`), onDone: () => setDialog(null) })
  const shiftText = (shift) => (shift ? `${day(shift.date)} · ${shift.shift?.code ?? ''}` : t('hr.swaps.giveAway'))
  return (
    <MyPage
      title={t('hr.me.swaps.title')}
      description={t('hr.me.swaps.description')}
      actions={() => (
        <Button variant="primary" icon="plus" onClick={() => setDialog({ kind: 'ask' })}>
          {t('hr.me.swaps.ask')}
        </Button>
      )}
    >
      {() => (
        <>
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          <DataTable
            caption={t('hr.me.swaps.title')}
            loading={query.isLoading}
            rows={query.data?.data ?? []}
            emptyText={t('hr.swaps.none')}
            columns={[
              { key: 'employee', label: t('hr.swaps.asking'), render: (row) => row.employee?.name ?? '' },
              { key: 'shift', label: t('hr.swaps.shift'), render: (row) => <span className="tabular-nums">{shiftText(row.roster_shift)}</span> },
              { key: 'peer', label: t('hr.swaps.peer'), render: (row) => row.peer?.name ?? '' },
              { key: 'peerShift', label: t('hr.swaps.peerShift'), render: (row) => <span className="tabular-nums">{shiftText(row.peer_roster_shift)}</span> },
              { key: 'status', label: t('hr.columns.status'), render: (row) => <HrStatus status={row.status} /> },
              {
                key: 'actions',
                label: '',
                align: 'end',
                render: (row) =>
                  row.status === 'awaiting_peer' && row.peer?.id === me?.id ? (
                    <span className="flex justify-end gap-2">
                      <Button variant="ghost" onClick={() => setDialog({ kind: 'act', row, action: 'accept' })}>
                        {t('hr.swaps.accept')}
                      </Button>
                      <Button variant="ghost" onClick={() => setDialog({ kind: 'act', row, action: 'decline' })}>
                        {t('hr.swaps.decline')}
                      </Button>
                    </span>
                  ) : null,
              },
            ]}
          />
          {dialog?.kind === 'ask' ? <AskSwap me={me} onClose={() => setDialog(null)} /> : null}
          {dialog?.kind === 'act' ? (
            <ConfirmDialog
              open
              title={t(`hr.swaps.${dialog.action}Title`)}
              text={t('hr.me.swaps.answerHelp', { name: dialog.row.employee?.name ?? '' })}
              confirmLabel={t(`hr.swaps.${dialog.action}`)}
              tone={dialog.action === 'accept' ? 'primary' : 'danger'}
              pending={act.isPending}
              error={act.error}
              onConfirm={() => act.mutate({ row: dialog.row, action: dialog.action })}
              onClose={() => setDialog(null)}
            />
          ) : null}
        </>
      )}
    </MyPage>
  )
}

/**
 * HR-19: ask a peer to take or swap one of the user's next shifts. The
 * peer and their shifts are read from the back-office lists, which need a
 * roster role; without one, the dialog says to ask a manager.
 */
function AskSwap({ me, onClose }) {
  const { t } = useTranslation()
  const day = useDay()
  const from = today()
  const to = addDays(from, 41)
  const mine = useQuery({ queryKey: [...hrKey, 'me', 'roster', 'upcoming'], queryFn: () => api.get(`hr/me/roster?from=${from}&to=${to}`) })
  const [form, setForm] = useState({ roster_shift_id: '', peer: null, peer_roster_shift_id: '', reason: '' })
  const peerShifts = useQuery({
    queryKey: [...hrKey, 'peer-shifts', form.peer?.id],
    queryFn: () => api.get(`hr/roster-shifts?employee=${form.peer.id}&from=${from}&to=${addDays(from, 30)}`),
    enabled: Boolean(form.peer),
    retry: false,
  })
  const save = useHrMutation((body) => api.post('hr/me/swaps', body), { success: t('hr.me.swaps.asked'), onDone: onClose })
  const errors = save.errors(['roster_shift_id', 'peer_employee_id', 'peer_roster_shift_id', 'reason'])
  const shiftOption = (shift) => ({ value: shift.id, label: `${day(shift.date)} · ${shift.shift?.code ?? ''}` })
  return (
    <FormDialog
      open
      title={t('hr.me.swaps.askTitle')}
      onClose={onClose}
      submitLabel={t('hr.me.swaps.send')}
      pending={save.isPending}
      disabled={!form.roster_shift_id || !form.peer}
      errors={errors}
      onSubmit={() => save.mutate({ roster_shift_id: form.roster_shift_id, peer_employee_id: form.peer.id, peer_roster_shift_id: form.peer_roster_shift_id || null, reason: form.reason.trim() || null })}
    >
      <Select label={t('hr.me.swaps.myShift')} placeholder={t('hr.pickers.shift')} options={(mine.data?.data ?? []).map(shiftOption)} value={form.roster_shift_id} required error={errors.fields.roster_shift_id} onChange={(event) => setForm({ ...form, roster_shift_id: event.target.value })} />
      <EmployeePicker label={t('hr.swaps.peer')} company={me?.company?.id} value={form.peer} exclude={me ? [me.id] : []} required error={errors.fields.peer_employee_id} onChange={(peer) => setForm({ ...form, peer, peer_roster_shift_id: '' })} />
      {peerShifts.isError ? <Alert tone="info" title={t('hr.me.swaps.peerShiftsHidden')} /> : null}
      <Select
        label={t('hr.swaps.peerShift')}
        options={[{ value: '', label: t('hr.swaps.giveAway') }, ...(peerShifts.data?.data ?? []).map(shiftOption)]}
        value={form.peer_roster_shift_id}
        disabled={!form.peer || peerShifts.isError}
        error={errors.fields.peer_roster_shift_id}
        onChange={(event) => setForm({ ...form, peer_roster_shift_id: event.target.value })}
      />
      <TextField label={t('hr.fields.reason')} value={form.reason} maxLength={500} error={errors.fields.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
    </FormDialog>
  )
}

/** HR-30, HR-31: the user's appraisals: write the self review, then acknowledge the completed one. */
export function MyAppraisals() {
  const { t } = useTranslation()
  const { linked } = useHrMe()
  const query = useQuery({ queryKey: [...hrKey, 'me', 'appraisals'], queryFn: () => api.get('hr/me/appraisals'), enabled: linked })
  const rows = query.data?.data ?? []
  return (
    <MyPage title={t('hr.me.appraisals.title')} description={t('hr.me.appraisals.description')}>
      {() => (
        <>
          {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
          {!query.isLoading && rows.length === 0 ? <Empty>{t('hr.appraisals.none')}</Empty> : null}
          {rows.map((appraisal) => (
            <MyAppraisal key={`${appraisal.id}-${appraisal.status}`} appraisal={appraisal} />
          ))}
        </>
      )}
    </MyPage>
  )
}

function MyAppraisal({ appraisal }) {
  const { t } = useTranslation()
  const [rating, setRating] = useState(appraisal.self_rating ? String(appraisal.self_rating) : '')
  const [comments, setComments] = useState(appraisal.self_comments ?? '')
  const save = useHrMutation((body) => api.patch(`hr/me/appraisals/${appraisal.id}`, body), { success: (_, body) => (body.submit ? t('hr.me.appraisals.submitted') : t('hr.appraisals.saved')) })
  const acknowledge = useHrMutation(() => api.post(`hr/me/appraisals/${appraisal.id}/acknowledge`, {}), { success: t('hr.me.appraisals.acknowledged') })
  const errors = save.errors(['self_rating', 'self_comments'])
  const self = appraisal.status === 'self_review'
  const body = (submit) => ({ self_rating: rating ? Number(rating) : null, self_comments: comments.trim() || null, ...(submit ? { submit: true } : {}) })
  return (
    <Card title={appraisal.cycle?.name ?? ''} subtitle={appraisal.reviewer ? t('hr.appraisals.reviewedBy', { name: appraisal.reviewer.name }) : undefined} actions={<HrStatus status={appraisal.status} />}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {acknowledge.isError ? <Alert tone="danger" title={errorMessage(acknowledge.error)} /> : null}
        {(appraisal.goals ?? []).length ? (
          <DataTable
            caption={t('hr.appraisals.goals')}
            rows={appraisal.goals.map((goal, index) => ({ id: String(index), ...goal }))}
            columns={[
              { key: 'title', label: t('hr.appraisals.goalTitle'), render: (row) => row.title },
              { key: 'weight', label: t('hr.appraisals.weight'), align: 'end', render: (row) => <span className="tabular-nums">{row.weight ?? '—'}</span> },
              { key: 'rating', label: t('hr.appraisals.rating'), render: (row) => (row.rating ? t(`hr.ratings.${row.rating}`) : '—') },
              { key: 'comment', label: t('hr.appraisals.comment'), render: (row) => row.comment ?? '' },
            ]}
          />
        ) : null}
        <Select label={t('hr.appraisals.selfRating')} options={[{ value: '', label: t('hr.appraisals.noRating') }, ...RATINGS.map((value) => ({ value, label: t(`hr.ratings.${value}`) }))]} value={rating} disabled={!self} error={errors.fields.self_rating} onChange={(event) => setRating(event.target.value)} className="sm:max-w-xs" />
        <TextAreaField label={t('hr.appraisals.selfComments')} value={comments} disabled={!self} rows={4} maxLength={5000} error={errors.fields.self_comments} onChange={(event) => setComments(event.target.value)} />
        {appraisal.status === 'completed' || appraisal.status === 'acknowledged' ? (
          <DetailGrid>
            <Detail label={t('hr.appraisals.managerRating')}>{appraisal.manager_rating ? t(`hr.ratings.${appraisal.manager_rating}`) : '—'}</Detail>
            <Detail label={t('hr.appraisals.overall')}>{appraisal.overall_rating ?? '—'}</Detail>
            <Detail label={t('hr.appraisals.managerComments')}>{appraisal.manager_comments ?? '—'}</Detail>
          </DetailGrid>
        ) : null}
        <div className="flex flex-wrap gap-2">
          {self ? (
            <>
              <Button loading={save.isPending && !save.variables?.submit} onClick={() => save.mutate(body(false))}>
                {t('hr.me.appraisals.saveDraft')}
              </Button>
              <Button variant="primary" loading={save.isPending && Boolean(save.variables?.submit)} onClick={() => save.mutate(body(true))}>
                {t('hr.me.appraisals.submit')}
              </Button>
            </>
          ) : null}
          {appraisal.status === 'completed' ? (
            <Button variant="primary" loading={acknowledge.isPending} onClick={() => acknowledge.mutate()}>
              {t('hr.me.appraisals.acknowledge')}
            </Button>
          ) : null}
        </div>
      </div>
    </Card>
  )
}

