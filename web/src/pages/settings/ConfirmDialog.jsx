import { useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert, Button, Dialog } from '@/components/ds'
import { useErrorFocus } from '@/lib/useErrorFocus'

/**
 * A confirmation: the title asks the question, the body names the
 * consequence, the confirming button (last) names the result ("Archive
 * branch", "Deactivate Amina"). `tone="danger"` for what cannot simply be
 * undone. An error from the action shows inside the dialog.
 */
export function ConfirmDialog({ open, title, children, confirmLabel, cancelLabel, tone = 'danger', onConfirm, onClose, pending, error, failure }) {
  const { t } = useTranslation()
  const bodyRef = useRef(null)
  const alertRef = useRef(null)
  useErrorFocus(bodyRef, alertRef, failure)

  return (
    <Dialog
      open={open}
      title={title}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {cancelLabel ?? t('common.cancel')}
          </Button>
          <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={pending} onClick={onConfirm}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div ref={bodyRef} className="flex flex-col gap-3">
        {error ? (
          <div ref={alertRef} tabIndex={-1} className="rounded-md">
            <Alert tone="danger" title={error} />
          </div>
        ) : null}
        <div>{children}</div>
      </div>
    </Dialog>
  )
}
