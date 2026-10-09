import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Dialog, Icon } from '@/components/ds'

const KINDS = ['stk', 'c2b-confirm', 'c2b-validate', 'b2c-result', 'b2c-timeout', 'status-result', 'status-timeout']

/** One callback URL, read-only, with a copy button. The URL holds a token: it is a secret. */
function UrlRow({ kind, url }) {
  const { t } = useTranslation()
  const [copied, setCopied] = useState(false)
  const label = t(`paymentMethods.callbacks.kinds.${kind}`, { defaultValue: kind })
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(url)
      setCopied(true)
    } catch {
      setCopied(false)
    }
  }
  return (
    <li className="flex flex-col gap-1 py-2">
      <span className="text-caption text-ink-muted">{label}</span>
      <div className="flex items-start gap-2">
        <code className="min-w-0 flex-1 rounded-md border border-border bg-surface-100 px-2 py-1 font-mono text-caption break-all text-ink">{url}</code>
        <Button variant="ghost" className="size-icon-btn shrink-0 px-0" onClick={copy} aria-label={t('paymentMethods.callbacks.copyFor', { kind: label })}>
          <Icon name={copied ? 'check' : 'copy'} />
        </Button>
      </div>
    </li>
  )
}

/**
 * Concept note 7.1 (M-Pesa Daraja, `core.payment_method.configure`): the
 * method's callback URLs to register with Safaricom, a new token when
 * they may have leaked (the old URLs stop at once), and registering the
 * C2B URLs for the method's Paybill or Till.
 */
export function CallbacksDialog({ method, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [confirmRotate, setConfirmRotate] = useState(false)
  const key = ['payment-method-callbacks', method.id]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`payment-methods/${method.id}/callbacks`) })
  const rotate = useMutation({
    mutationFn: () => api.post(`payment-methods/${method.id}/callbacks/rotate`, {}),
    onSuccess: (answer) => {
      queryClient.setQueryData(key, answer)
      setConfirmRotate(false)
    },
  })
  const register = useMutation({ mutationFn: () => api.post(`payment-methods/${method.id}/c2b/register`, {}) })
  const urls = query.data?.data?.urls ?? {}
  const error = query.error ?? rotate.error ?? register.error

  return (
    <Dialog
      open
      size="lg"
      title={t('paymentMethods.callbacks.title', { name: method.name })}
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" onClick={onClose}>
            {t('paymentMethods.callbacks.close')}
          </Button>
          <Button variant="primary" loading={register.isPending} disabled={!method.configured} onClick={() => register.mutate()}>
            {t('paymentMethods.callbacks.register')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4">
        {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
        {register.isSuccess ? <Alert tone="success" title={t('paymentMethods.callbacks.registered')} /> : null}
        {rotate.isSuccess ? <Alert tone="info" title={t('paymentMethods.callbacks.rotated')} /> : null}
        <p>{t('paymentMethods.callbacks.intro')}</p>
        {!method.configured ? <p className="text-caption text-ink-muted">{t('paymentMethods.callbacks.needsSettings')}</p> : null}
        {query.isPending ? (
          <p className="text-ink-muted">{t('common.loading')}</p>
        ) : (
          <ul aria-label={t('paymentMethods.callbacks.list')} className="divide-y divide-border">
            {KINDS.filter((kind) => urls[kind]).map((kind) => (
              <UrlRow key={kind} kind={kind} url={urls[kind]} />
            ))}
          </ul>
        )}
        <div className="flex flex-col gap-2 rounded-md border border-border p-3">
          <p className="text-caption text-ink-muted">{t('paymentMethods.callbacks.rotateHelp')}</p>
          {confirmRotate ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="ghost" onClick={() => setConfirmRotate(false)}>
                {t('paymentMethods.callbacks.keepUrls')}
              </Button>
              <Button variant="danger" loading={rotate.isPending} onClick={() => rotate.mutate()}>
                {t('paymentMethods.callbacks.rotateConfirm')}
              </Button>
            </div>
          ) : (
            <Button className="w-fit" icon="sync" onClick={() => setConfirmRotate(true)}>
              {t('paymentMethods.callbacks.rotate')}
            </Button>
          )}
        </div>
      </div>
    </Dialog>
  )
}
