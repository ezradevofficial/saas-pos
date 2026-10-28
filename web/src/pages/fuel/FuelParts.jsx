import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, DataTable, Dialog, Select, StatusBadge } from '@/components/ds'
import { formatCompanyTime } from '@/lib/companyTime'
import { formatDecimal, moneyText } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useTimeZone, zoneOfRecord } from '@/lib/useTimeZone'
import { cn } from '@/lib/utils'
import { flagCodes, fuelKey, isNegative, productLabel, STATE_TONES, stationOptions } from './fuelData'

export { Back, Detail, DetailState, DocumentFlow, ReasonDialog } from '../pharmacy/PharmacyParts'

/** Litres (a decimal string with 3 places) in the UI language, tabular, with the unit; negative figures are losses. */
export function Litres({ value, className, signed = false }) {
  const { t } = useTranslation()
  const locale = useLocale()
  if (value === null || value === undefined || value === '') return <span className="text-ink-muted">—</span>
  const text = `${formatDecimal(value, locale, { minDecimals: 3 })} ${t('fuel.units.litres')}`
  return <span className={cn('whitespace-nowrap tabular-nums', signed && isNegative(value) && 'text-danger', className)}>{text}</span>
}

/** A plain decimal (a dip in cm, a reading) in the UI language, tabular. */
export function Figure({ value, unit, className }) {
  const locale = useLocale()
  if (value === null || value === undefined || value === '') return <span className="text-ink-muted">—</span>
  return (
    <span className={cn('whitespace-nowrap tabular-nums', className)}>
      {formatDecimal(value, locale)}
      {unit ? <span className="text-ink-muted">{` ${unit}`}</span> : null}
    </span>
  )
}

/** Money in minor units with the currency code first; a negative amount (short) is red. */
export function Amount({ minor, currency, className, signed = false }) {
  const locale = useLocale()
  if (minor === null || minor === undefined || minor === '') return <span className="text-ink-muted">—</span>
  return <span className={cn('whitespace-nowrap tabular-nums', signed && isNegative(minor) && 'text-danger', className)}>{moneyText(minor, currency, locale)}</span>
}

/** An instant (ISO UTC) in the company's time zone. */
export function When({ value, companyId }) {
  const locale = useLocale()
  const zone = useTimeZone(companyId)
  const text = value ? formatCompanyTime(value, locale, zoneOfRecord(zone)) : null
  return text ? <span className="tabular-nums">{text}</span> : <span className="text-ink-muted">—</span>
}

/** A record status as a dot and a word. */
export function RecordStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATE_TONES[status] ?? 'neutral'}>{t(`fuel.states.${status}`, { defaultValue: status })}</StatusBadge>
}

/** FUEL-18: review flags, each a small dot and a word, never a filled pill. */
export function Flags({ flags }) {
  const { t } = useTranslation()
  const codes = flagCodes(flags)
  if (!codes.length) return <span className="text-ink-muted">—</span>
  return (
    <ul aria-label={t('fuel.flagsLabel')} className="flex flex-wrap gap-x-3 gap-y-1">
      {codes.map((code) => (
        <li key={code}>
          <StatusBadge tone="warning">{t(`fuel.flags.${code}`, { defaultValue: t(`pos.flags.${code}`, { defaultValue: code }) })}</StatusBadge>
        </li>
      ))}
    </ul>
  )
}

/**
 * A page of a fuel list: the endpoint with `params` (filters; empty ones are left out),
 * paged by the API's `page` and `per_page`. `footer(response)` draws what comes
 * with the page (report totals).
 */
export function FuelList({ queryKey, endpoint, params = {}, columns, onRowClick, emptyText, caption, footer, perPage = 25 }) {
  const { t } = useTranslation()
  const signature = JSON.stringify(params)
  const [paging, setPaging] = useState({ signature, page: 1 })
  const page = paging.signature === signature ? paging.page : 1
  const search = new URLSearchParams()
  for (const [name, value] of Object.entries(params)) if (value !== '' && value !== null && value !== undefined) search.set(name, String(value))
  search.set('per_page', String(perPage))
  search.set('page', String(page))
  const query = useQuery({
    queryKey: [...fuelKey, queryKey, signature, page],
    queryFn: () => api.get(`${endpoint}?${search}`),
    placeholderData: keepPreviousData,
  })
  const rows = (query.data?.data ?? []).map((row, index) => ({ ...row, id: row.id ?? `row-${index}` }))
  const last = query.data?.meta?.last_page ?? 1
  const go = (next) => setPaging({ signature, page: next })
  return (
    <div className="flex flex-col gap-3">
      {query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : null}
      <DataTable
        caption={caption}
        columns={columns}
        rows={rows}
        loading={query.isPending}
        stale={query.isPlaceholderData}
        emptyText={emptyText}
        onRowClick={onRowClick}
      />
      {last > 1 ? (
        <nav aria-label={t('fuel.list.pages')} className="flex items-center justify-end gap-3">
          <span className="text-caption text-ink-muted tabular-nums">{t('fuel.list.page', { page, pages: last })}</span>
          <Button disabled={page <= 1} onClick={() => go(page - 1)}>
            {t('fuel.list.previous')}
          </Button>
          <Button disabled={page >= last} onClick={() => go(page + 1)}>
            {t('fuel.list.next')}
          </Button>
        </nav>
      ) : null}
      {footer && query.data ? footer(query.data) : null}
    </div>
  )
}

