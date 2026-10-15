import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, DecimalInput, Dialog, ListView, Money, MoneyInput, PercentInput, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { useDebounced } from '@/lib/useDebounced'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { useUoms } from '@/pages/catalogue/catalogueData'
import { dateField } from '@/pages/inventory/inventoryHelpers'
import { Amount, Back, CalendarDate, CustomerPicker, Detail, DetailState, DocumentFlow, ItemPicker, ReasonDialog, Status, TextAreaField, When } from './CrmParts'
import { useCrmAction, useDownload } from './crmHelpers'
import { companyOfBranch, crmKey, fieldError, namedOptions, QUOTE_CREATE, QUOTE_STATUSES, statusLabel, useCurrencies, usePlaces } from './crmData'

const quotesKey = [...crmKey, 'quotes']

/** CRM-17, CRM-32: quotes with number, status, customer, branch, dates and total. */
export default function Quotes() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { can } = usePermissions()
  const places = usePlaces()
  const columns = [
    { key: 'number', label: t('crm.columns.number'), sortKey: 'number', hideable: false, render: (row) => <span className="font-medium text-ink tabular-nums">{row.number ?? t('crm.quote.draftNumber')}</span> },
    { key: 'status', label: t('crm.columns.status'), sortKey: 'status', render: (row) => <Status status={row.status} /> },
    { key: 'party', label: t('crm.columns.customer'), render: (row) => row.party?.name ?? '' },
    { key: 'branch', label: t('crm.columns.branch'), render: (row) => row.branch?.name ?? '' },
    { key: 'quote_date', label: t('crm.columns.quoteDate'), sortKey: 'quote_date', render: (row) => <CalendarDate value={row.quote_date} /> },
    { key: 'valid_until', label: t('crm.columns.validUntil'), sortKey: 'valid_until', render: (row) => <CalendarDate value={row.valid_until} /> },
    { key: 'total', label: t('crm.columns.total'), align: 'end', render: (row) => (row.totals ? <Money amount={row.totals.total_minor} currency={row.currency} /> : '') },
    { key: 'owner', label: t('crm.columns.owner'), defaultHidden: true, render: (row) => row.owner?.name ?? '' },
    { key: 'created_at', label: t('crm.columns.createdAt'), sortKey: 'created_at', defaultHidden: true, render: (row) => <When value={row.created_at} companyId={row.company?.id} /> },
  ]
  const list = useServerList({ id: 'crm-quotes', endpoint: 'crm/quotes', queryKey: quotesKey, filters: { status: '', branch: '', from: '', to: '' }, defaultSort: '-created_at', columns })
  const all = (name) => ({ value: '', label: t(`crm.filters.all.${name}`) })
  const filterFields = [
    { name: 'status', label: t('crm.filters.status'), options: [all('status'), ...QUOTE_STATUSES.map((status) => ({ value: status, label: statusLabel(t, status) }))] },
    ...(places.branches.length ? [{ name: 'branch', label: t('crm.filters.branch'), options: [all('branch'), ...namedOptions(places.branches)] }] : []),
    dateField('from', t('crm.filters.from')),
    dateField('to', t('crm.filters.to')),
  ]
  const filtered = list.term || Object.values(list.filters).some(Boolean)
  return (
    <>
      <PageHeader
        title={t('crm.quotes.title')}
        description={t('crm.quotes.description')}
        actions={can(QUOTE_CREATE) ? <Button variant="primary" icon="plus" onClick={() => navigate('/crm/quotes/new')}>{t('crm.quotes.new')}</Button> : null}
      />
      <ListView
        list={list}
        title={t('crm.quotes.title')}
        searchLabel={t('crm.quotes.search')}
        filterFields={filterFields}
        emptyText={filtered ? t('crm.list.emptyFiltered') : t('crm.quotes.empty')}
        onRowClick={(row) => navigate(`/crm/quotes/${row.id}`)}
      />
    </>
  )
}

let nextKey = 0
/** An editable quote line; `typed` marks a price the user typed (else the price list's applies). */
const blankLine = (extra = {}) => ({ key: `quote-line-${(nextKey += 1)}`, item: null, description: '', uom_id: '', qty: '1', price_minor: '', typed: false, seed: '', discount_percent: '', tax_code_id: '', ...extra })

/** A saved line as an editable one (a price other than the list price was typed). */
const lineFromRecord = (line) =>
  blankLine({
    item: line.item ?? null,
    description: line.description ?? '',
    uom_id: line.uom?.id ?? '',
    uom: line.uom ?? null,
    qty: line.qty ?? '',
    price_minor: line.unit_price_minor ?? '',
    typed: !line.item || line.list_price_minor === null || line.list_price_minor !== line.unit_price_minor,
    seed: 'saved',
    discount_percent: line.discount_percent && Number(line.discount_percent) !== 0 ? line.discount_percent : '',
    tax_code_id: line.item ? '' : (line.tax_code?.id ?? ''),
  })

