import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, DecimalInput, Dialog, MoneyInput, Select, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { todayIn } from '@/lib/dates'
import { useTimeZone } from '@/lib/useTimeZone'
import { Amount, Back, CalendarDate, CancelDialog, Detail, DetailState, LineName, Qty, Status, When } from './ProcurementParts'
import { useDocumentAction } from './procurementHelpers'
import { procurementKey, useCurrencies } from './procurementData'

/** PRC-04: a supplier's quote typed in by the buyer: reference, dates, currency and per line a unit price (blank: no offer) and lead time. */
function QuoteDialog({ rfq, open, onClose, onDone }) {
  const { t } = useTranslation()
  const zone = useTimeZone(rfq.company?.id)
  const currencies = useCurrencies(rfq.company?.id)
  const [party, setParty] = useState('')
  const [reference, setReference] = useState('')
  const [quoteDate, setQuoteDate] = useState(() => todayIn(zone))
  const [validUntil, setValidUntil] = useState('')
  const [currency, setCurrency] = useState('')
  const [prices, setPrices] = useState({})
  const [leadTimes, setLeadTimes] = useState({})
  const [submitted, setSubmitted] = useState(false)
  const shownCurrency = currency || currencies.base
  const offered = rfq.lines.filter((line) => prices[line.id] !== undefined && prices[line.id] !== '' && prices[line.id] !== null)
  const save = useMutation({
    mutationFn: () =>
      api.post('procurement/quotes', {
        rfq_id: rfq.id,
        party_id: party,
        reference: reference.trim() || null,
        quote_date: quoteDate,
        valid_until: validUntil || null,
        currency: shownCurrency,
        lines: offered.map((line) => ({ rfq_line_id: line.id, unit_price_minor: prices[line.id], ...(leadTimes[line.id] ? { lead_time_days: Number(leadTimes[line.id]) } : {}) })),
      }),
    onSuccess: (response) => onDone(response),
  })
  const errors = formErrors(save.error, ['party_id', 'reference', 'quote_date', 'valid_until', 'currency', 'lines'])
  const ready = party && quoteDate && offered.length > 0
  const confirm = () => {
    setSubmitted(true)
    if (ready) save.mutate()
  }

  return (
    <Dialog
      open={open}
      size="lg"
      title={t('procurement.quote.title')}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('procurement.actions.back')}
          </Button>
          <Button variant="primary" loading={save.isPending} onClick={confirm}>
            {t('procurement.quote.save')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        <p>{t('procurement.quote.help')}</p>
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label={t('procurement.fields.supplier')}
            placeholder={t('procurement.pickers.supplier')}
            options={rfq.suppliers.map((supplier) => ({ value: supplier.id, label: supplier.name }))}
            value={party}
            required
            error={errors.fields.party_id ?? (submitted && !party ? t('procurement.validation.supplier') : undefined)}
            onChange={(event) => setParty(event.target.value)}
          />
          <TextField label={t('procurement.fields.reference')} value={reference} maxLength={100} error={errors.fields.reference} onChange={(event) => setReference(event.target.value)} />
          <TextField type="date" label={t('procurement.fields.quoteDate')} value={quoteDate} required error={errors.fields.quote_date} onChange={(event) => setQuoteDate(event.target.value)} />
          <TextField type="date" label={t('procurement.fields.validUntil')} value={validUntil} error={errors.fields.valid_until} onChange={(event) => setValidUntil(event.target.value)} />
          <Select label={t('procurement.fields.currency')} options={currencies.options} value={shownCurrency} error={errors.fields.currency} onChange={(event) => {
              // Prices are minor units of the currency: another currency clears them (stage 7B review).
              if (event.target.value !== shownCurrency) setPrices({})
              setCurrency(event.target.value)
            }}
          />
        </div>
        {errors.fields.lines || (submitted && offered.length === 0) ? <Alert tone="danger" title={errors.fields.lines ?? t('procurement.quote.noPrices')} /> : null}
        <ul aria-label={t('procurement.quote.prices')} className="flex flex-col gap-3">
          {rfq.lines.map((line) => (
            <li key={line.id} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-3">
              <div className="flex flex-col">
                <LineName line={line} />
                <span className="text-caption text-ink-muted">
                  <Qty value={line.qty} unit={line.uom?.code} />
                </span>
              </div>
              <MoneyInput
                key={shownCurrency}
                label={t('procurement.quote.unitPriceOf', { line: line.line_no })}
                help={t('procurement.quote.noOffer')}
                currency={shownCurrency}
                value={prices[line.id] ?? ''}
                onChange={(value) => setPrices((current) => ({ ...current, [line.id]: value ?? '' }))}
              />
              <DecimalInput
                label={t('procurement.quote.leadTimeOf', { line: line.line_no })}
                suffix={t('procurement.quote.days')}
                maxDecimals={0}
                positive={false}
                maxIntegerDigits={4}
                value={leadTimes[line.id] ?? ''}
                onChange={(value) => setLeadTimes((current) => ({ ...current, [line.id]: value ?? '' }))}
              />
            </li>
          ))}
        </ul>
      </div>
    </Dialog>
  )
}

