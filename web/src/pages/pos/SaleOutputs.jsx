import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Card, Dialog, Select, StatusBadge, TextField } from '@/components/ds'
import { downloadFile } from '@/lib/files'

const SHARE_TONES = { active: 'success', revoked: 'neutral', expired: 'neutral' }

/** The print frame's sandbox (TPL-04): never allow-same-origin. */
const PRINT_SANDBOX = 'allow-scripts allow-modals'

/** The receipt's HTML with its scripts removed and one call to print once it has loaded. */
function printable(html) {
  const clean = String(html ?? '').replace(/<script\b[\s\S]*?<\/script\s*>/gi, '')
  const call = '<script>window.addEventListener("load", function () { window.print() })</script>'
  return clean.includes('</body>') ? clean.replace('</body>', `${call}</body>`) : clean + call
}

const fieldError = (error, field) => {
  const messages = error?.status === 422 ? error.errors?.[field] : null
  return messages ? (Array.isArray(messages) ? messages[0] : String(messages)) : undefined
}

/** Email the receipt as a PDF (queued; rate-limited by the API: 429 too_many_emails). */
function EmailDialog({ open, saleId, customerEmail, onClose }) {
  const { t, i18n } = useTranslation()
  const [email, setEmail] = useState('')
  const [language, setLanguage] = useState(i18n.resolvedLanguage === 'fr' ? 'fr' : 'en')
  const send = useMutation({
    mutationFn: () => api.post(`pos/sales/${saleId}/email`, { ...(email.trim() ? { email: email.trim() } : {}), language }),
    onSuccess: (response) => {
      toast.success(t('pos.sale.outputs.emailQueued', { to: response?.data?.to ?? email.trim() }))
      setEmail('')
      onClose()
    },
  })
  const emailError = fieldError(send.error, 'email')
  const general = send.error && !emailError ? errorMessage(send.error) : null
  const close = () => {
    send.reset()
    onClose()
  }

  return (
    <Dialog
      open={open}
      title={t('pos.sale.outputs.emailTitle')}
      onClose={close}
      footer={
        <>
          <Button variant="ghost" onClick={close}>
            {t('common.cancel')}
          </Button>
          <Button variant="primary" icon="mail" loading={send.isPending} onClick={() => send.mutate()}>
            {t('pos.sale.outputs.sendEmail')}
          </Button>
        </>
      }
    >
      <div className="flex flex-col gap-4 pt-1">
        {general ? <Alert tone="danger" title={general} /> : null}
        <TextField
          label={t('pos.sale.outputs.emailTo')}
          type="email"
          autoComplete="email"
          value={email}
          placeholder={customerEmail ?? ''}
          help={customerEmail ? t('pos.sale.outputs.emailBlankCustomer', { email: customerEmail }) : t('pos.sale.outputs.emailBlank')}
          error={emailError}
          maxLength={254}
          onChange={(event) => setEmail(event.target.value)}
        />
        <Select
          label={t('pos.sale.outputs.language')}
          options={[
            { value: 'en', label: t('pos.sale.outputs.languages.en') },
            { value: 'fr', label: t('pos.sale.outputs.languages.fr') },
          ]}
          value={language}
          onChange={(event) => setLanguage(event.target.value)}
        />
      </div>
    </Dialog>
  )
}

