import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { Alert, Button } from '@/components/ds'

/**
 * HOOK-01: a webhook's signing secret, shown once (after adding the webhook
 * or rotating its secret). It lives only in the page's memory: a reload
 * never shows it again.
 */
export function SecretReveal({ secret }) {
  const { t } = useTranslation()

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(secret)
      toast.success(t('webhooks.secret.copied'))
    } catch {
      toast.error(t('webhooks.secret.copyFailed'))
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <Alert tone="warning" title={t('webhooks.secret.onceTitle')}>
        {t('webhooks.secret.onceBody')}
      </Alert>
      <div className="flex flex-wrap items-center gap-2">
        <code data-testid="webhook-signing-secret" className="min-w-0 flex-1 rounded-md border border-border bg-surface-100 px-3 py-2 font-mono text-caption break-all text-ink select-all">
          {secret}
        </code>
        <Button icon="copy" onClick={copy}>
          {t('webhooks.secret.copy')}
        </Button>
      </div>
    </div>
  )
}
