import { useMutation, useQuery } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router'
import { Alert, Button, Checkbox, Money, Select, TextField } from '@/components/ds'
import { apiUrl } from '@/config'
import i18n from '@/i18n'
import { LanguageSwitch } from '@/layouts/AuthLayout'
import { formatAmount } from '@/lib/money'
import { usePageTitle } from '@/lib/usePageTitle'
import { queryString, timeIn } from './salonData'

/** A fresh key for one booking attempt: the same key sent twice books once. */
const newKey = () => globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`

/** The public page's own client: no token, no cookies, no referrer; the answer's own sentence on a refusal. */
async function publicCall(code, suffix, { method = 'GET', body, headers = {} } = {}) {
  let response
  try {
    response = await fetch(`${apiUrl}/api/v1/salon-booking/${encodeURIComponent(code)}${suffix}`, {
      method,
      headers: { Accept: 'application/json', 'Accept-Language': i18n.resolvedLanguage ?? 'en', ...(body ? { 'Content-Type': 'application/json' } : {}), ...headers },
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'omit',
      referrerPolicy: 'no-referrer',
    })
  } catch {
    throw new Error(i18n.t('errors.network'))
  }
  const data = await response.json().catch(() => null)
  if (response.ok) return data?.data
  const first = Object.values(data?.errors ?? {})[0]
  const error = new Error((Array.isArray(first) ? first[0] : first) || data?.message || i18n.t('errors.generic'))
  error.status = response.status
  throw error
}

/** SAL-15, SAL-26: the branch's public booking page: pick services and a time, give a name and a phone or email. No account. */
export default function PublicBooking() {
  const { t } = useTranslation()
  const { code } = useParams()
  const [serviceIds, setServiceIds] = useState([])
  const [staffId, setStaffId] = useState('')
  const [date, setDate] = useState('')
  const [slot, setSlot] = useState(null)
  const [guest, setGuest] = useState({ name: '', phone: '', email: '' })
  const [booked, setBooked] = useState(null)
  const [paying, setPaying] = useState({ phone: '', message: '' })
  const key = useRef(null)
  const menu = useQuery({ queryKey: ['salon-public', code], queryFn: () => publicCall(code, ''), retry: false })
  const data = menu.data
  usePageTitle(data?.name ?? t('salon.public.title'))
  const [now] = useState(() => Date.now())
  const day = date || (data ? new Date(now).toLocaleDateString('en-CA', { timeZone: data.timezone }) : '')
  const ready = Boolean(data && serviceIds.length && day)
  const slots = useQuery({
    queryKey: ['salon-public', code, 'slots', serviceIds.join(','), staffId, day],
    queryFn: () => publicCall(code, `/slots${queryString({ service_ids: serviceIds, date: day, staff_id: staffId })}`),
    enabled: ready,
    retry: false,
  })
  const book = useMutation({
    mutationFn: () => {
      key.current ??= newKey()
      return publicCall(code, '/appointments', {
        method: 'POST',
        headers: { 'Idempotency-Key': key.current },
        body: { guest_name: guest.name.trim(), guest_phone: guest.phone.trim() || undefined, guest_email: guest.email.trim() || undefined, service_ids: serviceIds, staff_id: staffId || undefined, starts_at: slot.starts_at, locale: i18n.resolvedLanguage === 'fr' ? 'fr' : 'en' },
      })
    },
    onSuccess: (result) => {
      setBooked(result)
      setPaying((current) => ({ ...current, phone: guest.phone }))
    },
    onError: () => {
      // A refused time or an error: a fresh attempt gets a fresh key, the list of times reloads.
      key.current = null
      setSlot(null)
      slots.refetch()
    },
  })
  const pay = useMutation({
    mutationFn: () => publicCall(code, `/appointments/${encodeURIComponent(booked.number)}/pay`, { method: 'POST', body: { phone: paying.phone.trim() } }),
    onSuccess: (result) => {
      setBooked(result)
      setPaying((current) => ({ ...current, message: t('salon.public.pushSent') }))
    },
  })
  const status = useMutation({
    mutationFn: () => publicCall(code, `/appointments/${encodeURIComponent(booked.number)}${queryString({ phone: paying.phone.trim() })}`),
    onSuccess: setBooked,
  })
  const contactOk = Boolean(guest.phone.trim() || guest.email.trim())
  const toggle = (id) => {
    setSlot(null)
    setServiceIds((current) => (current.includes(id) ? current.filter((entry) => entry !== id) : [...current, id]))
  }
  const depositDue = booked ? Number(booked.deposit_required_minor) - Number(booked.deposit_paid_minor) : 0
  return (
    <div className="flex min-h-screen flex-col bg-surface-100 text-body text-ink">
      <header className="border-b border-border bg-surface-200">
        <div className="mx-auto flex w-full max-w-content items-center justify-between gap-4 px-4 py-3 md:px-6">
          <h1 className="truncate text-h3 text-ink">{data?.name ?? t('salon.public.title')}</h1>
          <LanguageSwitch />
        </div>
      </header>
      <main className="mx-auto flex w-full max-w-prose flex-1 flex-col gap-5 px-4 py-6 md:px-6">
        {menu.isError ? <Alert tone="danger" title={menu.error.status === 404 ? t('salon.public.notFound') : menu.error.message} /> : null}
        {menu.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
        {booked ? (
          <section aria-label={t('salon.public.confirmed')} className="flex flex-col gap-3 rounded-lg border border-border bg-surface-200 p-5">
            <h2 className="text-h3">{t('salon.public.confirmed')}</h2>
            <p>{t('salon.public.number', { number: booked.number })}</p>
            <p className="tabular-nums">{`${new Date(booked.starts_at).toLocaleDateString(i18n.resolvedLanguage, { timeZone: data.timezone, dateStyle: 'full' })}, ${timeIn(booked.starts_at, data.timezone)}`}</p>
            <p>{booked.services.join(', ')}</p>
            <p>
              {t('salon.public.total')} <Money amount={booked.total_minor} currency={booked.currency} />
            </p>
            {depositDue > 0 ? (
              <div className="flex flex-col gap-3">
                <Alert tone="warning" title={t('salon.public.depositDue', { amount: `${booked.currency} ${formatAmount(depositDue, booked.currency, i18n.resolvedLanguage)}` })} />
                <TextField label={t('salon.public.payPhone')} help={t('salon.public.payPhoneHelp')} inputMode="tel" value={paying.phone} onChange={(event) => setPaying((current) => ({ ...current, phone: event.target.value }))} />
                {pay.isError ? <Alert tone="danger" title={pay.error.message} /> : null}
                {status.isError ? <Alert tone="danger" title={status.error.message} /> : null}
                {paying.message ? <p className="text-ink-muted">{paying.message}</p> : null}
                <div className="flex gap-2">
                  <Button variant="primary" loading={pay.isPending} disabled={!paying.phone.trim()} onClick={() => pay.mutate()}>
                    {t('salon.public.payDeposit')}
                  </Button>
                  <Button loading={status.isPending} disabled={!paying.phone.trim()} onClick={() => status.mutate()}>
                    {t('salon.public.checkPayment')}
                  </Button>
                </div>
              </div>
            ) : null}
            <p className="text-caption text-ink-muted">{t('salon.public.reminder')}</p>
          </section>
        ) : data ? (
          <>
            <fieldset className="flex flex-col gap-3">
              <legend className="text-h3 text-ink">{t('salon.public.services')}</legend>
              {data.services.map((service) => (
                <div key={service.id} className="flex items-center justify-between gap-3">
                  <Checkbox label={`${service.name} · ${t('salon.services.minutes', { count: service.duration_minutes })}`} checked={serviceIds.includes(service.id)} onChange={() => toggle(service.id)} />
                  {service.price_minor == null ? null : <Money amount={service.price_minor} currency={data.currency} />}
                </div>
              ))}
            </fieldset>
            <div className="grid grid-cols-2 gap-4">
              <Select label={t('salon.public.staff')} options={[{ value: '', label: t('salon.book.anyStaff') }, ...data.staff.map((member) => ({ value: member.id, label: member.name }))]} value={staffId} onChange={(event) => { setSlot(null); setStaffId(event.target.value) }} />
              <TextField label={t('salon.book.date')} type="date" value={day} onChange={(event) => { setSlot(null); setDate(event.target.value) }} />
            </div>
            {ready ? (
              <div className="flex flex-col gap-2">
                <h2 className="text-h3 text-ink">{t('salon.public.times')}</h2>
                {slots.isPending ? <p className="text-ink-muted">{t('common.loading')}</p> : null}
                {slots.isError ? <Alert tone="danger" title={slots.error.message} /> : null}
                {slots.data && !slots.data.length ? <p className="text-ink-muted">{t('salon.book.noSlots')}</p> : null}
                <div role="group" aria-label={t('salon.book.times')} className="flex flex-wrap gap-2">
                  {(slots.data ?? []).slice(0, 48).map((entry) => (
                    <Button key={entry.starts_at} variant={slot?.starts_at === entry.starts_at ? 'primary' : 'secondary'} aria-pressed={slot?.starts_at === entry.starts_at} onClick={() => setSlot(entry)}>
                      {timeIn(entry.starts_at, data.timezone)}
                    </Button>
                  ))}
                </div>
              </div>
            ) : null}
            {slot ? (
              <div className="flex flex-col gap-4">
                <h2 className="text-h3 text-ink">{t('salon.public.you')}</h2>
                <TextField label={t('salon.public.name')} value={guest.name} onChange={(event) => setGuest((current) => ({ ...current, name: event.target.value }))} />
                <div className="grid grid-cols-2 gap-4">
                  <TextField label={t('salon.book.guestPhone')} inputMode="tel" value={guest.phone} onChange={(event) => setGuest((current) => ({ ...current, phone: event.target.value }))} />
                  <TextField label={t('salon.clients.email')} type="email" value={guest.email} onChange={(event) => setGuest((current) => ({ ...current, email: event.target.value }))} />
                </div>
                <p className="text-caption text-ink-muted">{data.deposit_required ? t('salon.public.depositNote', { percent: data.deposit_percent }) : t('salon.public.privacy')}</p>
                {book.isError ? <Alert tone="danger" title={book.error.message} /> : null}
                <div>
                  <Button variant="primary" loading={book.isPending} disabled={!guest.name.trim() || !contactOk} onClick={() => book.mutate()}>
                    {t('salon.public.book', { time: timeIn(slot.starts_at, data.timezone) })}
                  </Button>
                </div>
              </div>
            ) : null}
          </>
        ) : null}
      </main>
    </div>
  )
}
