import { useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, Dialog, Select, StatusBadge, Tabs, TextField } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { EXCEPTION_TONES, employeeLabel, enumLabel, hrKey, SECTIONS, STATUS_TONES, statusLabel } from './hrData'
import { ADDRESS_FIELDS, useDay, useHrMutation } from './hrForms'

// The presentational pieces HR shares with other back-office modules (an
// amount, a detail row, the way back, a detail's loading and error states,
// a calendar date) come from the Inventory parts, which hold no Inventory data.
export { Amount, Back, CalendarDate, Detail, DetailState } from '@/pages/inventory/InventoryParts'
export { TextAreaField }

/** An HR status as a dot and a word. */
export function HrStatus({ status }) {
  const { t } = useTranslation()
  if (!status) return null
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** HR-25: a timesheet day's exceptions, each a small dot and a word. */
export function ExceptionBadges({ exceptions }) {
  const { t } = useTranslation()
  if (!exceptions?.length) return <span className="text-ink-muted">—</span>
  return (
    <span className="flex flex-wrap gap-x-3 gap-y-1">
      {exceptions.map((code) => (
        <StatusBadge key={code} tone={EXCEPTION_TONES[code] ?? 'neutral'}>
          {enumLabel(t, 'exceptions', code)}
        </StatusBadge>
      ))}
    </span>
  )
}

/** Days (a decimal string) in the UI language, tabular. */
export function Days({ value }) {
  const locale = useLocale()
  if (value === null || value === undefined || value === '') return <span className="text-ink-muted">—</span>
  return <span className="tabular-nums">{formatDecimal(value, locale)}</span>
}

/**
 * HR-02: an employee picker that searches the server (hr/employees?search=).
 * `value` is the picked employee ({id, number, name}); `onChange(employee)`.
 */
export function EmployeePicker({ label, value, onChange, company, status = 'active', error, required, disabled, exclude = [], className }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const params = new URLSearchParams({ per_page: '20', sort: 'number', status })
  if (company) params.set('company', company)
  if (search) params.set('search', search)
  const query = useQuery({ queryKey: [...hrKey, 'employee-picker', params.toString()], queryFn: () => api.get(`hr/employees?${params}`), staleTime: 30_000, retry: false })
  const rows = (query.data?.data ?? []).filter((row) => !exclude.includes(row.id))
  const known = value && !rows.some((row) => row.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label ?? t('hr.fields.employee')}
      placeholder={t('hr.pickers.employee')}
      options={known.map((row) => ({ value: row.id, label: employeeLabel(row) }))}
      value={value?.id ?? ''}
      error={error ?? (query.isError ? errorMessage(query.error) : undefined)}
      required={required}
      disabled={disabled}
      onSearchChange={onSearchChange}
      onChange={(event) => onChange(known.find((row) => row.id === event.target.value) ?? null)}
      className={className}
    />
  )
}

/** The pages of a section (Leave, Rosters, Attendance) as tabs; only those the user may open. */
export function SectionTabs({ section }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const { can } = usePermissions()
  const pages = SECTIONS[section].filter((page) => can(page.permission))
  const current = pages.find((page) => pathname.startsWith(page.to))?.key ?? pages[0]?.key
  return <Tabs value={current} onChange={(key) => navigate(pages.find((page) => page.key === key).to)} items={pages.map((page) => ({ value: page.key, label: t(`hr.sections.${section}.${page.key}`) }))} />
}

/** A section's own path opens its first page the user may see. */
export function SectionIndex({ section }) {
  const { can, isLoading } = usePermissions()
  if (isLoading) return null
  const first = SECTIONS[section].find((page) => can(page.permission))
  return first ? <Navigate to={first.to} replace /> : <Navigate to="/" replace />
}

/** Shown while the user has no company to set HR up for. */
export function NoCompany() {
  const { t } = useTranslation()
  const { ready } = useCompanies()
  if (!ready) return <p className="text-ink-muted">{t('common.loading')}</p>
  return <Alert tone="info" title={t('hr.noCompany')} />
}

