import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, Checkbox, DataTable, KpiTile } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { moneyText } from '@/lib/money'
import { useLocale } from '@/lib/useLocale'
import { ChargeDialog, PaymentDialog, RefundDialog, SettleDialog, TransferDialog } from './FolioDialogs'
import { folioHtml } from './folioPrint'
import { Amount, Back, Day, DetailState, ReasonDialog, StateBadge } from './HotelParts'
import { CHECKOUT, FOLIO_CHARGE, FOLIO_PAYMENT, FOLIO_REFUND, FOLIO_TRANSFER, FOLIO_VOID, hotelKey, useHotelPlaces } from './hotelData'

const PRINT_SANDBOX = 'allow-scripts allow-modals'
/** Line kinds that can be voided, and that can be moved to another folio (HOT-21, HOT-25). */
const VOIDABLE = ['room', 'extra', 'fee']
const MOVABLE = ['room', 'extra', 'fee', 'pos_charge', 'transfer_in']

/** The document's HTML with its scripts removed and one call to print once it has loaded. */
function printable(html) {
  const clean = String(html ?? '').replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
  const call = '<script>window.addEventListener("load", function () { window.print() })</script>'
  return clean.includes('</body>') ? clean.replace('</body>', `${call}</body>`) : clean + call
}

