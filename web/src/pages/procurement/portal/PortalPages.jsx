import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect, useId, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, MoneyInput, Select, StatusBadge, TextField } from '@/components/ds'
import { formatDateTime } from '@/lib/dates'
import { formatBytes } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { TextAreaField } from '@/pages/approvals/TextAreaField'
import { Detail, Qty } from '@/pages/inventory/InventoryParts'
import { STATUS_TONES, statusLabel } from '../procurementData'
import { getPortalSession, portal, setPortalSession } from './portalClient'

const viewKey = ['supplier-portal', 'tender']

/** PRC-07, L10N-03: an instant in the tender's time zone (its branch's, else its company's), named after the time. */
function At({ value, zone }) {
  const locale = useLocale()
  return value ? (
    <span className="tabular-nums">
      {formatDateTime(value, locale, zone)}
      {zone ? <span className="text-caption text-ink-muted"> {zone}</span> : null}
    </span>
  ) : (
    <span className="text-ink-muted">—</span>
  )
}

/** The tender as any bidder may know it: number, title, company, branch, times, lines and criteria (PRC-08). */
function TenderFacts({ tender }) {
  const { t } = useTranslation()
  return (
    <>
      <header className="flex flex-col gap-1">
        <p className="text-body text-ink-muted">{[tender.number, tender.company, tender.branch].filter(Boolean).join(' · ')}</p>
        <h1 className="text-h1 text-ink">{tender.title}</h1>
        {tender.description ? <p className="text-body whitespace-pre-line text-ink-muted">{tender.description}</p> : null}
      </header>
      <Card>
        <dl className="grid gap-4 sm:grid-cols-3">
          <Detail label={t('procurement.portal.deadline')}>
            <At value={tender.deadline_at} zone={tender.timezone} />
          </Detail>
          <Detail label={t('procurement.portal.opening')}>
            <At value={tender.opening_at} zone={tender.timezone} />
          </Detail>
          <Detail label={t('procurement.portal.weights')}>{t('procurement.tender.weightsValue', { technical: tender.technical_weight, financial: tender.financial_weight })}</Detail>
        </dl>
      </Card>
      <Card title={t('procurement.lines.title')}>
        <DataTable
          caption={t('procurement.lines.title')}
          columns={[
            { key: 'line_no', label: t('procurement.columns.lineNo'), numeric: true, render: (line) => line.line_no },
            { key: 'description', label: t('procurement.columns.item'), wrap: true, render: (line) => line.description },
            { key: 'qty', label: t('procurement.columns.qty'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
          ]}
          rows={tender.lines ?? []}
        />
      </Card>
      {tender.criteria?.length ? (
        <Card title={t('procurement.tender.criteria')}>
          <DataTable
            caption={t('procurement.tender.criteria')}
            columns={[
              { key: 'name', label: t('procurement.fields.criterionName'), wrap: true, render: (criterion) => criterion.name },
              { key: 'weight', label: t('procurement.fields.weight'), align: 'end', numeric: true, render: (criterion) => `${criterion.weight} %` },
              { key: 'max_score', label: t('procurement.fields.maxScore'), align: 'end', numeric: true, render: (criterion) => criterion.max_score },
            ]}
            rows={(tender.criteria ?? []).map((criterion, index) => ({ ...criterion, id: index }))}
          />
        </Card>
      ) : null}
    </>
  )
}

/** PRC-07: the emailed link (`/supplier-portal/:token`) is exchanged once for a portal session, then the tender opens. */
export function PortalSignIn() {
  const { t } = useTranslation()
  const { token } = useParams()
  const navigate = useNavigate()
  const started = useRef(false)
  const [error, setError] = useState(null)
  useEffect(() => {
    if (started.current) return
    started.current = true
    portal('POST', 'sessions', { body: { token }, session: false })
      .then((response) => {
        setPortalSession(response?.data?.session_token ?? null)
        navigate('/supplier-portal/tender', { replace: true })
      })
      .catch(setError)
  }, [token, navigate])
  if (error) {
    return (
      <Alert tone="danger" title={t('procurement.portal.linkInvalid')}>
        {t('procurement.portal.linkInvalidHelp')}
      </Alert>
    )
  }
  return <p className="text-ink-muted">{t('procurement.portal.signingIn')}</p>
}

/** PRC-09: the bid form: currency, a unit price per line (blank: no offer), the technical response and attachments. */
function BidForm({ view }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const queryClient = useQueryClient()
  const fileId = useId()
  const { tender } = view
  const [currency, setCurrency] = useState(tender.currency)
  const [prices, setPrices] = useState({})
  const [technical, setTechnical] = useState('')
  const [submitted, setSubmitted] = useState(false)
  // PRC-09: only the tenant's active currencies (each needs a rate when the bid is submitted).
  const currencies = (view.currencies?.length ? view.currencies : [tender.currency]).filter(Boolean).map((code) => ({ value: code, label: code }))
  const offered = (tender.lines ?? []).filter((line) => prices[line.id] !== undefined && prices[line.id] !== '' && prices[line.id] !== null)
  const upload = useMutation({
    mutationFn: async (files) => {
      for (const file of files) {
        const form = new FormData()
        form.append('file', file)
        await portal('POST', 'bid/files', { form })
      }
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: viewKey }),
  })
  // PRC-09: an upload not yet in a submitted bid can be taken out.
  const remove = useMutation({
    mutationFn: (file) => portal('DELETE', `bid/files/${file.id}`),
    onSettled: () => queryClient.invalidateQueries({ queryKey: viewKey }),
  })
  const submit = useMutation({
    mutationFn: () =>
      portal('PUT', 'bid', {
        body: {
          currency,
          lines: offered.map((line) => ({ tender_line_id: line.id, unit_price_minor: prices[line.id] })),
          ...(technical.trim() ? { technical_response: technical.trim() } : {}),
          file_ids: (view.files ?? []).map((file) => file.id),
        },
      }),
    onSuccess: (response) => {
      if (response?.data) queryClient.setQueryData(viewKey, response)
      setPrices({})
      setTechnical('')
      setSubmitted(false)
    },
  })
  const errors = formErrors(submit.error, ['currency', 'lines', 'technical_response', 'file_ids'])
  const send = (event) => {
    event.preventDefault()
    setSubmitted(true)
    if (offered.length) submit.mutate()
  }

  return (
    <form noValidate onSubmit={send} className="flex flex-col gap-5">
      <Card title={t(view.bid?.status === 'submitted' ? 'procurement.portal.replaceTitle' : 'procurement.portal.bidTitle')} subtitle={t('procurement.portal.bidHelp')}>
        <div className="flex flex-col gap-4">
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          <Select label={t('procurement.fields.currency')} options={currencies} value={currency} error={errors.fields.currency} onChange={(event) => {
              // Prices are minor units of the currency: another currency clears them (stage 7B review).
              if (event.target.value !== currency) setPrices({})
              setCurrency(event.target.value)
            }}
            className="max-w-field"
          />
          {errors.fields.lines || (submitted && !offered.length) ? <Alert tone="danger" title={errors.fields.lines ?? t('procurement.portal.noPrices')} /> : null}
          <ul aria-label={t('procurement.portal.prices')} className="flex flex-col gap-3">
            {(tender.lines ?? []).map((line) => (
              <li key={line.id} className="grid gap-3 rounded-md border border-border p-3 sm:grid-cols-2">
                <span className="flex flex-col">
                  <span className="text-ink">{line.description}</span>
                  <span className="text-caption text-ink-muted">
                    <Qty value={line.qty} unit={line.uom?.code} />
                  </span>
                </span>
                <MoneyInput
                  key={currency}
                  label={t('procurement.portal.unitPriceOf', { line: line.line_no })}
                  help={t('procurement.quote.noOffer')}
                  currency={currency}
                  value={prices[line.id] ?? ''}
                  onChange={(value) => setPrices((current) => ({ ...current, [line.id]: value ?? '' }))}
                />
              </li>
            ))}
          </ul>
          <TextAreaField label={t('procurement.portal.technicalResponse')} rows={6} value={technical} maxLength={20000} error={errors.fields.technical_response} onChange={(event) => setTechnical(event.target.value)} />
          <div className="flex flex-col gap-2">
            <label htmlFor={fileId} className="text-label text-ink">
              {t('procurement.portal.attachments')}
            </label>
            <input
              id={fileId}
              type="file"
              multiple
              aria-describedby={`${fileId}-help`}
              disabled={upload.isPending}
              onChange={(event) => {
                const files = [...(event.target.files ?? [])]
                event.target.value = ''
                if (files.length) upload.mutate(files)
              }}
              className="text-body text-ink file:mr-3 file:rounded-md file:border file:border-border-strong file:bg-surface-200 file:px-3 file:py-2 file:text-label file:text-ink"
            />
            <p id={`${fileId}-help`} className="text-caption text-ink-muted">
              {t('procurement.portal.attachmentsHelp')}
            </p>
            {upload.isError ? <Alert tone="danger" title={errorMessage(upload.error)} /> : null}
            {remove.isError ? <Alert tone="danger" title={errorMessage(remove.error)} /> : null}
            {view.files?.length ? (
              <ul aria-label={t('procurement.portal.uploaded')} className="flex flex-col gap-1">
                {view.files.map((file) => (
                  <li key={file.id} className="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border px-3 py-2">
                    <span className="text-ink">{file.name}</span>
                    <span className="flex items-center gap-3">
                      <span className="text-caption text-ink-muted">{formatBytes(file.size, locale)}</span>
                      {file.attached ? null : (
                        <Button variant="ghost" aria-label={t('procurement.portal.removeFileOf', { name: file.name })} loading={remove.isPending && remove.variables?.id === file.id} onClick={() => remove.mutate(file)}>
                          {t('procurement.portal.removeFile')}
                        </Button>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
          <div>
            <Button type="submit" variant="pay" loading={submit.isPending}>
              {t(view.bid?.status === 'submitted' ? 'procurement.portal.replace' : 'procurement.portal.submit')}
            </Button>
          </div>
        </div>
      </Card>
    </form>
  )
}

/** PRC-09: the bidder's receipt: status, version, time and fingerprint; the bid stays sealed and is not shown back. */
function Receipt({ view, onWithdraw, withdrawing }) {
  const { t } = useTranslation()
  const { bid, tender } = view
  return (
    <Card title={t('procurement.portal.receiptTitle')}>
      <div className="flex flex-col gap-4">
        <dl className="grid gap-4 sm:grid-cols-3">
          <Detail label={t('procurement.columns.status')}>
            <StatusBadge tone={STATUS_TONES[bid.outcome ?? bid.status] ?? 'neutral'}>{statusLabel(t, bid.outcome ?? bid.status)}</StatusBadge>
          </Detail>
          <Detail label={t('procurement.columns.version')}>{bid.version}</Detail>
          <Detail label={t('procurement.columns.submittedAt')}>
            <At value={bid.status === 'withdrawn' ? bid.withdrawn_at : bid.submitted_at} zone={view.tender.timezone} />
          </Detail>
          <div className="sm:col-span-3">
            <Detail label={t('procurement.columns.fingerprint')}>
              <span className="font-mono text-caption break-all">{bid.fingerprint}</span>
            </Detail>
          </div>
        </dl>
        <p className="text-body text-ink-muted">{t('procurement.portal.sealedNote')}</p>
        {bid.status === 'submitted' && tender.open_for_bids ? (
          <div>
            <Button variant="danger" loading={withdrawing} onClick={onWithdraw}>
              {t('procurement.portal.withdraw')}
            </Button>
          </div>
        ) : null}
      </div>
    </Card>
  )
}

/** PRC-07 to PRC-09: the session's tender, the bid form while bids are open, the receipt, withdraw and sign out. */
export function PortalTender() {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [signedOut, setSignedOut] = useState(false)
  const hasSession = Boolean(getPortalSession())
  const query = useQuery({ queryKey: viewKey, queryFn: () => portal('GET', 'tender'), enabled: hasSession && !signedOut, retry: false })
  const withdraw = useMutation({ mutationFn: () => portal('POST', 'bid/withdraw'), onSuccess: (response) => response?.data && queryClient.setQueryData(viewKey, response) })
  const signOut = useMutation({
    mutationFn: () => portal('DELETE', 'sessions/current'),
    onSettled: () => {
      setPortalSession(null)
      queryClient.removeQueries({ queryKey: viewKey })
      setSignedOut(true)
    },
  })

  if (signedOut) return <Alert tone="success" title={t('procurement.portal.signedOut')}>{t('procurement.portal.signedOutHelp')}</Alert>
  if (!hasSession || query.error?.status === 401) {
    return (
      <Alert tone="warning" title={t('procurement.portal.noSession')}>
        {t('procurement.portal.noSessionHelp')}
      </Alert>
    )
  }
  if (query.isError) return <Alert tone="danger" title={errorMessage(query.error)} />
  const view = query.data?.data
  if (!view) return <p className="text-ink-muted">{t('common.loading')}</p>

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-body text-ink-muted">{t('procurement.portal.signedInAs', { name: view.bidder?.name ?? '', email: view.bidder?.email ?? '' })}</p>
        <Button variant="ghost" icon="signOut" loading={signOut.isPending} onClick={() => signOut.mutate()}>
          {t('procurement.portal.signOut')}
        </Button>
      </div>
      <TenderFacts tender={view.tender} />
      {withdraw.isError ? <Alert tone="danger" title={errorMessage(withdraw.error)} /> : null}
      {view.bid ? <Receipt view={view} withdrawing={withdraw.isPending} onWithdraw={() => withdraw.mutate()} /> : null}
      {view.tender.unavailable ? (
        // Decision 21 (owner, 2026-10-14): a suspended or cancelled business takes no bids.
        <Alert tone="warning" title={t('procurement.portal.unavailable')}>
          {t('procurement.portal.unavailableHelp')}
        </Alert>
      ) : view.tender.open_for_bids ? (
        <BidForm key={`${view.bid?.version ?? 0}-${view.bid?.status ?? 'none'}`} view={view} />
      ) : (
        <Alert tone="info" title={t('procurement.portal.closed')} />
      )}
    </>
  )
}

/** PRC-08: an open tender's public notice, and registration (the link is emailed, so the address is proven). */
export function PortalNotice() {
  const { t } = useTranslation()
  const { notice } = useParams()
  const query = useQuery({ queryKey: ['supplier-portal', 'notice', notice], queryFn: () => portal('GET', `notices/${notice}`, { session: false }), retry: false })
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [taxId, setTaxId] = useState('')
  const register = useMutation({ mutationFn: () => portal('POST', `notices/${notice}/register`, { body: { name: name.trim(), email: email.trim(), ...(taxId.trim() ? { tax_id: taxId.trim() } : {}) }, session: false }) })
  const errors = formErrors(register.error, ['name', 'email', 'tax_id'])
  const tender = query.data?.data

  if (query.isError) {
    return (
      <Alert tone="danger" title={t('procurement.portal.noticeInvalid')}>
        {t('procurement.portal.noticeInvalidHelp')}
      </Alert>
    )
  }
  if (!tender) return <p className="text-ink-muted">{t('common.loading')}</p>
  return (
    <>
      <TenderFacts tender={tender} />
      {register.isSuccess ? (
        <Alert tone="success" title={t('procurement.portal.linkSent')}>
          {t('procurement.portal.linkSentHelp', { email: email.trim() })}
        </Alert>
      ) : tender.open_for_bids ? (
        <Card title={t('procurement.portal.registerTitle')} subtitle={t('procurement.portal.registerHelp')}>
          <form
            noValidate
            onSubmit={(event) => {
              event.preventDefault()
              if (name.trim() && email.trim()) register.mutate()
            }}
            className="flex flex-col gap-4"
          >
            {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
            <TextField label={t('procurement.portal.companyName')} value={name} maxLength={200} required error={errors.fields.name} onChange={(event) => setName(event.target.value)} />
            <TextField type="email" label={t('procurement.fields.email')} value={email} maxLength={254} required error={errors.fields.email} onChange={(event) => setEmail(event.target.value)} />
            <TextField label={t('procurement.fields.taxId')} value={taxId} maxLength={60} error={errors.fields.tax_id} onChange={(event) => setTaxId(event.target.value)} />
            <div>
              <Button type="submit" variant="primary" icon="mail" loading={register.isPending} disabled={!name.trim() || !email.trim()}>
                {t('procurement.portal.register')}
              </Button>
            </div>
          </form>
        </Card>
      ) : (
        <Alert tone="info" title={t('procurement.portal.closed')} />
      )}
    </>
  )
}
