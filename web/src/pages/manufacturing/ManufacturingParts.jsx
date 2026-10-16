import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, StatusBadge } from '@/components/ds'
import { saveFile } from '@/lib/files'
import { Amount } from '@/pages/inventory/InventoryParts'
import { STATUS_TONES, statusLabel, useManufacturingStatus } from './manufacturingData'

// The presentational pieces Manufacturing shares with the Inventory screens
// (an amount, a quantity, a detail row, the way back, a detail's loading
// and error states, the item picker, a calendar date) come from the
// Inventory parts, which hold no Inventory data; the rest is its own.
export { Amount, Back, CalendarDate, Detail, DetailState, DocumentFlow, ItemPicker, Qty } from '@/pages/inventory/InventoryParts'

/** An order, report or version status as a dot and a word. */
export function MfgStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/** MFG-17: a quiet note while Inventory is off (orders record quantities only). */
export function InventoryOffNote() {
  const { t } = useTranslation()
  const status = useManufacturingStatus()
  if (!status || status.inventory_active) return null
  return <Alert tone="info" title={t('manufacturing.inventoryOff')} />
}

/** The print frame's sandbox (TPL-04): never allow-same-origin. */
const PRINT_SANDBOX = 'allow-scripts allow-modals'

/** The document's HTML with its scripts removed and one call to print once it has loaded. */
function printable(html) {
  const clean = String(html ?? '').replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
  const call = '<script>window.addEventListener("load", function () { window.print() })</script>'
  return clean.includes('</body>') ? clean.replace('</body>', `${call}</body>`) : clean + call
}

/**
 * MFG-24: print an order's production order or pick list (a sandboxed
 * frame that prints itself) or download it as a PDF.
 */
export function OrderPrints({ orderId }) {
  const { t } = useTranslation()
  const [busy, setBusy] = useState(null)
  const [printing, setPrinting] = useState(null)
  const [error, setError] = useState(null)
  const run = async (key, fn) => {
    setBusy(key)
    setError(null)
    try {
      await fn()
    } catch (caught) {
      setError(errorMessage(caught))
    } finally {
      setBusy(null)
    }
  }
  const path = (document, format) => `manufacturing/orders/${orderId}/print?document=${document}&format=${format}`
  const print = (document) =>
    run(`${document}-print`, async () => {
      const { blob } = await api.download(path(document, 'html'))
      const html = await blob.text()
      setPrinting((current) => ({ html, n: (current?.n ?? 0) + 1 }))
    })
  const pdf = (document) =>
    run(`${document}-pdf`, async () => {
      const { blob, filename } = await api.download(path(document, 'pdf'))
      saveFile(blob, filename ?? `${document}.pdf`)
    })

  return (
    <Card title={t('manufacturing.print.title')} subtitle={t('manufacturing.print.help')}>
      <div className="flex flex-col gap-3">
        {error ? <Alert tone="danger" title={error} /> : null}
        {['production_order', 'pick_list'].map((document) => (
          <div key={document} className="flex flex-wrap items-center gap-2">
            <span className="min-w-40 text-body text-ink">{t(`manufacturing.print.${document}`)}</span>
            <Button icon="print" loading={busy === `${document}-print`} disabled={Boolean(busy)} onClick={() => print(document)} aria-label={t('manufacturing.print.printOne', { document: t(`manufacturing.print.${document}`) })}>
              {t('manufacturing.print.print')}
            </Button>
            <Button variant="ghost" icon="download" loading={busy === `${document}-pdf`} disabled={Boolean(busy)} onClick={() => pdf(document)} aria-label={t('manufacturing.print.pdfOne', { document: t(`manufacturing.print.${document}`) })}>
              {t('manufacturing.print.pdf')}
            </Button>
          </div>
        ))}
      </div>
      {printing ? (
        // The document prints itself in a frame with an opaque origin: allow-scripts runs only the
        // print call added here (the server escapes every value); allow-modals opens the print dialog.
        <iframe key={printing.n} title={t('manufacturing.print.frame')} sandbox={PRINT_SANDBOX} srcDoc={printable(printing.html)} aria-hidden="true" tabIndex={-1} className="sr-only" />
      ) : null}
    </Card>
  )
}

/** A value of a report row: "Not valued" for an order without stock values, a dash when hidden. */
export function ValueCell({ row, value }) {
  const { t } = useTranslation()
  if (!row.valued) return <span className="text-ink-muted">{t('manufacturing.notValued')}</span>
  return <Amount value={value} />
}

/** An order's number, opening the order. */
export function OrderLink({ order }) {
  if (!order?.id) return null
  return (
    <Link to={`/manufacturing/orders/${order.id}`} className="font-medium text-primary tabular-nums hover:text-primary-hover" onClick={(event) => event.stopPropagation()}>
      {order.number}
    </Link>
  )
}
