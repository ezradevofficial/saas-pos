import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert, Button } from '@/components/ds'

/**
 * The webhook signing secret (AUTO-03). The server makes it when a rule
 * first gets a webhook and returns it once, in that response or the one
 * that rotates it; afterwards the rule only says one is set. The secret
 * lives in this page's memory, so a reload never shows it again.
 */
export function WebhookSecretPanel({ secret, hasSecret, saved, canRotate, onRotate, rotating, rotateError }) {
  const { t } = useTranslation()
  const [confirming, setConfirming] = useState(false)

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(secret)
      toast.success(t('automation.secret.copied'))
    } catch {
      toast.error(t('automation.secret.copyFailed'))
    }
  }

  return (
    <section aria-label={t('automation.secret.title')} className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4">
      <h4 className="text-label text-ink">{t('automation.secret.title')}</h4>
      {secret ? (
        <>
          <Alert tone="warning" title={t('automation.secret.onceTitle')}>
            {t('automation.secret.onceBody')}
          </Alert>
          <div className="flex flex-wrap items-center gap-2">
            <code data-testid="webhook-secret" className="min-w-0 flex-1 rounded-md border border-border bg-surface-200 px-3 py-2 font-mono text-caption break-all text-ink">
              {secret}
            </code>
            <Button icon="copy" onClick={copy}>
              {t('automation.secret.copy')}
            </Button>
          </div>
        </>
      ) : (
        <p className="text-caption text-ink-muted">{hasSecret ? t('automation.secret.isSet') : saved ? t('automation.secret.createdOnSave') : t('automation.secret.createdOnFirstSave')}</p>
      )}
      <p className="text-caption text-ink-muted">{t('automation.secret.help')}</p>
      {rotateError ? <Alert tone="danger" title={rotateError} /> : null}
      {saved && hasSecret && canRotate && !confirming ? (
        <Button icon="sync" className="self-start" onClick={() => setConfirming(true)}>
          {t('automation.secret.rotate')}
        </Button>
      ) : null}
      {saved && hasSecret && canRotate && confirming ? (
        <div role="group" aria-label={t('automation.secret.rotateConfirmTitle')} className="flex flex-col gap-2 rounded-md border border-border-strong p-3">
          <p className="text-body text-ink">{t('automation.secret.rotateConfirm')}</p>
          <div className="flex flex-wrap gap-2">
            <Button variant="ghost" onClick={() => setConfirming(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={rotating}
              onClick={async () => {
                const ok = await onRotate()
                if (ok) setConfirming(false)
              }}
            >
              {t('automation.secret.rotateNow')}
            </Button>
          </div>
        </div>
      ) : null}
    </section>
  )
}
