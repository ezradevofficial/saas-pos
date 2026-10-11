import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Dialog } from '@/components/ds'

/** Whether two payloads are the same JSON (key order as built by the same code). */
export const samePayload = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null)

/**
 * Asks before an action that would drop unsaved edits (switching the scope
 * or the form of a designer, LAY-06). `guard(action)` runs the action at
 * once when nothing is unsaved, else after the user confirms in an in-page
 * dialog. Render `dialog` once in the page.
 */
export function useUnsavedGuard(dirty) {
  const { t } = useTranslation()
  const [pending, setPending] = useState(null)
  const guard = (action) => {
    if (dirty) setPending(() => action)
    else action()
  }
  const cancel = () => setPending(null)
  const confirm = () => {
    const action = pending
    setPending(null)
    action?.()
  }
  const dialog = (
    <Dialog
      open={Boolean(pending)}
      title={t('layouts.unsaved.title')}
      onClose={cancel}
      footer={
        <>
          <Button onClick={cancel}>{t('layouts.unsaved.keep')}</Button>
          <Button variant="danger" onClick={confirm}>
            {t('layouts.unsaved.discard')}
          </Button>
        </>
      }
    >
      <p>{t('layouts.unsaved.body')}</p>
    </Dialog>
  )
  return { guard, dialog }
}