/** Whether a line says what it is (an item or a description) and its quantity, so it can be priced. */
const lineReady = (line) => Boolean((line.item || line.description.trim()) && line.qty && line.price_minor !== null && line.discount_percent !== null)

/** A line as the API takes it (ADR 003: money in minor units, quantities and percents as decimal strings). */
function lineBody(line) {
  return {
    ...(line.item ? { item_id: line.item.id } : {}),
    ...(line.uom_id ? { uom_id: line.uom_id } : {}),
    ...(line.description.trim() ? { description: line.description.trim() } : {}),
    qty: line.qty,
    ...(line.typed && line.price_minor !== '' ? { unit_price_minor: line.price_minor } : {}),
    ...(line.discount_percent ? { discount_percent: line.discount_percent } : {}),
    ...(!line.item && line.tax_code_id ? { tax_code_id: line.tax_code_id } : {}),
  }
}

const QUOTE_FIELDS = ['branch_id', 'party_id', 'currency', 'quote_date', 'valid_until', 'terms', 'notes', 'lines']
const LINE_FIELDS = ['item_id', 'uom_id', 'description', 'qty', 'unit_price_minor', 'discount_percent', 'tax_code_id']

/** A rate (decimal string) as "16 %", or nothing. */
function Rate({ value }) {
  const locale = useLocale()
  if (value === null || value === undefined || value === '') return null
  return <span className="tabular-nums">{`${formatDecimal(String(value), locale)} %`}</span>
}

/** CRM-19: a line's tax: its code and rate, or "Rate needed" when the code has no confirmed rate on the quote date. */
function LineTax({ line }) {
  const { t } = useTranslation()
  if (line?.rate_needed) return <StatusBadge tone="warning">{t('crm.quote.rateNeeded')}</StatusBadge>
  if (!line?.tax_code) return <span className="text-ink-muted">—</span>
  return (
    <span className="flex flex-col">
      <span>{line.tax_code.name ?? line.tax_code.code}</span>
      <span className="text-caption text-ink-muted"><Rate value={line.tax_rate} /></span>
    </span>
  )
}

/** CRM-18: the quote's totals in its currency, code first. */
function Totals({ totals, currency }) {
  const { t } = useTranslation()
  if (!totals || !currency) return null
  return (
    <dl className="flex flex-col gap-2" aria-label={t('crm.quote.totals')}>
      {['subtotal', 'discount', 'tax'].map((name) => (
        <div key={name} className="flex items-baseline justify-between gap-4">
          <dt className="text-ink-muted">{t(`crm.quote.${name}`)}</dt>
          <dd><Money amount={totals[`${name}_minor`]} currency={currency} /></dd>
        </div>
      ))}
      <div className="flex items-baseline justify-between gap-4 border-t border-border pt-2">
        <dt className="font-medium text-ink">{t('crm.quote.total')}</dt>
        <dd data-testid="quote-total"><Money amount={totals.total_minor} currency={currency} size="lg" /></dd>
      </div>
    </dl>
  )
}

/** The units an item line may use: the item's base unit and its other units. */
function useUnitOptions() {
  const { t } = useTranslation()
  const uoms = useUoms()
  const label = (id, code) => {
    const uom = uoms.all.find((entry) => entry.id === id)
    return uom ? (uom.code ? `${uom.code.toUpperCase()} · ${uom.name}` : uom.name) : (code ?? t('crm.quote.baseUnit'))
  }
  return (line) => {
    const options = []
    if (line.item?.base_uom_id) options.push({ value: line.item.base_uom_id, label: label(line.item.base_uom_id) })
    for (const unit of line.item?.uoms ?? []) if (!options.some((option) => option.value === unit.uom_id)) options.push({ value: unit.uom_id, label: label(unit.uom_id, unit.code) })
    if (line.uom_id && !options.some((option) => option.value === line.uom_id)) options.push({ value: line.uom_id, label: label(line.uom_id, line.uom?.code) })
    if (!line.item) return uoms.active.map((uom) => ({ value: uom.id, label: label(uom.id) }))
    return options
  }
}

/**
 * CRM-17..CRM-20: a quote's lines: item (or description), unit, quantity,
 * unit price (the price list's unless one is typed), discount percent and,
 * for a description line, a tax code; each line's price, tax and problems
 * from the preview.
 */
