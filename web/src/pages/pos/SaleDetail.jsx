import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Icon, StatusBadge } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { formatDecimal } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { Amount, Detail } from './PosParts'
import { useAmountText } from './useAmountText'
import { flagLabel, methodLabel, RECORD_TONES, SALE_REVIEW, SALE_TONES } from './posData'

/** "1 USD = 2,850 CDF" from a stored rate snapshot (CUR-04). */
function RateText({ fx }) {
  const locale = useLocale()
  if (!fx || fx.base === fx.quote) return null
  return <span className="text-caption text-ink-muted tabular-nums">{`1 ${fx.base} = ${formatDecimal(fx.rate, locale)} ${fx.quote}`}</span>
}

function Back() {
  const { t } = useTranslation()
  return (
    <Link to="/pos/sales" className="flex w-fit items-center gap-2 text-label text-primary hover:text-primary-hover">
      <Icon name="back" />
      {t('pos.sales.back')}
    </Link>
  )
}

/** One flag's words: its code, the line it is about, and what the server expected. */
function FlagRow({ flag, currency }) {
  const { t } = useTranslation()
  const amount = useAmountText()
  const detail = flag.detail ?? {}
  const expected = detail.expected_unit_price_minor ?? detail.expected_tax_minor
  return (
    <li className="flex flex-col gap-1 py-2">
      <span className="text-body text-ink">
        {flagLabel(t, flag.code)}
        {flag.line ? <span className="text-ink-muted">{` · ${t('pos.sale.flagLine', { line: flag.line })}`}</span> : null}
      </span>
      {expected != null ? <span className="text-caption text-ink-muted">{t('pos.sale.flagExpected', { amount: amount({ amount_minor: String(expected), currency }) })}</span> : null}
      {detail.pair ? <span className="text-caption text-ink-muted">{t('pos.sale.flagPair', { pair: detail.pair })}</span> : null}
    </li>
  )
}

/**
 * POS-12: one sale as sold (the device wins, POS-09): place and people,
 * lines with tax, totals, payments with the rates used for other
 * currencies (CUR-04), change, the fiscal state, the void and refunds, and
 * the flags with "Mark reviewed" (`pos.sale.review` at its location).
 */