/**
 * A form in a dialog: the form's own message above, Cancel and the
 * action naming the result. `errors` comes from formErrors (fields under
 * their inputs, the rest in the alert).
 */
export function FormDialog({ open, title, onClose, onSubmit, submitLabel, pending, disabled, errors, size = 'md', variant = 'primary', children }) {
  const { t } = useTranslation()
  return (
    <Dialog
      open={open}
      title={title}
      size={size}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('common.cancel')}
          </Button>
          <Button variant={variant} loading={pending} disabled={disabled} onClick={onSubmit}>
            {submitLabel}
          </Button>
        </>
      }
    >
      <form
        noValidate
        className="flex flex-col gap-4 pt-1"
        onSubmit={(event) => {
          event.preventDefault()
          if (!disabled) onSubmit()
        }}
      >
        {errors?.form ? <Alert tone="danger" title={errors.form} /> : null}
        {children}
      </form>
    </Dialog>
  )
}

/**
 * A confirmation, with a reason when `reasonLabel` is given. `onConfirm(reason)`
 * runs the action; the dialog shows its error.
 */
export function ConfirmDialog({ open, title, text, confirmLabel, reasonLabel, reasonRequired = false, tone = 'primary', pending, error, onConfirm, onClose }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const close = () => {
    setReason('')
    onClose()
  }
  return (
    <Dialog
      open={open}
      title={title}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('hr.actions.keep')}
          </Button>
          <Button variant={tone} loading={pending} disabled={reasonRequired && !reason.trim()} onClick={() => onConfirm(reason.trim())}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
        {text ? <p>{text}</p> : null}
        {reasonLabel ? <TextField label={reasonLabel} value={reason} maxLength={500} required={reasonRequired} onChange={(event) => setReason(event.target.value)} /> : null}
      </div>
    </Dialog>
  )
}

/** HR-06: up to 5 emergency contacts: name, relationship, phones and email. */
export function EmergencyContactsEditor({ value, onChange, errors = {} }) {
  const { t } = useTranslation()
  const set = (index, key, text) => onChange(value.map((row, at) => (at === index ? { ...row, [key]: text } : row)))
  return (
    <fieldset className="flex flex-col gap-3">
      <legend className="text-label text-ink">{t('hr.personal.emergencyContacts')}</legend>
      {value.length === 0 ? <p className="text-body text-ink-muted">{t('hr.personal.noEmergencyContacts')}</p> : null}
      {value.map((row, index) => (
        <div key={index} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
          {['name', 'relationship', 'phone', 'alt_phone', 'email'].map((key) => (
            <TextField
              key={key}
              label={t(`hr.emergency.${key}`)}
              value={row[key] ?? ''}
              required={key === 'name' || key === 'phone'}
              type={key === 'email' ? 'email' : 'text'}
              error={errors[`emergency_contacts.${index}.${key}`]}
              onChange={(event) => set(index, key, event.target.value)}
            />
          ))}
          <div className="flex items-end">
            <Button variant="ghost" icon="remove" onClick={() => onChange(value.filter((_, at) => at !== index))}>
              {t('hr.personal.removeContact')}
            </Button>
          </div>
        </div>
      ))}
      {value.length < 5 ? (
        <div>
          <Button icon="plus" onClick={() => onChange([...value, { name: '', relationship: '', phone: '', alt_phone: '', email: '' }])}>
            {t('hr.personal.addContact')}
          </Button>
        </div>
      ) : null}
    </fieldset>
  )
}