function QuoteLines({ lines, setLines, currency, previewLines, taxCodes, errors, submitted }) {
  const { t } = useTranslation()
  const unitOptions = useUnitOptions()
  const update = (key, patch) => setLines((current) => current.map((line) => (line.key === key ? { ...line, ...patch } : line)))
  const error = (index, name) => errors.fields[`lines.${index}.${name}`]
  // The preview prices only the lines that are ready, in order: each ready line's place among them.
  const readyLines = lines.filter(lineReady)
  return (
    <section aria-label={t('crm.quote.lines')} className="flex flex-col gap-3">
      <h2 className="text-h3 text-ink">{t('crm.quote.lines')}</h2>
      {errors.fields.lines ? <Alert tone="danger" title={errors.fields.lines} /> : null}
      {lines.map((line, index) => {
        const number = index + 1
        const preview = lineReady(line) ? previewLines?.[readyLines.indexOf(line)] : null
        const shownPrice = line.typed ? line.price_minor : (preview?.unit_price_minor ?? '')
        const priceKey = `${line.key}-${line.typed ? line.seed : (preview?.unit_price_minor ?? 'none')}-${currency}`
        return (
          <fieldset key={line.key} className="flex flex-col gap-3 rounded-md border border-border p-4">
            <legend className="px-1 text-label text-ink">{t('crm.quote.line', { number })}</legend>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <ItemPicker
                label={t('crm.fields.item')}
                placeholder={t('crm.pickers.item')}
                type={null}
                value={line.item}
                error={error(index, 'item_id')}
                onChange={(item) => update(line.key, { item, uom_id: item?.base_uom_id ?? '', typed: false, price_minor: '', tax_code_id: '' })}
                className="sm:col-span-2"
              />
              <TextField
                label={t('crm.fields.description')}
                help={line.item ? t('crm.quote.descriptionOptional') : t('crm.quote.descriptionHelp')}
                value={line.description}
                maxLength={500}
                error={error(index, 'description') ?? (submitted && !line.item && !line.description.trim() ? t('crm.validation.itemOrDescription') : undefined)}
                onChange={(event) => update(line.key, { description: event.target.value })}
                className="sm:col-span-2"
              />
              <Select label={t('crm.fields.unit')} placeholder={t('crm.pickers.unit')} options={unitOptions(line)} value={line.uom_id} error={error(index, 'uom_id')} onChange={(event) => update(line.key, { uom_id: event.target.value })} />
              <DecimalInput
                label={t('crm.fields.qty')}
                value={line.qty}
                required
                showErrors={submitted}
                error={error(index, 'qty') ?? (submitted && !line.qty ? t('crm.validation.qty') : undefined)}
                onChange={(value) => update(line.key, { qty: value ?? '' })}
              />
              <div className="flex flex-col gap-1">
                <MoneyInput
                  key={priceKey}
                  label={t('crm.fields.unitPrice')}
                  currency={currency}
                  value={shownPrice}
                  showErrors={submitted}
                  help={line.item && !line.typed ? t('crm.quote.listPriceHelp') : undefined}
                  error={error(index, 'unit_price_minor')}
                  onChange={(value) => update(line.key, { price_minor: value, typed: true, seed: line.typed ? line.seed : (preview?.unit_price_minor ?? 'none') })}
                />
                {line.item && line.typed ? (
                  <Button variant="ghost" className="w-fit" onClick={() => update(line.key, { typed: false, price_minor: '' })}>
                    {t('crm.quote.useListPrice')}
                  </Button>
                ) : null}
              </div>
              <PercentInput label={t('crm.fields.discountPercent')} value={line.discount_percent} error={error(index, 'discount_percent')} onChange={(value) => update(line.key, { discount_percent: value })} />
              {line.item ? (
                <div className="flex flex-col gap-1">
                  <span className="text-label text-ink">{t('crm.fields.taxCode')}</span>
                  <LineTax line={preview} />
                </div>
              ) : (
                <Select
                  label={t('crm.fields.taxCode')}
                  placeholder={t('crm.pickers.taxCode')}
                  options={taxCodes.map((code) => ({ value: code.id, label: code.rate_needed ? t('crm.quote.rateNeededCode', { name: code.name }) : code.name }))}
                  value={line.tax_code_id}
                  required
                  error={error(index, 'tax_code_id') ?? (submitted && !line.tax_code_id ? t('crm.validation.taxCode') : undefined)}
                  onChange={(event) => update(line.key, { tax_code_id: event.target.value })}
                />
              )}
              <div className="flex flex-col gap-1">
                <span className="text-label text-ink">{t('crm.quote.lineTotal')}</span>
                {preview ? <Money amount={preview.total_minor} currency={currency} /> : <span className="text-ink-muted">—</span>}
              </div>
            </div>
            {(preview?.problems ?? []).map((problem) => (
              <Alert key={problem.code} tone="warning" title={problem.message} />
            ))}
            {lines.length > 1 ? (
              <div>
                <Button variant="ghost" icon="remove" aria-label={t('crm.quote.removeLine', { number })} onClick={() => setLines((current) => current.filter((entry) => entry.key !== line.key))}>
                  {t('crm.quote.removeShort')}
                </Button>
              </div>
            ) : null}
          </fieldset>
        )
      })}
      <div>
        <Button icon="plus" onClick={() => setLines((current) => [...current, blankLine()])}>{t('crm.quote.addLine')}</Button>
      </div>
    </section>
  )
}

