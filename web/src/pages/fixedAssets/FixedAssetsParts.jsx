import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, StatusBadge, TextField } from '@/components/ds'
import { fieldError } from '@/pages/inventory/inventoryHelpers'
import { STATUS_TONES, statusLabel } from './fixedAssetsData'

// Presentational pieces shared with the Inventory screens (an amount, a
// date, a detail row, the way back, a flow card, a detail's states).
export { Amount, Back, CalendarDate, Detail, DetailState, DocumentFlow } from '@/pages/inventory/InventoryParts'

/** A Fixed assets status as a dot and a word. */
export function FaStatus({ status }) {
  const { t } = useTranslation()
  return <StatusBadge tone={STATUS_TONES[status] ?? 'neutral'}>{statusLabel(t, status)}</StatusBadge>
}

/**
 * A dialog asking for a reason before an action (`{reason}` plus `extra`).
 * `onDone(response)` gets the record as the API answers.
 */
export function ReasonDialog({ open, title, help, confirm, tone = 'danger', path, extra = {}, onClose, onDone }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const send = useMutation({
    mutationFn: () => api.post(path, { ...extra, reason: reason.trim() }),
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
            {t('fixedAssets.actions.keep')}
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
        <TextField label={t('fixedAssets.fields.reason')} value={reason} maxLength={500} required error={fieldError(send.error, 'reason')} onChange={(event) => setReason(event.target.value)} />
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

/** A hidden frame that prints the labels' HTML (the server escapes every value). */
export function PrintFrame({ printing }) {
  const { t } = useTranslation()
  if (!printing) return null
  return <iframe key={printing.n} title={t('fixedAssets.labels.frame')} sandbox={PRINT_SANDBOX} srcDoc={printable(printing.html)} aria-hidden="true" tabIndex={-1} className="sr-only" />
}