/** HR-02, HR-06, HR-31: personal contacts, address and emergency contacts, as one form (the profile's Personal tab and My profile). */
export function PersonalForm({ form, setForm, errors }) {
  const { t } = useTranslation()
  const field = (key) => ({ value: form[key] ?? '', error: errors.fields[key], onChange: (event) => setForm({ ...form, [key]: event.target.value }) })
  const address = (key) => ({
    value: form.address?.[key] ?? '',
    error: errors.fields[`address.${key}`],
    onChange: (event) => setForm({ ...form, address: { ...form.address, [key]: event.target.value } }),
  })
  return (
    <>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="email" label={t('hr.fields.personalEmail')} {...field('personal_email')} />
        <TextField label={t('hr.fields.personalPhone')} {...field('personal_phone')} />
      </div>
      <fieldset className="grid gap-3 sm:grid-cols-2">
        <legend className="mb-2 text-label text-ink">{t('hr.personal.address')}</legend>
        {ADDRESS_FIELDS.map((key) => (
          <TextField key={key} label={t(`hr.address.${key}`)} maxLength={key === 'country' ? 2 : 200} {...address(key)} />
        ))}
      </fieldset>
      <EmergencyContactsEditor value={form.emergency_contacts} onChange={(next) => setForm({ ...form, emergency_contacts: next })} errors={errors.fields} />
    </>
  )
}

/**
 * HR-14: ask for leave: type, dates, half days at either end, reason.
 * `types` are `{id, code, name}`; `onSubmit(body)`.
 */
export function LeaveRequestFields({ form, setForm, types, errors, employeePicker }) {
  const { t } = useTranslation()
  return (
    <>
      {employeePicker}
      <Select
        label={t('hr.fields.leaveType')}
        placeholder={t('hr.pickers.leaveType')}
        options={types.map((type) => ({ value: type.id, label: `${type.name} · ${type.code}` }))}
        value={form.leave_type_id}
        required
        error={errors.fields.leave_type_id}
        onChange={(event) => setForm({ ...form, leave_type_id: event.target.value })}
      />
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField type="date" label={t('hr.fields.startDate')} value={form.start_date} required error={errors.fields.start_date} onChange={(event) => setForm({ ...form, start_date: event.target.value })} />
        <TextField type="date" label={t('hr.fields.endDate')} value={form.end_date} required error={errors.fields.end_date} onChange={(event) => setForm({ ...form, end_date: event.target.value })} />
        <Checkbox label={t('hr.leave.startHalf')} checked={form.start_half} onChange={(event) => setForm({ ...form, start_half: event.target.checked })} />
        <Checkbox label={t('hr.leave.endHalf')} checked={form.end_half} onChange={(event) => setForm({ ...form, end_half: event.target.checked })} />
      </div>
      <TextAreaField label={t('hr.fields.reason')} value={form.reason} maxLength={1000} error={errors.fields.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} />
    </>
  )
}

/**
 * HR-28: a checklist with its items; holders of `hr.checklist.manage`
 * tick items done (or reopen them). `employee` shows the employee's name.
 */
export function ChecklistCard({ checklist, canManage, showEmployee = false }) {
  const { t } = useTranslation()
  const day = useDay()
  const toggle = useHrMutation((item) => api.post(`hr/checklist-items/${item.id}/${item.done_at ? 'reopen' : 'complete'}`, {}))
  const done = checklist.items.filter((item) => item.done_at).length
  return (
    <Card
      title={showEmployee ? `${checklist.name} · ${employeeLabel(checklist.employee)}` : checklist.name}
      subtitle={`${enumLabel(t, 'checklistKinds', checklist.kind)} · ${t('hr.checklists.progress', { done, total: checklist.items.length })}`}
      actions={<HrStatus status={checklist.status} />}
    >
      {toggle.isError ? <Alert tone="danger" title={errorMessage(toggle.error)} className="mb-3" /> : null}
      <ul className="flex flex-col gap-2">
        {checklist.items.map((item) => (
          <li key={item.id} className="flex flex-wrap items-center justify-between gap-2">
            <Checkbox label={item.title} checked={Boolean(item.done_at)} disabled={!canManage || toggle.isPending} onChange={() => toggle.mutate(item)} />
            <span className="text-caption text-ink-muted tabular-nums">{item.due_on ? t('hr.checklists.due', { date: day(item.due_on) }) : ''}</span>
          </li>
        ))}
      </ul>
    </Card>
  )
}

/** A small list of label and value pairs (a detail card). */
export function DetailGrid({ children }) {
  return <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{children}</dl>
}

/** A message on an empty table or section. */
export function Empty({ children }) {
  return <p className="text-body text-ink-muted">{children}</p>
}