/** MD-03: a company's tax codes (a code without a rate in force shows "Rate needed"). */
function useTaxCodes(companyId) {
  const query = useQuery({ queryKey: ['tax-codes', 'crm', companyId], queryFn: () => api.get(`companies/${companyId}/tax-codes?per_page=200`), enabled: Boolean(companyId), staleTime: 60_000, retry: false })
  return (query.data?.data ?? []).filter((code) => !code.archived_at)
}

function QuoteForm({ quote, opportunity }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const places = usePlaces()
  const editing = Boolean(quote)
  const [branch, setBranch] = useState(quote?.branch?.id ?? opportunity?.branch?.id ?? '')
  const companyId = companyOfBranch(places, branch) ?? quote?.company?.id ?? null
  const currencies = useCurrencies(companyId)
  const taxCodes = useTaxCodes(companyId)
  const [party, setParty] = useState(quote?.party ?? opportunity?.party ?? null)
  const [currency, setCurrency] = useState(quote?.currency ?? opportunity?.value?.currency ?? '')
  const [quoteDate, setQuoteDate] = useState(quote?.quote_date ?? '')
  const [validUntil, setValidUntil] = useState(quote?.valid_until ?? '')
  const [terms, setTerms] = useState(quote?.terms ?? '')
  const [notes, setNotes] = useState(quote?.notes ?? '')
  const [lines, setLines] = useState(() => (quote?.lines?.length ? quote.lines.map(lineFromRecord) : [blankLine()]))
  const [submitted, setSubmitted] = useState(false)

  const body = {
    branch_id: branch,
    party_id: party?.id ?? null,
    ...(opportunity || quote?.opportunity ? { opportunity_id: opportunity?.id ?? quote.opportunity.id } : {}),
    ...(currency ? { currency } : {}),
    ...(quoteDate ? { quote_date: quoteDate } : {}),
    ...(validUntil ? { valid_until: validUntil } : {}),
    terms: terms.trim() || null,
    notes: notes.trim() || null,
    lines: lines.filter(lineReady).map(lineBody),
  }
  const payload = JSON.stringify(body)
  const settled = useDebounced(payload, 400)
  const previewable = Boolean(branch && party && body.lines.length)
  // CRM-18: live totals from the server's own maths (never computed here), for what the form says now.
  const preview = useQuery({
    queryKey: [...crmKey, 'quote-preview', settled],
    queryFn: () => api.post('crm/quotes/preview', JSON.parse(settled)),
    enabled: previewable && settled === payload,
    placeholderData: keepPreviousData,
    retry: false,
  })
  const previewData = previewable ? preview.data?.data : null
  const shownCurrency = previewData?.currency ?? (currency || currencies.base)

  const save = useMutation({
    mutationFn: () => (editing ? api.patch(`crm/quotes/${quote.id}`, body) : api.post('crm/quotes', body)),
    onSuccess: async (response) => {
      toast.success(t(editing ? 'crm.quote.saved' : 'crm.quote.created'))
      await queryClient.invalidateQueries({ queryKey: crmKey })
      navigate(`/crm/quotes/${response.data.id}`)
    },
  })
  const errors = formErrors(save.error, [...QUOTE_FIELDS, ...lines.flatMap((_, index) => LINE_FIELDS.map((name) => `lines.${index}.${name}`))])
  const ready = branch && party && lines.every((line) => lineReady(line) && (line.item || line.tax_code_id))
  const submit = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <form noValidate onSubmit={submit} className="flex flex-col gap-5">
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      {opportunity ? <Alert tone="info" title={t('crm.quote.fromOpportunity', { name: opportunity.name })} /> : null}
      <Card>
        <div className="grid gap-4 sm:grid-cols-2">
          <CustomerPicker value={party} onChange={setParty} required disabled={Boolean(opportunity)} error={errors.fields.party_id ?? (submitted && !party ? t('crm.validation.customer') : undefined)} />
          <Select
            label={t('crm.fields.branch')}
            placeholder={t('crm.pickers.branch')}
            options={namedOptions(places.activeBranches)}
            value={branch}
            required
            disabled={editing}
            error={errors.fields.branch_id ?? (submitted && !branch ? t('crm.validation.branch') : undefined)}
            onChange={(event) => setBranch(event.target.value)}
          />
          <Select
            label={t('crm.fields.currency')}
            help={t('crm.quote.currencyHelp')}
            options={[{ value: '', label: t('crm.quote.currencyAuto') }, ...currencies.options]}
            value={currency}
            error={errors.fields.currency}
            onChange={(event) => {
              if (event.target.value !== currency) setLines((current) => current.map((line) => ({ ...line, typed: line.item ? false : line.typed, price_minor: '' })))
              setCurrency(event.target.value)
            }}
          />
          <TextField type="date" label={t('crm.fields.quoteDate')} help={t('crm.quote.quoteDateHelp')} value={quoteDate} error={errors.fields.quote_date} onChange={(event) => setQuoteDate(event.target.value)} />
          <TextField type="date" label={t('crm.fields.validUntil')} help={t('crm.quote.validUntilHelp')} value={validUntil} error={errors.fields.valid_until} onChange={(event) => setValidUntil(event.target.value)} />
          <TextAreaField label={t('crm.fields.terms')} value={terms} maxLength={5000} error={errors.fields.terms} onChange={(event) => setTerms(event.target.value)} className="sm:col-span-2" />
          <TextAreaField label={t('crm.fields.notes')} value={notes} maxLength={5000} error={errors.fields.notes} onChange={(event) => setNotes(event.target.value)} className="sm:col-span-2" />
        </div>
      </Card>
      <QuoteLines lines={lines} setLines={setLines} currency={shownCurrency} previewLines={previewData?.lines} taxCodes={taxCodes} errors={errors} submitted={submitted} />
      <Card title={t('crm.quote.totals')} subtitle={previewData ? [previewData.price_list?.name, previewData.tax_inclusive ? t('crm.quote.taxInclusive') : t('crm.quote.taxExclusive')].filter(Boolean).join(' · ') : null}>
        {preview.isError && previewable ? <Alert tone="warning" title={errorMessage(preview.error)} /> : null}
        {previewData ? <Totals totals={previewData.totals} currency={previewData.currency} /> : <p className="text-body text-ink-muted">{t('crm.quote.previewHelp')}</p>}
      </Card>
      <div className="flex flex-wrap gap-2">
        <Button variant="ghost" onClick={() => navigate(editing ? `/crm/quotes/${quote.id}` : '/crm/quotes')}>{t('common.cancel')}</Button>
        <Button type="submit" variant="primary" loading={save.isPending}>{t('crm.quote.saveDraft')}</Button>
      </div>
    </form>
  )
}

