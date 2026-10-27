import { useMutation, useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, Icon, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { formatCalendarDate, formatDateTime } from '@/lib/dates'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone } from '@/lib/useTimeZone'
import { BLANK_GUEST, fieldError, hotelKey, ID_TYPES, STATE_TONES, usePaymentMethods } from './hotelData'

/** A calendar day ("2026-12-31") as the UI shows it. */
export function Day({ value }) {
  const locale = useLocale()
  return value ? <span className="tabular-nums">{formatCalendarDate(value, locale)}</span> : <span className="text-ink-muted">—</span>
}

/** A moment (ISO 8601) in the company's time zone. */
export function Stamp({ value, companyId }) {
  const locale = useLocale()
  const zone = useTimeZone(companyId)
  return value ? <span className="tabular-nums">{formatDateTime(value, locale, zone)}</span> : <span className="text-ink-muted">—</span>
}

/** A stay, folio, task or room state as a dot and a word. */
export function StateBadge({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATE_TONES[status] ?? 'neutral'}>{t(`hotel.states.${status}`, { defaultValue: status })}</StatusBadge>
}

/** An amount in minor units with its currency code first. */
export function Amount({ minor, currency, tone }) {
  if (minor === null || minor === undefined || minor === '') return <span className="text-ink-muted">—</span>
  return <Money amount={minor} currency={currency} tone={tone} />
}

