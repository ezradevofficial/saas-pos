import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Dialog, StatusBadge, TextField } from '@/components/ds'
import { saveFile } from '@/lib/files'
import { fieldError } from '@/pages/inventory/inventoryHelpers'
import { STATUS_TONES, statusLabel } from './storesData'

// The presentational pieces Stores shares with the Inventory screens (an
// amount, a quantity, a detail row, the way back, a detail's loading and
// error states, the item picker) come from the Inventory parts, which hold
// no Inventory data; the rest is Stores' own.
export { Amount, Back, Detail, DetailState, ItemPicker, Qty } from '@/pages/inventory/InventoryParts'

/** A Stores document status as a dot and a word. */
export function DocumentStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/**
 * WF-10, STR-03: where a requisition's flow is: each open step and who
 * holds it, the approval's link while one waits, and the full flow page.
 */
export function RequisitionFlow({ id }) {
  const { t } = useTranslation()
  const query = useQuery({
    queryKey: ['document-workflows', 'stores.requisition', id],
    queryFn: () => api.get(`document-workflows/stores.requisition/${encodeURIComponent(id)}`),
    retry: false,
  })
  const flow = query.data?.data
  if (!flow) return null
  const current = flow.current ?? []
  return (
    <Card title={t('stores.flow.title')}>
      <section className="flex flex-col gap-3">
        {current.length === 0 ? <p className="text-body text-ink-muted">{t('stores.flow.noCurrent')}</p> : null}
        {current.map((step) => {
          const names = [...(step.holders?.users ?? []), ...(step.holders?.roles ?? [])].map((holder) => holder.name).filter(Boolean)
          return (
            <div key={step.token_id ?? step.node_id} className="flex flex-col gap-1 rounded-md border border-border p-3">
              <span className="font-medium text-ink">{step.name}</span>
              <span className="text-caption text-ink-muted">{!step.holders?.blocked && names.length ? t('stores.flow.heldBy', { names: names.join(', ') }) : t('stores.flow.nobody')}</span>
              {step.holders?.approval_id ? (
                <Link to={`/approvals/${step.holders.approval_id}`} className="w-fit text-label text-primary hover:text-primary-hover">
                  {t('stores.flow.openApproval')}
                </Link>
              ) : null}
            </div>
          )
        })}
        <Link to={`/document-workflows/stores.requisition/${id}`} className="w-fit text-label text-primary hover:text-primary-hover">
          {t('stores.flow.openFlow')}
        </Link>
      </section>
    </Card>
  )
}

/**
 * Cancel or close a requisition with a reason (`{reason}`). `onDone`
 * gets the requisition as the API answers.
 */
export function ReasonDialog({ open, title, help, confirm, tone = 'danger', path, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const send = useMutation({
    mutationFn: () => api.post(path, { reason: reason.trim() }),
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
            {t('stores.actions.keep')}
          </Button>
          <Button variant={tone} loading={send.isPending} disabled={!reason.trim()} onClick={() => send.mutate()}>
            {confirm}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {help ? <p>{help}</p> : null}
        {send.isError && !fieldError(send.error, 'reason') ? <Alert tone="danger" title={errorMessage(send.error)} /> : null}
        <TextField label={t('stores.actions.reason')} value={reason} maxLength={500} required error={fieldError(send.error, 'reason')} onChange={(event) => setReason(event.target.value)} />
      </div>
    </Dialog>
  )
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
 * STR-14: print an issue's delivery note or issue voucher (a sandboxed
 * frame that prints itself) or download it as a PDF.
 */
export function PrintActions({ issueId }) {
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
  const path = (document, format) => `stores/issues/${issueId}/print?document=${document}&format=${format}`
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
    <Card title={t('stores.print.title')} subtitle={t('stores.print.help')}>
      <div className="flex flex-col gap-3">
        {error ? <Alert tone="danger" title={error} /> : null}
        {['delivery_note', 'issue_voucher'].map((document) => (
          <div key={document} className="flex flex-wrap items-center gap-2">
            <span className="min-w-40 text-body text-ink">{t(`stores.print.${document}`)}</span>
            <Button icon="print" loading={busy === `${document}-print`} disabled={Boolean(busy)} onClick={() => print(document)}>
              {t('stores.print.print')}
            </Button>
            <Button variant="ghost" icon="download" loading={busy === `${document}-pdf`} disabled={Boolean(busy)} onClick={() => pdf(document)}>
              {t('stores.print.pdf')}
            </Button>
          </div>
        ))}
      </div>
      {printing ? (
        // The document prints itself in a frame with an opaque origin: allow-scripts runs only the
        // print call added here (the server escapes every value); allow-modals opens the print dialog.
        <iframe key={printing.n} title={t('stores.print.frame')} sandbox={PRINT_SANDBOX} srcDoc={printable(printing.html)} aria-hidden="true" tabIndex={-1} className="sr-only" />
      ) : null}
    </Card>
  )
}