/** CRM-17..CRM-20: a new quote (blank, or for the opportunity named by `?opportunity=`) or a draft's changes, with live totals. */
export function QuoteEditor() {
  const { t } = useTranslation()
  const { quoteId } = useParams()
  const [params] = useSearchParams()
  const opportunityId = quoteId ? null : params.get('opportunity')
  const query = useQuery({ queryKey: [...crmKey, 'quote', quoteId], queryFn: () => api.get(`crm/quotes/${quoteId}`), enabled: Boolean(quoteId) })
  const source = useQuery({ queryKey: [...crmKey, 'opportunity', opportunityId], queryFn: () => api.get(`crm/opportunities/${opportunityId}`), enabled: Boolean(opportunityId) })
  const back = <Back to={quoteId ? `/crm/quotes/${quoteId}` : '/crm/quotes'}>{t('crm.quotes.back')}</Back>
  if (quoteId && !query.data?.data) return <DetailState query={query} back={back} notFound={t('crm.quote.notFound')} />
  if (opportunityId && !source.data?.data) return <DetailState query={source} back={back} notFound={t('crm.opportunity.notFound')} />
  return (
    <>
      {back}
      <PageHeader title={t(quoteId ? 'crm.quote.editTitle' : 'crm.quote.newTitle')} description={t('crm.quote.formHelp')} />
      <QuoteForm key={quoteId ?? opportunityId ?? 'new'} quote={query.data?.data} opportunity={source.data?.data} />
    </>
  )
}