export default function SaleDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { saleId } = useParams()
  const queryClient = useQueryClient()
  const { canWithin } = usePermissions()
  const companyOf = useCompanyOfRecord()
  const query = useQuery({ queryKey: ['pos-sales', 'detail', saleId], queryFn: () => api.get(`pos/sales/${saleId}`) })
  const sale = query.data?.data

  const review = useMutation({
    mutationFn: () => api.post(`pos/sales/${saleId}/review`, {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['pos-sales'] }),
  })

  if (query.isPending) return <p className="text-ink-muted">{t('common.loading')}</p>
  if (query.isError) {
    return (
      <>
        <Back />
        <Alert tone="danger" title={query.error.status === 404 ? t('pos.sale.notFound') : errorMessage(query.error)} />
      </>
    )
  }

  const company = companyOf(sale.company?.id)
  const when = (value) => formatCompanyTime(value, locale, company)
  const chain = [
    { type: 'company', id: sale.company?.id },
    { type: 'branch', id: sale.branch?.id },
    { type: 'location', id: sale.location?.id },
  ]
  const canReview = sale.flags?.length > 0 && !sale.reviewed_at && canWithin(SALE_REVIEW, chain)
  const foreign = (payment) => payment.amount.currency !== sale.currency

  const lineColumns = [
    { key: 'line_no', label: t('pos.sale.lines.no'), numeric: true, render: (line) => line.line_no },
    {
      key: 'item',
      label: t('pos.sale.lines.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name}</span>
          {Number(line.refunded_qty) > 0 ? <span className="text-caption text-ink-muted">{t('pos.sale.lines.refunded', { qty: formatDecimal(line.refunded_qty, locale) })}</span> : null}
        </span>
      ),
    },
    { key: 'qty', label: t('pos.sale.lines.qty'), align: 'end', numeric: true, render: (line) => formatDecimal(line.qty, locale) },
    {
      key: 'unit_price',
      label: t('pos.sale.lines.unitPrice'),
      align: 'end',
      render: (line) => (
        <span className="flex flex-col items-end">
          <Amount value={line.unit_price} />
          {line.list_price && line.list_price.amount_minor !== line.unit_price.amount_minor ? (
            <span className="text-caption text-ink-muted">{t('pos.sale.lines.listPrice')}</span>
          ) : null}
        </span>
      ),
    },
    { key: 'discount', label: t('pos.sale.lines.discount'), align: 'end', render: (line) => (line.discount?.amount_minor !== '0' ? <Amount value={line.discount} /> : '') },
    {
      key: 'tax',
      label: t('pos.sale.lines.tax'),
      align: 'end',
      render: (line) => (
        <span className="flex flex-col items-end">
          <Amount value={line.tax} />
          {line.tax_rate != null ? (
            <span className="text-caption text-ink-muted tabular-nums">
              {t(line.tax_inclusive ? 'pos.sale.lines.rateIncluded' : 'pos.sale.lines.rateAdded', { rate: formatDecimal(line.tax_rate, locale) })}
            </span>
          ) : null}
        </span>
      ),
    },
    { key: 'total', label: t('pos.sale.lines.total'), align: 'end', render: (line) => <Amount value={line.total} /> },
  ]

  const paymentColumns = [
    {
      key: 'method',
      label: t('pos.sale.payments.method'),
      render: (payment) => (
        <span className="flex flex-col">
          <span>{payment.method_name ?? methodLabel(t, payment.method_type)}</span>
          {payment.provider_reference ? <span className="font-mono text-caption text-ink-muted">{payment.provider_reference}</span> : null}
        </span>
      ),
    },
    {
      key: 'amount',
      label: t('pos.sale.payments.amount'),
      align: 'end',
      render: (payment) => (
        <span className="flex flex-col items-end">
          <Amount value={payment.amount} />
          {foreign(payment) ? <RateText fx={payment.fx} /> : null}
        </span>
      ),
    },
    { key: 'amount_in_sale', label: t('pos.sale.payments.inSale', { currency: sale.currency }), align: 'end', render: (payment) => <Amount value={payment.amount_in_sale} /> },
    {
      key: 'status',
      label: t('pos.sale.payments.status'),
      render: (payment) => <StatusBadge tone={payment.status === 'confirmed' ? 'success' : 'warning'}>{t(`pos.sale.payments.statuses.${payment.status}`)}</StatusBadge>,
    },
  ]

  const totalRows = [
    ['subtotal', sale.subtotal],
    ['discount', sale.discount?.amount_minor !== '0' ? sale.discount : null],
    ['tax', sale.tax],
    ['total', sale.total],
    ['paid', sale.paid],
    ['change', sale.change?.amount_minor !== '0' ? sale.change : null],
    ['rounding', sale.rounding?.amount_minor !== '0' ? sale.rounding : null],
  ].filter(([, value]) => value)

  return (
    <>
      <Back />
      <PageHeader
        eyebrow={t('pos.sale.eyebrow')}
        title={sale.receipt_number}
        description={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <StatusBadge tone={SALE_TONES[sale.status]}>{t(`pos.sales.status.${sale.status}`)}</StatusBadge>
            <span className="tabular-nums">{when(sale.sold_at)}</span>
          </span>
        }
        actions={
          canReview ? (
            <Button variant="pay" icon="check" loading={review.isPending} onClick={() => review.mutate()}>
              {t('pos.sale.markReviewed')}
            </Button>
          ) : null
        }
      />
      {review.isError ? <Alert tone="danger" title={errorMessage(review.error)} /> : null}
      {review.isSuccess ? <Alert tone="success" title={t('pos.sale.reviewedNotice')} /> : null}

      <div className="flex flex-col gap-5">
        {sale.flags?.length ? (
          <Card title={t('pos.sale.flagsTitle')} subtitle={sale.reviewed_at ? t('pos.sale.reviewedAt', { time: when(sale.reviewed_at) }) : t('pos.sale.flagsHelp')}>
            <ul className="divide-y divide-border">
              {sale.flags.map((flag, index) => (
                <FlagRow key={`${flag.code}-${index}`} flag={flag} currency={sale.currency} />
              ))}
            </ul>
          </Card>
        ) : null}

        <Card title={t('pos.sale.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('pos.sale.place')}>
              <span className="flex flex-col">
                <span>{sale.location?.name}</span>
                <span className="text-caption text-ink-muted">{[sale.company?.name, sale.branch?.name].filter(Boolean).join(' · ')}</span>
              </span>
            </Detail>
            <Detail label={t('pos.sale.device')}>{sale.device?.name}</Detail>
            <Detail label={t('pos.sale.cashier')}>{sale.cashier?.name}</Detail>
            <Detail label={t('pos.sale.customer')}>{sale.customer?.name ?? <span className="text-ink-muted">{t('pos.sale.noCustomer')}</span>}</Detail>
            <Detail label={t('pos.sale.received')}>
              <span className="flex flex-col">
                <span className="tabular-nums">{when(sale.received_at)}</span>
                {sale.offline ? <span className="text-caption text-ink-muted">{t('pos.sale.soldOffline')}</span> : null}
              </span>
            </Detail>
            <Detail label={t('pos.sale.shift')}>
              <Link to={`/pos/shifts/${sale.shift_id}`} className="text-primary hover:text-primary-hover">
                {t('pos.sale.openShift')}
              </Link>
            </Detail>
            {/* POS-10: the fiscal queue (KRA eTIMS, DRC DGI) is not in the API yet. */}
            <Detail label={t('pos.sale.fiscal')}>
              <StatusBadge tone="warning">{t('pos.sale.fiscalPending')}</StatusBadge>
            </Detail>
          </dl>
        </Card>

        <Card title={t('pos.sale.lines.title')}>
          <DataTable caption={t('pos.sale.lines.title')} columns={lineColumns} rows={sale.lines ?? []} />
        </Card>

        <div className="grid gap-5 lg:grid-cols-2">
          <Card title={t('pos.sale.payments.title')}>
            <div className="flex flex-col gap-3">
              <DataTable caption={t('pos.sale.payments.title')} columns={paymentColumns} rows={sale.payments ?? []} />
              {sale.change?.amount_minor !== '0' && sale.change?.currency !== sale.currency ? (
                <p className="text-caption text-ink-muted">{t('pos.sale.changeIn', { currency: sale.change.currency })}</p>
              ) : null}
            </div>
          </Card>
          <Card title={t('pos.sale.totals.title')}>
            <dl className="flex flex-col divide-y divide-border">
              {totalRows.map(([key, value]) => (
                <div key={key} className="flex items-center justify-between gap-4 py-2">
                  <dt className={key === 'total' ? 'text-body text-ink' : 'text-body text-ink-muted'}>{t(`pos.sale.totals.${key}`)}</dt>
                  <dd>
                    <Amount value={value} />
                  </dd>
                </div>
              ))}
              {sale.base_total && sale.base_total.currency !== sale.currency ? (
                <div className="flex items-center justify-between gap-4 py-2">
                  <dt className="flex flex-col text-body text-ink-muted">
                    {t('pos.sale.totals.base', { currency: sale.base_total.currency })}
                    <RateText fx={sale.fx} />
                  </dt>
                  <dd>
                    <Amount value={sale.base_total} />
                  </dd>
                </div>
              ) : null}
            </dl>
          </Card>
        </div>

        {sale.void || sale.refunds?.length ? (
          <Card title={t('pos.sale.returns')}>
            <ul className="divide-y divide-border">
              {sale.void ? (
                <li className="flex flex-wrap items-start justify-between gap-3 py-2">
                  <span className="flex flex-col gap-1">
                    <span className="text-ink">{t('pos.sale.voided')}</span>
                    <span className="text-caption text-ink-muted">{sale.void.reason}</span>
                    <span className="text-caption text-ink-muted tabular-nums">{when(sale.void.voided_at)}</span>
                  </span>
                  <StatusBadge tone={RECORD_TONES[sale.void.status]}>{t(`pos.records.${sale.void.status}`)}</StatusBadge>
                </li>
              ) : null}
              {(sale.refunds ?? []).map((refund) => (
                <li key={refund.id} className="flex flex-wrap items-start justify-between gap-3 py-2">
                  <span className="flex flex-col gap-1">
                    <span className="text-ink tabular-nums">{t('pos.sale.refund', { number: refund.receipt_number })}</span>
                    <span className="text-caption text-ink-muted">{refund.reason}</span>
                    <span className="text-caption text-ink-muted tabular-nums">{when(refund.refunded_at)}</span>
                  </span>
                  <span className="flex flex-col items-end gap-1">
                    <Amount value={refund.total} />
                    <StatusBadge tone={RECORD_TONES[refund.status]}>{t(`pos.records.${refund.status}`)}</StatusBadge>
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        ) : null}
      </div>
    </>
  )
}