/** A label and its value, for detail panels. */
export function Detail({ label, children }) {
  return (
    <div className="flex min-w-0 flex-col gap-1">
      <dt className="text-caption text-ink-muted">{label}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/** The way back to a list. */
export function Back({ to, children }) {
  return (
    <Link to={to} className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {children}
    </Link>
  )
}

/** A detail page while it loads, fails or is not found. */
export function DetailState({ query, back, notFound }) {
  const { t } = useTranslation()
  return (
    <>
      {back}
      {query.isPending ? <p className="text-body text-ink-muted">{t('common.loading')}</p> : null}
      {query.isError ? <Alert tone={query.error?.status === 404 ? 'warning' : 'danger'} title={query.error?.status === 404 ? notFound : errorMessage(query.error)} /> : null}
    </>
  )
}

/** The property picker: nothing when the user has only one. */
export function BranchSwitch({ choice }) {
  const { t } = useTranslation()
  if (choice.branches.length < 2) return null
  return (
    <Select
      label={t('hotel.fields.property')}
      options={choice.branches.map((branch) => ({ value: branch.id, label: branch.name }))}
      value={choice.branch?.id ?? ''}
      onChange={(event) => choice.choose(event.target.value)}
      className="max-w-sm"
    />
  )
}

/** What to show while no property can be chosen. */
export function NoProperty({ choice }) {
  const { t } = useTranslation()
  if (choice.loading) return <p className="text-body text-ink-muted">{t('common.loading')}</p>
  return <Alert tone="info" title={t('hotel.noProperty')} />
}

/** A reason asked for before an action: `path` takes `{reason, ...extra}`. */
export function ReasonDialog({ open, title, help, label, path, extra, optional = false, action, onClose, onDone, children }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const send = useMutation({
    mutationFn: () => api.post(path, { ...(reason.trim() ? { reason: reason.trim() } : {}), ...extra }),
    onSuccess: (response) => {
      setReason('')
      onDone?.(response)
    },
  })
  const close = () => {
    send.reset()
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
            {t('common.cancel')}
          </Button>
          <Button variant="primary" loading={send.isPending} disabled={!optional && !reason.trim()} onClick={() => send.mutate()}>
            {action}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {help ? <p className="text-body text-ink-muted">{help}</p> : null}
        {send.error ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        {children}
        <TextField label={label} value={reason} maxLength={500} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** A payment method picker; `onChange` gets the whole method. */
export function PaymentMethodSelect({ companyId, currency, value, onChange, error, label }) {
  const { t } = useTranslation()
  const { methods } = usePaymentMethods(companyId, currency)
  return (
    <Select
      label={label ?? t('hotel.fields.paymentMethod')}
      placeholder={t('hotel.pickers.paymentMethod')}
      options={methods.map((method) => ({ value: method.id, label: method.name }))}
      value={value?.id ?? ''}
      error={error}
      required
      onChange={(event) => onChange(methods.find((method) => method.id === event.target.value) ?? null)}
    />
  )
}

/** An item picker that searches the server (items?search=); `onChange` gets the whole item. */
export function ItemPicker({ label, value, onChange, error, required, disabled, className }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['items', 'hotel-picker', search],
    queryFn: () => api.get(`items?per_page=20&sort=name${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    staleTime: 30_000,
  })
  const rows = query.data?.data ?? []
  const known = value && !rows.some((item) => item.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label}
      placeholder={t('hotel.pickers.item')}
      options={known.map((item) => ({ value: item.id, label: `${item.name} · ${item.code}` }))}
      value={value?.id ?? ''}
      error={error}
      required={required}
      disabled={disabled}
      onSearchChange={onSearchChange}
      onChange={(event) => onChange(known.find((item) => item.id === event.target.value) ?? null)}
      className={className}
    />
  )
}

/** HOT-16: pick a guest of the company, or add one inline (personal data is entered here, never listed). */
export function GuestField({ companyId, value, onChange, errors = {} }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const [picked, setPicked] = useState(null)
  const query = useQuery({
    queryKey: [...hotelKey, 'guest-picker', companyId, search],
    queryFn: () => api.get(`hotel/guests?per_page=20${companyId ? `&company=${companyId}` : ''}${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    enabled: value.mode === 'existing',
    staleTime: 30_000,
  })
  const rows = query.data?.data ?? []
  const known = picked && !rows.some((guest) => guest.id === picked.id) ? [picked, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  const set = (name) => (event) => onChange({ ...value, [name]: event.target.value })
  const adding = value.mode === 'new'
  return (
    <fieldset className="flex flex-col gap-3">
      <legend className="sr-only">{t('hotel.fields.guest')}</legend>
      {adding ? (
        <div className="grid gap-3 sm:grid-cols-2">
          <TextField label={t('hotel.fields.guestName')} value={value.name} maxLength={160} required error={errors.name} onChange={set('name')} className="sm:col-span-2" />
          <TextField label={t('hotel.fields.phone')} value={value.phone} maxLength={30} error={errors.phone} onChange={set('phone')} />
          <TextField type="email" label={t('hotel.fields.email')} value={value.email} maxLength={190} error={errors.email} onChange={set('email')} />
          <TextField label={t('hotel.fields.nationality')} value={value.nationality} maxLength={60} error={errors.nationality} onChange={set('nationality')} />
          <Select label={t('hotel.fields.idType')} placeholder={t('hotel.pickers.idType')} options={ID_TYPES.map((type) => ({ value: type, label: t(`hotel.idTypes.${type}`) }))} value={value.id_type} error={errors.id_type} onChange={set('id_type')} />
          <TextField label={t('hotel.fields.idNumber')} value={value.id_number} maxLength={60} autoComplete="off" error={errors.id_number} onChange={set('id_number')} className="sm:col-span-2" />
        </div>
      ) : (
        <Select
          label={t('hotel.fields.guest')}
          placeholder={t('hotel.pickers.guest')}
          options={known.map((guest) => ({ value: guest.id, label: guest.phone ? `${guest.name} · ${guest.phone}` : guest.name }))}
          value={value.guest_id}
          required
          error={errors.guest_id}
          onSearchChange={onSearchChange}
          onChange={(event) => {
            setPicked(known.find((guest) => guest.id === event.target.value) ?? null)
            onChange({ ...value, guest_id: event.target.value })
          }}
        />
      )}
      <Button variant="ghost" icon={adding ? 'back' : 'plus'} className="w-fit" onClick={() => onChange({ ...BLANK_GUEST, mode: adding ? 'existing' : 'new' })}>
        {adding ? t('hotel.guestField.existing') : t('hotel.guestField.new')}
      </Button>
    </fieldset>
  )
}

/** HOT-10: the night-by-night quote of a plan for dates and a party, with the estimate of tax and levies. */
export function RateQuote({ planId, arrival, departure, adultCount = 1, childCount = 0, rooms = 1 }) {
  const { t } = useTranslation()
  const ready = Boolean(planId && arrival && departure && departure > arrival)
  const query = useQuery({
    queryKey: [...hotelKey, 'quote', planId, arrival, departure, adultCount, childCount],
    queryFn: () => api.get(`hotel/rate-quote?rate_plan_id=${planId}&arrival_on=${arrival}&departure_on=${departure}&adults=${adultCount}&children=${childCount}`),
    enabled: ready,
    staleTime: 30_000,
  })
  if (!ready) return null
  if (query.isPending) return <p className="text-body text-ink-muted">{t('hotel.quote.loading')}</p>
  if (query.isError) return <Alert tone="danger" title={fieldError(query.error, 'rate_plan_id') ?? errorMessage(query.error)} />
  const quote = query.data.data
  const multiple = BigInt(quote.total_minor) * BigInt(rooms)
  return (
    <section aria-label={t('hotel.quote.title')} className="flex flex-col gap-2 rounded-md border border-border p-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-label text-ink">{t('hotel.quote.title')}</h3>
        <span className="text-caption text-ink-muted">{t(`hotel.boards.${quote.board}`)}</span>
      </div>
      <ul className="flex flex-col gap-1">
        {quote.nights.map((night) => (
          <li key={night.date} className="flex justify-between gap-3 text-body">
            <Day value={night.date} />
            <Amount minor={night.amount_minor} currency={quote.currency} />
          </li>
        ))}
      </ul>
      <div className="flex justify-between gap-3 border-t border-border pt-2 text-body font-medium">
        <span>{rooms > 1 ? t('hotel.quote.totalRooms', { count: rooms }) : t('hotel.quote.total')}</span>
        <Amount minor={rooms > 1 ? multiple.toString() : quote.total_minor} currency={quote.currency} />
      </div>
      <p className="text-caption text-ink-muted">
        {quote.tax_inclusive ? t('hotel.quote.taxInclusive') : t('hotel.quote.taxExtra')}
        {quote.estimate?.flags?.includes('tax_rate_needed') ? ` ${t('hotel.quote.taxRateNeeded')}` : ''}
      </p>
    </section>
  )
}