/** CRM-22: send the quote's PDF by email (an address, or the customer's) in English or French. */
function SendDialog({ quote, onClose, onDone }) {
  const { t, i18n } = useTranslation()
  const [email, setEmail] = useState('')
  const [locale, setLocale] = useState(i18n.resolvedLanguage === 'fr' ? 'fr' : 'en')
  const send = useMutation({ mutationFn: () => api.post(`crm/quotes/${quote.id}/send`, { ...(email.trim() ? { email: email.trim() } : {}), locale }), onSuccess: onDone })
  return (
    <Dialog
      open
      title={t('crm.quote.sendTitle', { number: quote.number ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant="primary" icon="mail" loading={send.isPending} onClick={() => send.mutate()}>{t('crm.quote.send')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('crm.quote.sendHelp')}</p>
        {send.isError && !fieldError(send.error, 'email') ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        <TextField type="email" label={t('crm.fields.email')} help={t('crm.quote.emailHelp')} value={email} maxLength={254} error={fieldError(send.error, 'email')} onChange={(event) => setEmail(event.target.value)} />
        <Select label={t('crm.fields.language')} options={[{ value: 'en', label: t('crm.languages.en') }, { value: 'fr', label: t('crm.languages.fr') }]} value={locale} error={fieldError(send.error, 'locale')} onChange={(event) => setLocale(event.target.value)} />
      </div>
    </Dialog>
  )
}

/** CRM-23: record the customer's acceptance, optionally winning the opportunity in the same step. */
function AcceptDialog({ quote, onClose, onDone }) {
  const { t } = useTranslation()
  const [win, setWin] = useState(Boolean(quote.opportunity))
  const accept = useMutation({ mutationFn: () => api.post(`crm/quotes/${quote.id}/accept`, quote.opportunity ? { win_opportunity: win } : {}), onSuccess: onDone })
  return (
    <Dialog
      open
      title={t('crm.quote.acceptTitle', { number: quote.number ?? '' })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>{t('crm.actions.back')}</Button>
          <Button variant="primary" loading={accept.isPending} onClick={() => accept.mutate()}>{t('crm.quote.accept')}</Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('crm.quote.acceptHelp')}</p>
        {accept.isError ? <Alert tone="danger" title={errorMessage(accept.error)} /> : null}
        {quote.opportunity ? <Checkbox label={t('crm.quote.winOpportunity', { name: quote.opportunity.name })} checked={win} onChange={(event) => setWin(event.target.checked)} /> : null}
      </div>
    </Dialog>
  )
}

/** CRM-22: the quote's public links (WhatsApp shares): when made, until when, opens, and Revoke. */
function Shares({ quote }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const key = [...crmKey, 'quote-shares', quote.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/quotes/${quote.id}/shares`), retry: false })
  const revoke = useMutation({
    mutationFn: (share) => api.post(`crm/quotes/${quote.id}/shares/${share.id}/revoke`),
    onSuccess: () => {
      toast.success(t('crm.quote.shareRevoked'))
      queryClient.invalidateQueries({ queryKey: key })
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
  const rows = query.data?.data ?? []
  const columns = [
    { key: 'created_at', label: t('crm.columns.createdAt'), render: (row) => <When value={row.created_at} companyId={quote.company?.id} /> },
    { key: 'expires_at', label: t('crm.columns.expiresAt'), render: (row) => <When value={row.expires_at} companyId={quote.company?.id} /> },
    { key: 'status', label: t('crm.columns.status'), render: (row) => <Status status={row.status} /> },
    { key: 'access_count', label: t('crm.columns.opens'), align: 'end', numeric: true, render: (row) => row.access_count ?? 0 },
    {
      key: 'actions',
      label: t('crm.columns.actions'),
      align: 'end',
      render: (row) => (row.status === 'active' && !row.revoked_at ? <Button variant="ghost" loading={revoke.isPending && revoke.variables?.id === row.id} onClick={() => revoke.mutate(row)}>{t('crm.quote.revoke')}</Button> : null),
    },
  ]
  if (!rows.length) return null
  return (
    <Card title={t('crm.quote.shares')}>
      <DataTable caption={t('crm.quote.shares')} columns={columns} rows={rows} />
    </Card>
  )
}

/**
 * CRM-17..CRM-24, WF-10: one quote: status and number, customer, dates,
 * lines with discount and tax rate, totals, where its approval flow is,
 * and Submit, Edit, PDF, Send, Share on WhatsApp, Accept, Decline,
 * Convert to invoice and Cancel as the reader may (`can`).
 */
export function QuoteDetail() {
  const { t } = useTranslation()
  const { quoteId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [dialog, setDialog] = useState(null)
  const [convertError, setConvertError] = useState(null)
  const key = [...crmKey, 'quote', quoteId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`crm/quotes/${quoteId}`) })
  const action = useCrmAction(key)
  const convert = useCrmAction(key, { onError: (error) => setConvertError(errorMessage(error)) })
  const download = useDownload()
  const share = useMutation({
    mutationFn: () => api.post(`crm/quotes/${quoteId}/share`),
    onSuccess: (response) => {
      const url = response?.data?.whatsapp_url
      if (url) window.open(url, '_blank', 'noopener,noreferrer')
      toast.success(t('crm.quote.shared'))
      queryClient.invalidateQueries({ queryKey: [...crmKey, 'quote-shares', quoteId] })
    },
    onError: (error) => toast.error(errorMessage(error)),
  })
  const quote = query.data?.data
  const back = <Back to="/crm/quotes">{t('crm.quotes.back')}</Back>
  if (!quote) return <DetailState query={query} back={back} notFound={t('crm.quote.notFound')} />

  const can = quote.can ?? {}
  const company = quote.company?.id
  const rateNeeded = (quote.lines ?? []).some((line) => line.rate_needed)
  const title = quote.number ?? t('crm.quote.draftNumber')
  const done = async (response, message) => {
    setDialog(null)
    if (response?.data) queryClient.setQueryData(key, response)
    toast.success(t(message))
    await queryClient.invalidateQueries({ queryKey: crmKey })
  }
  const columns = [
    { key: 'line_no', label: t('crm.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    {
      key: 'item',
      label: t('crm.columns.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name ?? line.description}</span>
          {line.item ? <span className="font-mono text-caption text-ink-muted">{line.item.code}</span> : null}
          {line.item && line.description && line.description !== line.item.name ? <span className="text-caption text-ink-muted">{line.description}</span> : null}
        </span>
      ),
    },
    { key: 'qty', label: t('crm.columns.qty'), align: 'end', render: (line) => <QtyText value={line.qty} unit={line.uom?.code} /> },
    { key: 'unit_price', label: t('crm.columns.unitPrice'), align: 'end', render: (line) => <Money amount={line.unit_price_minor} currency={quote.currency} /> },
    { key: 'discount', label: t('crm.columns.discount'), align: 'end', render: (line) => (line.discount_percent && Number(line.discount_percent) !== 0 ? <Rate value={line.discount_percent} /> : <span className="text-ink-muted">—</span>) },
    { key: 'tax', label: t('crm.columns.tax'), wrap: true, render: (line) => <LineTax line={line} /> },
    { key: 'total', label: t('crm.columns.lineTotal'), align: 'end', render: (line) => <Money amount={line.total_minor} currency={quote.currency} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('crm.quote.eyebrow')}
        title={title}
        description={
          <span className="flex flex-wrap items-center gap-3">
            <Status status={quote.status} />
            {quote.expired && !['accepted', 'declined', 'converted', 'cancelled'].includes(quote.status) ? <StatusBadge tone="warning">{t('crm.quote.expired')}</StatusBadge> : null}
            <span className="text-ink-muted">{quote.party?.name}</span>
          </span>
        }
        actions={
          <>
            {can.edit ? <Button icon="edit" onClick={() => navigate(`/crm/quotes/${quote.id}/edit`)}>{t('crm.actions.edit')}</Button> : null}
            {can.cancel ? <Button variant="danger" onClick={() => setDialog('cancel')}>{t('crm.quote.cancel')}</Button> : null}
            <Button icon="print" loading={download.isPending} onClick={() => download.mutate({ path: `crm/quotes/${quote.id}/pdf`, name: `${quote.number ?? 'quote'}.pdf` })}>{t('crm.quote.pdf')}</Button>
            {can.send ? <Button icon="mail" onClick={() => setDialog('send')}>{t('crm.quote.send')}</Button> : null}
            {can.send ? <Button icon="whatsapp" loading={share.isPending} onClick={() => share.mutate()}>{t('crm.quote.share')}</Button> : null}
            {can.accept ? <Button onClick={() => setDialog('decline')}>{t('crm.quote.decline')}</Button> : null}
            {can.accept ? <Button variant="primary" onClick={() => setDialog('accept')}>{t('crm.quote.accept')}</Button> : null}
            {can.convert ? (
              <Button variant="pay" loading={convert.isPending} onClick={() => { setConvertError(null); convert.mutate({ path: `crm/quotes/${quote.id}/convert`, body: { target: 'invoice' }, done: 'crm.quote.converted' }) }}>
                {t('crm.quote.convert')}
              </Button>
            ) : null}
            {can.submit ? (
              <Button variant="pay" loading={action.isPending} disabled={rateNeeded} onClick={() => action.mutate({ path: `crm/quotes/${quote.id}/submit`, done: 'crm.quote.submitted' })}>
                {t('crm.quote.submit')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {convertError ? <Alert tone="danger" title={convertError} /> : null}
        {rateNeeded && quote.status === 'draft' ? <Alert tone="warning" title={t('crm.quote.rateNeededTitle')}>{t('crm.quote.rateNeededHelp')}</Alert> : null}
        {quote.status === 'draft' && quote.discount_over_limit ? <Alert tone="info" title={t('crm.quote.discountNeedsApproval')} /> : null}
        {quote.status === 'draft' && quote.needs_approval && !quote.discount_over_limit ? <Alert tone="info" title={t('crm.quote.needsApproval')} /> : null}
        <Card title={t('crm.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('crm.fields.customer')}>
              {quote.party ? <Link to={`/contacts/customers/${quote.party.id}?tab=sales`} className="text-primary hover:text-primary-hover">{quote.party.name}</Link> : '—'}
            </Detail>
            <Detail label={t('crm.fields.branch')}>
              <span className="flex flex-col">
                <span>{quote.branch?.name}</span>
                <span className="text-caption text-ink-muted">{quote.company?.name}</span>
              </span>
            </Detail>
            {quote.opportunity ? <Detail label={t('crm.fields.opportunity')}><Link to={`/crm/opportunities/${quote.opportunity.id}`} className="text-primary hover:text-primary-hover">{quote.opportunity.name}</Link></Detail> : null}
            <Detail label={t('crm.fields.quoteDate')}><CalendarDate value={quote.quote_date} /></Detail>
            <Detail label={t('crm.fields.validUntil')}><CalendarDate value={quote.valid_until} /></Detail>
            <Detail label={t('crm.fields.priceList')}>{quote.price_list?.name ?? '—'}</Detail>
            <Detail label={t('crm.fields.taxInclusive')}>{quote.tax_inclusive ? t('crm.yes') : t('crm.no')}</Detail>
            <Detail label={t('crm.fields.owner')}>{quote.owner?.name ?? '—'}</Detail>
            {quote.sent_at ? <Detail label={t('crm.quote.sentAt')}><When value={quote.sent_at} companyId={company} /></Detail> : null}
            {quote.accepted_at ? <Detail label={t('crm.quote.acceptedAt')}><When value={quote.accepted_at} companyId={company} /></Detail> : null}
            {quote.declined_at ? (
              <Detail label={t('crm.quote.declinedAt')}>
                <span className="flex flex-col">
                  <When value={quote.declined_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{quote.decline_reason}</span>
                </span>
              </Detail>
            ) : null}
            {quote.converted_ref ? (
              <Detail label={t('crm.quote.convertedTo')}>
                {quote.converted_ref.type === 'invoice' ? <Link to={`/accounting/documents/${quote.converted_ref.id}`} className="text-primary hover:text-primary-hover">{quote.converted_ref.label}</Link> : quote.converted_ref.label}
              </Detail>
            ) : null}
            {quote.cancelled_at ? (
              <Detail label={t('crm.quote.cancelledAt')}>
                <span className="flex flex-col">
                  <When value={quote.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{quote.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
            {quote.terms ? <Detail label={t('crm.fields.terms')}><span className="whitespace-pre-line">{quote.terms}</span></Detail> : null}
            {quote.notes ? <Detail label={t('crm.fields.notes')}><span className="whitespace-pre-line">{quote.notes}</span></Detail> : null}
          </dl>
        </Card>
        <Card title={t('crm.quote.lines')}>
          <DataTable caption={t('crm.quote.lines')} columns={columns} rows={quote.lines ?? []} />
        </Card>
        <Card title={t('crm.quote.totals')}>
          <div className="flex flex-col gap-3 sm:max-w-field">
            <Totals totals={quote.totals} currency={quote.currency} />
            {quote.base_totals && quote.base_totals.currency !== quote.currency ? (
              <p className="flex items-baseline justify-between gap-4 text-caption text-ink-muted">
                <span>{t('crm.quote.baseTotal')}</span>
                <Amount value={{ amount_minor: quote.base_totals.total_minor, currency: quote.base_totals.currency }} />
              </p>
            ) : null}
          </div>
        </Card>
        <DocumentFlow type="crm.quote" id={quote.id} started={quote.status !== 'draft'} />
        {can.send ? <Shares quote={quote} /> : null}
      </div>
      {dialog === 'send' ? <SendDialog quote={quote} onClose={() => setDialog(null)} onDone={(response) => done(response, 'crm.quote.sent')} /> : null}
      {dialog === 'accept' ? <AcceptDialog quote={quote} onClose={() => setDialog(null)} onDone={(response) => done(response, 'crm.quote.accepted')} /> : null}
      {dialog === 'decline' ? (
        <ReasonDialog title={t('crm.quote.declineTitle', { number: title })} label={t('crm.fields.reason')} confirm={t('crm.quote.decline')} variant="danger" path={`crm/quotes/${quote.id}/decline`} onClose={() => setDialog(null)} onDone={(response) => done(response, 'crm.quote.declined')} />
      ) : null}
      {dialog === 'cancel' ? (
        <ReasonDialog title={t('crm.quote.cancelTitle', { number: title })} help={t('crm.quote.cancelHelp')} label={t('crm.fields.reason')} confirm={t('crm.quote.confirmCancel')} variant="danger" path={`crm/quotes/${quote.id}/cancel`} onClose={() => setDialog(null)} onDone={(response) => done(response, 'crm.quote.cancelled')} />
      ) : null}
    </>
  )
}

/** A quantity (decimal string) with its unit code. */
function QtyText({ value, unit }) {
  const locale = useLocale()
  if (value === null || value === undefined) return <span className="text-ink-muted">—</span>
  return (
    <span className="whitespace-nowrap tabular-nums">
      {formatDecimal(String(value), locale)}
      {unit ? <span className="text-ink-muted">{` ${unit}`}</span> : null}
    </span>
  )
}