/** A station picker (the value is the location id). `all` adds an "all stations" choice for filters. */
export function StationSelect({ stations, all = false, label, value, onChange, ...rest }) {
  const { t } = useTranslation()
  const options = [...(all ? [{ value: '', label: t('fuel.filters.allStations') }] : []), ...stationOptions(stations)]
  return <Select label={label ?? t('fuel.fields.station')} placeholder={all ? undefined : t('fuel.pickers.station')} options={options} value={value} onChange={(event) => onChange(event.target.value)} {...rest} />
}

/** A fuel product picker (the value is the fuel product id). */
export function ProductSelect({ products, all = false, label, value, onChange, ...rest }) {
  const { t } = useTranslation()
  const options = [...(all ? [{ value: '', label: t('fuel.filters.allProducts') }] : []), ...products.map((product) => ({ value: product.id, label: productLabel(product) }))]
  return <Select label={label ?? t('fuel.fields.product')} placeholder={all ? undefined : t('fuel.pickers.product')} options={options} value={value} onChange={(event) => onChange(event.target.value)} {...rest} />
}

/** A tank picker. */
export function TankSelect({ tanks, all = false, label, value, onChange, ...rest }) {
  const { t } = useTranslation()
  const options = [...(all ? [{ value: '', label: t('fuel.filters.allTanks') }] : []), ...tanks.map((tank) => ({ value: tank.id, label: `${tank.code} · ${tank.name}` }))]
  return <Select label={label ?? t('fuel.fields.tank')} placeholder={all ? undefined : t('fuel.pickers.tank')} options={options} value={value} onChange={(event) => onChange(event.target.value)} {...rest} />
}

/** A customer or supplier (core party with that role), searched on the server. `value` is the party ({id, name}). */
export function PartyPicker({ partyRole, label, value, onChange, error, required, disabled, className }) {
  const { t } = useTranslation()
  const [search, setSearch] = useState('')
  const query = useQuery({
    queryKey: ['parties', partyRole, 'fuel-picker', search],
    queryFn: () => api.get(`parties?role=${partyRole}&per_page=20${search ? `&search=${encodeURIComponent(search)}` : ''}`),
    staleTime: 30_000,
    retry: false,
  })
  const rows = (query.data?.data ?? []).filter((party) => !party.archived_at)
  const known = value && !rows.some((party) => party.id === value.id) ? [value, ...rows] : rows
  const onSearchChange = useCallback((text) => setSearch(text.trim()), [])
  return (
    <Select
      label={label}
      placeholder={t(`fuel.pickers.${partyRole}`)}
      options={known.map((party) => ({ value: party.id, label: party.name }))}
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

/** A filter bar: its fields side by side. */
export function FilterBar({ children }) {
  return <div className="flex flex-wrap items-end gap-3">{children}</div>
}

/** A decision with one sentence of help, a Cancel and the action named as a verb. */
export function ConfirmDialog({ open, title, text, action, variant = 'primary', loading = false, error, onClose, onConfirm }) {
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
      <div className="flex flex-col gap-3">
        {error ? <Alert tone="danger" title={error} /> : null}
        {text ? <p className="text-body text-ink-muted">{text}</p> : null}
      </div>
    </Dialog>
  )
}

/** The footer of a form dialog: Cancel and the verb that saves. */
export function FormFooter({ action, loading, disabled, onClose, onSubmit }) {
  const { t } = useTranslation()
  return (
    <>
      <Button variant="ghost" onClick={onClose}>
        {t('common.cancel')}
      </Button>
      <Button variant="primary" loading={loading} disabled={disabled} onClick={onSubmit}>
        {action}
      </Button>
    </>
  )
}