/**
 * PRC-05: per RFQ line every quote side by side in base currency, the
 * lowest marked; per supplier the total of what it offered. One quote line
 * is selected per RFQ line (the lowest by default); Award makes one draft
 * PO per selected supplier and closes the RFQ.
 */
function Comparison({ rfq, onAwarded }) {
  const { t } = useTranslation()
  const query = useQuery({ queryKey: [...procurementKey, 'rfq', rfq.id, 'comparison'], queryFn: () => api.get(`procurement/rfqs/${rfq.id}/comparison`) })
  const [chosen, setChosen] = useState({})
  const award = useMutation({
    mutationFn: (selections) => api.post(`procurement/rfqs/${rfq.id}/award`, { selections }),
    onSuccess: (response) => onAwarded(response?.data ?? []),
  })
  const grid = query.data?.data
  if (!grid) return query.isError ? <Alert tone="danger" title={query.error?.message} /> : null
  if (!grid.suppliers.length) return null

  const selectionOf = (line) => chosen[line.rfq_line_id] ?? line.offers.find((offer) => offer.selected)?.quote_line_id ?? line.offers.find((offer) => offer.lowest)?.quote_line_id ?? null
  const selections = Object.fromEntries(grid.lines.map((line) => [line.rfq_line_id, selectionOf(line)]).filter(([, value]) => value))
  const columns = [
    { key: 'line', label: t('procurement.columns.item'), wrap: true, render: (line) => <LineName line={line} /> },
    { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    ...grid.suppliers.map((supplier) => ({
      key: supplier.quote_id,
      label: supplier.party?.name,
      wrap: true,
      render: (line) => {
        const offer = line.offers.find((entry) => entry.quote_id === supplier.quote_id)
        if (!offer) return <span className="text-caption text-ink-muted">{t('procurement.comparison.noOffer')}</span>
        const id = `pick-${line.rfq_line_id}-${offer.quote_line_id}`
        return (
          <div className="flex items-start gap-2">
            {rfq.can?.award ? (
              <input
                id={id}
                type="radio"
                name={`line-${line.rfq_line_id}`}
                checked={selectionOf(line) === offer.quote_line_id}
                onChange={() => setChosen((current) => ({ ...current, [line.rfq_line_id]: offer.quote_line_id }))}
                aria-label={t('procurement.comparison.pick', { supplier: supplier.party?.name, line: line.item?.name ?? line.description })}
                className="mt-1 size-4 shrink-0 cursor-pointer accent-primary"
              />
            ) : null}
            <span className="flex flex-col gap-1">
              <Amount value={offer.base_unit_price} />
              {offer.unit_price?.currency !== grid.base_currency ? (
                <span className="text-caption text-ink-muted">
                  <Amount value={offer.unit_price} />
                </span>
              ) : null}
              {offer.lead_time_days !== null && offer.lead_time_days !== undefined ? <span className="text-caption text-ink-muted">{t('procurement.comparison.leadTime', { count: offer.lead_time_days })}</span> : null}
              {offer.lowest ? <StatusBadge tone="success">{t('procurement.comparison.lowest')}</StatusBadge> : null}
            </span>
          </div>
        )
      },
    })),
  ]
  const totals = grid.suppliers.map((supplier) => ({ id: supplier.quote_id, ...supplier }))

  return (
    <Card title={t('procurement.comparison.title')} subtitle={t('procurement.comparison.subtitle', { currency: grid.base_currency })}>
      <div className="flex flex-col gap-4">
        {award.isError ? <Alert tone="danger" title={award.error?.message} /> : null}
        <DataTable caption={t('procurement.comparison.title')} columns={columns} rows={grid.lines.map((line) => ({ ...line, id: line.rfq_line_id }))} />
        <DataTable
          caption={t('procurement.comparison.totals')}
          columns={[
            { key: 'party', label: t('procurement.columns.supplier'), render: (row) => row.party?.name },
            { key: 'lines', label: t('procurement.comparison.linesQuoted'), align: 'end', numeric: true, render: (row) => row.lines_quoted },
            { key: 'valid', label: t('procurement.fields.validUntil'), render: (row) => <CalendarDate value={row.valid_until} /> },
            { key: 'total', label: t('procurement.columns.total'), align: 'end', render: (row) => <Amount value={row.total} /> },
            { key: 'base_total', label: t('procurement.columns.baseTotal'), align: 'end', render: (row) => <Amount value={row.base_total} /> },
            { key: 'status', label: t('procurement.columns.status'), render: (row) => <Status status={row.status} /> },
          ]}
          rows={totals}
        />
        {rfq.can?.award ? (
          <div>
            <Button variant="pay" loading={award.isPending} disabled={Object.keys(selections).length === 0} onClick={() => award.mutate(selections)}>
              {t('procurement.comparison.award')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/**
 * PRC-03 to PRC-05: one RFQ: its lines and invited suppliers, Issue and
 * Edit while a draft, the quotes typed in ("Enter quote" while issued),
 * the comparison grid with the award, and the draft POs it made.
 */
export default function RfqDetail() {
  const { t } = useTranslation()
  const { rfqId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [cancelling, setCancelling] = useState(false)
  const [quoting, setQuoting] = useState(false)
  const [orders, setOrders] = useState([])
  const key = [...procurementKey, 'rfq', rfqId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`procurement/rfqs/${rfqId}`) })
  const action = useDocumentAction(key)
  const rfq = query.data?.data
  const back = <Back to="/procurement/rfqs">{t('procurement.rfqs.back')}</Back>
  if (!rfq) return <DetailState query={query} back={back} notFound={t('procurement.rfq.notFound')} />

  const company = rfq.company?.id
  // PRC-05: the draft POs the award made, kept on the RFQ (named after their quote's supplier).
  const supplierNames = Object.fromEntries((rfq.quotes ?? []).map((quote) => [quote.party?.id, quote.party?.name]))
  const madeOrders = orders.length ? orders : (rfq.orders ?? []).map((order) => ({ ...order, party: order.party ?? { name: supplierNames[order.party_id] } }))
  const lineColumns = [
    { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    { key: 'item', label: t('procurement.columns.item'), wrap: true, render: (line) => <LineName line={line} /> },
    { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
  ]
  const quoteColumns = [
    { key: 'party', label: t('procurement.columns.supplier'), render: (quote) => quote.party?.name },
    { key: 'reference', label: t('procurement.fields.reference'), render: (quote) => quote.reference ?? '' },
    { key: 'quote_date', label: t('procurement.fields.quoteDate'), render: (quote) => <CalendarDate value={quote.quote_date} /> },
    { key: 'valid_until', label: t('procurement.fields.validUntil'), render: (quote) => <CalendarDate value={quote.valid_until} /> },
    { key: 'total', label: t('procurement.columns.total'), align: 'end', render: (quote) => <Amount value={quote.total} /> },
    { key: 'base_total', label: t('procurement.columns.baseTotal'), align: 'end', render: (quote) => <Amount value={quote.base_total} /> },
    { key: 'status', label: t('procurement.columns.status'), render: (quote) => <Status status={quote.status} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('procurement.rfq.eyebrow')}
        title={rfq.number ?? t('procurement.draftNumber')}
        description={<Status status={rfq.status} />}
        actions={
          <>
            {rfq.can?.edit ? <Button icon="edit" onClick={() => navigate(`/procurement/rfqs/${rfq.id}/edit`)}>{t('procurement.actions.edit')}</Button> : null}
            {rfq.can?.cancel ? (
              <Button variant="danger" onClick={() => setCancelling(true)}>
                {t('procurement.rfq.cancel')}
              </Button>
            ) : null}
            {rfq.can?.quote ? (
              <Button variant="primary" icon="plus" onClick={() => setQuoting(true)}>
                {t('procurement.rfq.enterQuote')}
              </Button>
            ) : null}
            {rfq.can?.issue ? (
              <Button variant="pay" loading={action.isPending} onClick={() => action.mutate({ path: `procurement/rfqs/${rfq.id}/issue`, done: 'procurement.rfq.issued' })}>
                {t('procurement.rfq.issue')}
              </Button>
            ) : null}
          </>
        }
      />
      <div className="flex flex-col gap-5">
        {madeOrders.length ? (
          <Alert tone="success" title={t('procurement.comparison.awarded', { count: madeOrders.length })}>
            <ul className="flex flex-col gap-1">
              {madeOrders.map((order) => (
                <li key={order.id}>
                  <Link to={`/procurement/orders/${order.id}`} className="text-label text-primary hover:text-primary-hover">
                    {t('procurement.comparison.orderLink', { supplier: order.party?.name ?? '', number: order.number ?? t('procurement.draftNumber') })}
                  </Link>
                </li>
              ))}
            </ul>
          </Alert>
        ) : null}
        <Card title={t('procurement.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('procurement.fields.title')}>{rfq.title}</Detail>
            <Detail label={t('procurement.fields.branch')}>{[rfq.company?.name, rfq.branch?.name].filter(Boolean).join(' · ')}</Detail>
            <Detail label={t('procurement.fields.deadline')}>
              <When value={rfq.deadline_at} companyId={company} />
            </Detail>
            {rfq.requisition ? (
              <Detail label={t('procurement.fields.requisition')}>
                <Link to={`/procurement/requisitions/${rfq.requisition.id}`} className="text-primary hover:text-primary-hover">
                  {rfq.requisition.number}
                </Link>
              </Detail>
            ) : null}
            <Detail label={t('procurement.fields.invitedSuppliers')}>{rfq.suppliers.length ? rfq.suppliers.map((supplier) => supplier.name).join(', ') : t('procurement.rfq.noSuppliers')}</Detail>
            {rfq.note ? <Detail label={t('procurement.fields.note')}>{rfq.note}</Detail> : null}
            {rfq.cancelled_at ? (
              <Detail label={t('procurement.fields.cancelled')}>
                <span className="flex flex-col">
                  <When value={rfq.cancelled_at} companyId={company} />
                  <span className="text-caption text-ink-muted">{rfq.cancel_reason}</span>
                </span>
              </Detail>
            ) : null}
          </dl>
        </Card>
        <Card title={t('procurement.lines.title')}>
          <DataTable caption={t('procurement.lines.title')} columns={lineColumns} rows={rfq.lines ?? []} />
        </Card>
        <Card title={t('procurement.rfq.quotes')}>
          <DataTable caption={t('procurement.rfq.quotes')} columns={quoteColumns} rows={rfq.quotes ?? []} emptyText={t('procurement.rfq.noQuotes')} />
        </Card>
        {rfq.quotes?.length ? <Comparison key={`${rfq.status}-${rfq.quotes.length}`} rfq={rfq} onAwarded={(made) => {
          setOrders(made)
          toast.success(t('procurement.comparison.awardedToast'))
          queryClient.invalidateQueries({ queryKey: procurementKey })
        }} /> : null}
      </div>
      {rfq.can?.quote ? (
        <QuoteDialog
          key={quoting ? 'open' : 'closed'}
          rfq={rfq}
          open={quoting}
          onClose={() => setQuoting(false)}
          onDone={async () => {
            setQuoting(false)
            toast.success(t('procurement.quote.saved'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
      {rfq.can?.cancel ? (
        <CancelDialog
          open={cancelling}
          title={t('procurement.rfq.cancelTitle', { number: rfq.number ?? t('procurement.draftNumber') })}
          help={t('procurement.rfq.cancelHelp')}
          path={`procurement/rfqs/${rfq.id}/cancel`}
          onClose={() => setCancelling(false)}
          onDone={async (response) => {
            setCancelling(false)
            if (response?.data) queryClient.setQueryData(key, response)
            toast.success(t('procurement.rfq.cancelled'))
            await queryClient.invalidateQueries({ queryKey: procurementKey })
          }}
        />
      ) : null}
    </>
  )
}