/** HOT-20 to HOT-27: a folio: its lines, charges and payments, taxes, and what can be done while it is open. */
export default function FolioDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const { folioId } = useParams()
  const { can } = usePermissions()
  const queryClient = useQueryClient()
  const places = useHotelPlaces()
  const [dialog, setDialog] = useState(null)
  const [selected, setSelected] = useState([])
  const [printing, setPrinting] = useState(null)
  const [printError, setPrintError] = useState(null)
  const key = [...hotelKey, 'folio', folioId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`hotel/folios/${folioId}`) })
  const check = useMutation({
    mutationFn: (payment) => api.post(`hotel/folios/${folioId}/payments/${payment.id}/check`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: hotelKey }),
    onError: (error) => toast.error(errorMessage(error)),
  })
  const print = useMutation({
    mutationFn: () => api.get(`hotel/folios/${folioId}/print`),
    onMutate: () => setPrintError(null),
    onSuccess: (response) => setPrinting((current) => ({ html: folioHtml({ t, data: response.data, locale }), n: (current?.n ?? 0) + 1 })),
    onError: (error) => setPrintError(errorMessage(error)),
  })

  const back = <Back to="/hotel/folios">{t('hotel.folio.back')}</Back>
  const data = query.data?.data
  if (!data) return <DetailState query={query} back={back} notFound={t('hotel.folio.notFound')} />

  const open = data.status === 'open'
  const companyId = places.branches.find((branch) => branch.id === data.branch_id)?.company_id
  const folio = { ...data, company_id: companyId }
  const reversers = new Map(data.lines.filter((line) => line.reverses_id).map((line) => [line.reverses_id, line.kind]))
  const isReversal = (line) => Boolean(line.reverses_id)
  const movable = (line) => open && MOVABLE.includes(line.kind) && !reversers.has(line.id)
  const voidable = (line) => open && VOIDABLE.includes(line.kind) && !reversers.has(line.id)
  const toggle = (id, on) => setSelected((current) => (on ? [...current, id] : current.filter((entry) => entry !== id)))
  const canTransfer = open && can(FOLIO_TRANSFER)

  const marker = (line) => {
    if (isReversal(line)) return <span className="text-caption text-ink-muted">{line.kind === 'transfer_out' ? t('hotel.folio.movedOut') : t('hotel.folio.reversal')}</span>
    const by = reversers.get(line.id)
    if (by) return <span className="text-caption text-ink-muted">{by === 'transfer_out' ? t('hotel.folio.moved') : t('hotel.folio.voided')}</span>
    return null
  }

  const columns = [
    ...(canTransfer
      ? [
          {
            key: 'select',
            label: <span className="sr-only">{t('hotel.folio.select')}</span>,
            render: (row) => (movable(row) ? <Checkbox aria-label={t('hotel.folio.selectLine', { number: row.line_no })} checked={selected.includes(row.id)} onChange={(event) => toggle(row.id, event.target.checked)} /> : null),
          },
        ]
      : []),
    { key: 'line_no', label: t('hotel.folio.lineNo'), align: 'end', render: (row) => <span className="tabular-nums">{row.line_no}</span> },
    { key: 'business_date', label: t('hotel.columns.date'), render: (row) => <Day value={row.business_date} /> },
    {
      key: 'description',
      label: t('hotel.folio.description'),
      wide: true,
      wrap: true,
      render: (row) => (
        <div className="flex flex-col gap-1">
          <span className={reversers.has(row.id) ? 'text-ink-muted line-through' : undefined}>{row.description}</span>
          {marker(row)}
          {row.flags.includes('tax_rate_needed') ? <span className="text-caption text-warning">{t('hotel.folio.taxRateNeeded')}</span> : null}
        </div>
      ),
    },
    { key: 'net', label: t('hotel.print.net'), align: 'end', render: (row) => <Amount minor={row.net_minor} currency={row.currency} /> },
    { key: 'tax', label: t('hotel.print.tax'), align: 'end', render: (row) => <Amount minor={row.tax_minor} currency={row.currency} /> },
    { key: 'charge', label: t('hotel.folio.chargeColumn'), align: 'end', render: (row) => (BigInt(row.amount_minor) > 0n ? <Amount minor={row.amount_minor} currency={row.currency} /> : null) },
    { key: 'payment', label: t('hotel.folio.paymentColumn'), align: 'end', render: (row) => (BigInt(row.amount_minor) < 0n ? <Amount minor={String(-BigInt(row.amount_minor))} currency={row.currency} /> : null) },
    ...(open && can(FOLIO_VOID)
      ? [
          {
            key: 'actions',
            label: <span className="sr-only">{t('hotel.columns.actions')}</span>,
            render: (row) =>
              voidable(row) ? (
                <Button variant="ghost" aria-label={t('hotel.folio.voidLine', { number: row.line_no })} onClick={() => setDialog({ kind: 'void', line: row })}>
                  {t('hotel.folio.void')}
                </Button>
              ) : null,
          },
        ]
      : []),
  ]
  const close = () => {
    setDialog(null)
    setSelected([])
  }
  const balance = BigInt(data.balance_minor)
  return (
    <>
      {back}
      <PageHeader
        title={data.number}
        eyebrow={[data.guest?.name, data.room_number ? t('hotel.reservation.roomNumber', { number: data.room_number }) : null].filter(Boolean).join(' · ')}
        description={t(`hotel.kinds.${data.kind}`)}
        actions={
          <div className="flex flex-wrap gap-2">
            {open && can(FOLIO_CHARGE) ? <Button icon="plus" onClick={() => setDialog({ kind: 'charge' })}>{t('hotel.folio.addCharge')}</Button> : null}
            {open && can(FOLIO_PAYMENT) ? <Button onClick={() => setDialog({ kind: 'payment' })}>{t('hotel.folio.takePayment')}</Button> : null}
            {open && can(FOLIO_PAYMENT) ? <Button onClick={() => setDialog({ kind: 'deposit' })}>{t('hotel.folio.takeDeposit')}</Button> : null}
            {open && can(FOLIO_REFUND) ? <Button onClick={() => setDialog({ kind: 'refund' })}>{t('hotel.folio.refund')}</Button> : null}
            {canTransfer ? <Button disabled={selected.length === 0} onClick={() => setDialog({ kind: 'transfer' })}>{t('hotel.folio.transfer')}</Button> : null}
            <Button icon="print" loading={print.isPending} onClick={() => print.mutate()}>{t('hotel.folio.print')}</Button>
            {open && can(CHECKOUT) ? <Button variant="primary" onClick={() => setDialog({ kind: 'settle' })}>{t('hotel.folio.settle')}</Button> : null}
          </div>
        }
      />
      {printError ? <Alert tone="danger" title={printError} /> : null}
      <div className="flex flex-wrap items-center gap-4">
        <StateBadge status={data.status} />
        {data.reservation_id ? (
          <Link to={`/hotel/reservations/${data.reservation_id}`} className="text-label text-primary hover:text-primary-hover">
            {t('hotel.folio.openReservation', { number: data.reservation_number ?? '' })}
          </Link>
        ) : null}
        {data.invoice_number ? <span className="text-body text-ink-muted">{t('hotel.folio.invoice', { number: data.invoice_number })}</span> : null}
      </div>
      <div className="grid gap-3 sm:grid-cols-3">
        <KpiTile label={t('hotel.folio.charges')} value={moneyText(data.charges_minor, data.currency, locale)} />
        <KpiTile label={t('hotel.folio.paid')} value={moneyText(data.payments_minor, data.currency, locale)} />
        <KpiTile label={t('hotel.folio.balance')} value={moneyText(data.balance_minor, data.currency, locale)} />
      </div>
      {data.balance_left_minor ? <Alert tone="warning" title={t('hotel.folio.leftOnAccount', { amount: moneyText(data.balance_left_minor, data.currency, locale) })} /> : null}
      {balance < 0n && open ? <Alert tone="info" title={t('hotel.folio.credit')} /> : null}
      {data.pending_payments.length ? (
        <Card title={t('hotel.folio.pending')}>
          <ul className="flex flex-col gap-2">
            {data.pending_payments.map((payment) => (
              <li key={payment.id} className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-body">
                  {t(`hotel.payment.kind.${payment.kind}`, { defaultValue: payment.kind })} · <Amount minor={payment.amount_minor} currency={data.currency} />
                </span>
                <Button variant="secondary" loading={check.isPending && check.variables?.id === payment.id} onClick={() => check.mutate(payment)}>
                  {t('hotel.payment.checkNow')}
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}
      <DataTable caption={t('hotel.folio.lines')} columns={columns} rows={data.lines} emptyText={t('hotel.folio.noLines')} />

      {dialog?.kind === 'charge' ? <ChargeDialog folio={folio} onClose={close} /> : null}
      {dialog?.kind === 'payment' ? <PaymentDialog folio={folio} onClose={close} /> : null}
      {dialog?.kind === 'deposit' ? <PaymentDialog folio={folio} kind="deposit" onClose={close} /> : null}
      {dialog?.kind === 'refund' ? <RefundDialog folio={folio} onClose={close} /> : null}
      {dialog?.kind === 'transfer' ? <TransferDialog folio={folio} lineIds={selected} onClose={close} /> : null}
      {dialog?.kind === 'settle' ? <SettleDialog folio={folio} onClose={close} /> : null}
      {dialog?.kind === 'void' ? (
        <ReasonDialog
          open
          title={t('hotel.folio.voidTitle', { number: dialog.line.line_no })}
          help={t('hotel.folio.voidHelp')}
          label={t('hotel.folio.voidReason')}
          action={t('hotel.folio.void')}
          path={`hotel/folios/${data.id}/lines/${dialog.line.id}/void`}
          onClose={close}
          onDone={async () => {
            toast.success(t('hotel.folio.voidDone'))
            await queryClient.invalidateQueries({ queryKey: hotelKey })
            close()
          }}
        />
      ) : null}
      {printing ? (
        // The statement prints itself in a frame with an opaque origin: allow-scripts runs only the print call added here (every value is escaped).
        <iframe key={printing.n} title={t('hotel.print.frame')} sandbox={PRINT_SANDBOX} srcDoc={printable(printing.html)} aria-hidden="true" tabIndex={-1} className="sr-only" />
      ) : null}
    </>
  )
}