/** The links to the receipt shared so far (7 days each), with opens and Revoke. */
function ShareLinks({ saleId, when, latest }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const shares = useQuery({ queryKey: ['pos-sales', 'shares', saleId], queryFn: () => api.get(`pos/sales/${saleId}/shares`) })
  const revoke = useMutation({
    mutationFn: (id) => api.post(`pos/sales/${saleId}/shares/${id}/revoke`),
    onSuccess: () => {
      toast.success(t('pos.sale.outputs.revoked'))
      return queryClient.invalidateQueries({ queryKey: ['pos-sales', 'shares', saleId] })
    },
  })
  const list = shares.data?.data ?? []

  const copy = async (url) => {
    try {
      await navigator.clipboard.writeText(url)
      toast.success(t('pos.sale.outputs.copied'))
    } catch {
      toast.error(t('pos.sale.outputs.copyFailed'))
    }
  }

  return (
    <div className="flex flex-col gap-3">
      {latest ? (
        <div className="flex flex-col gap-2 rounded-md border border-border bg-surface-100 p-4">
          <TextField label={t('pos.sale.outputs.link')} value={latest.url} readOnly onFocus={(event) => event.target.select()} />
          <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="text-caption text-ink-muted tabular-nums">{t('pos.sale.outputs.expires', { time: when(latest.expires_at) })}</span>
            <Button icon="copy" onClick={() => copy(latest.url)}>
              {t('pos.sale.outputs.copyLink')}
            </Button>
          </div>
        </div>
      ) : null}
      <h4 className="text-label text-ink">{t('pos.sale.outputs.links')}</h4>
      {shares.isError ? <Alert tone="danger" title={errorMessage(shares.error)} /> : null}
      {revoke.isError ? <Alert tone="danger" title={errorMessage(revoke.error)} /> : null}
      {shares.isSuccess && list.length === 0 ? <p className="text-caption text-ink-muted">{t('pos.sale.outputs.noLinks')}</p> : null}
      {list.length > 0 ? (
        <ul aria-label={t('pos.sale.outputs.links')} className="flex flex-col divide-y divide-border">
          {list.map((share) => (
            <li key={share.id} className="flex flex-wrap items-center justify-between gap-3 py-2">
              <span className="flex flex-col gap-1">
                <span className="flex items-center gap-3">
                  <StatusBadge tone={SHARE_TONES[share.status] ?? 'neutral'}>{t(`pos.sale.outputs.status.${share.status}`, { defaultValue: share.status })}</StatusBadge>
                  <span className="text-caption text-ink-muted tabular-nums">{t('pos.sale.outputs.created', { time: when(share.created_at) })}</span>
                </span>
                <span className="text-caption text-ink-muted tabular-nums">
                  {[
                    t('pos.sale.outputs.opens', { count: share.access_count ?? 0 }),
                    share.last_accessed_at ? t('pos.sale.outputs.lastOpened', { time: when(share.last_accessed_at) }) : null,
                    share.status === 'active' ? t('pos.sale.outputs.expires', { time: when(share.expires_at) }) : null,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </span>
              </span>
              {share.status === 'active' ? (
                <Button variant="danger" loading={revoke.isPending && revoke.variables === share.id} onClick={() => revoke.mutate(share.id)}>
                  {t('pos.sale.outputs.revoke')}
                </Button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

/**
 * TPL-04: the sale's receipt as its template prints it. Print (the HTML in
 * a sandboxed frame with an opaque origin that prints itself), Download PDF,
 * and, with `pos.sale.share` at the sale, Email and a WhatsApp link.
 */
export function SaleOutputs({ sale, canShare, when }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [printing, setPrinting] = useState(null) // { html, n }
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)
  const [emailOpen, setEmailOpen] = useState(false)
  const [latest, setLatest] = useState(null)

  const run = async (name, action) => {
    setError(null)
    setBusy(name)
    try {
      await action()
    } catch (failure) {
      setError(errorMessage(failure))
    } finally {
      setBusy(null)
    }
  }

  const print = () =>
    run('print', async () => {
      const { blob } = await api.download(`pos/sales/${sale.id}/receipt?format=html`)
      const html = await blob.text()
      setPrinting((current) => ({ html, n: (current?.n ?? 0) + 1 }))
    })
  const pdf = () => run('pdf', () => downloadFile(`pos/sales/${sale.id}/receipt?format=pdf`, `${sale.receipt_number}.pdf`))
  const share = () =>
    run('share', async () => {
      const response = await api.post(`pos/sales/${sale.id}/share`)
      const data = response?.data
      if (data?.whatsapp_url) window.open(data.whatsapp_url, '_blank', 'noopener,noreferrer')
      setLatest(data ?? null)
      await queryClient.invalidateQueries({ queryKey: ['pos-sales', 'shares', sale.id] })
    })

  return (
    <Card title={t('pos.sale.outputs.title')} subtitle={t('pos.sale.outputs.subtitle')}>
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap gap-2">
          <Button icon="print" loading={busy === 'print'} onClick={print}>
            {t('pos.sale.outputs.print')}
          </Button>
          <Button icon="download" loading={busy === 'pdf'} onClick={pdf}>
            {t('pos.sale.outputs.downloadPdf')}
          </Button>
          {canShare ? (
            <>
              <Button icon="mail" onClick={() => setEmailOpen(true)}>
                {t('pos.sale.outputs.email')}
              </Button>
              <Button icon="whatsapp" loading={busy === 'share'} onClick={share}>
                {t('pos.sale.outputs.whatsapp')}
              </Button>
            </>
          ) : null}
        </div>
        {error ? <Alert tone="danger" title={error} /> : null}
        {canShare ? <ShareLinks saleId={sale.id} when={when} latest={latest} /> : null}
      </div>
      {printing ? (
        // The receipt prints itself in a frame with an opaque origin: allow-scripts runs only
        // the print call added here (every value in the receipt is escaped by the server), and
        // without allow-same-origin the frame can reach nothing of this page; allow-modals opens
        // the print dialog.
        <iframe
          key={printing.n}
          title={t('pos.sale.outputs.printFrame')}
          sandbox={PRINT_SANDBOX}
          srcDoc={printable(printing.html)}
          aria-hidden="true"
          tabIndex={-1}
          className="sr-only"
        />
      ) : null}
      {canShare ? <EmailDialog open={emailOpen} saleId={sale.id} customerEmail={sale.customer?.email ?? null} onClose={() => setEmailOpen(false)} /> : null}
    </Card>
  )
}
