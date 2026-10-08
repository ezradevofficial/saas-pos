import { useMutation } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Icon, Money, StatusBadge } from '@/components/ds'
import { approvalStatus, useApprovalActions, useApprovalDetail } from '@/lib/approvals'
import { formatCompanyTime } from './approvalData'
import { formatBytes, formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { ApprovalActions } from './ApprovalActions'
import { TextAreaField } from './TextAreaField'

/** The largest file the API accepts (AttachToApprovalRequest::MAX_KB). */
const MAX_FILE_BYTES = 10 * 1024 * 1024

function Section({ title, children }) {
  return (
    <section aria-label={title} className="flex flex-col gap-2">
      <h3 className="text-label text-ink">{title}</h3>
      {children}
    </section>
  )
}

function Fact({ term, children }) {
  return (
    <div className="flex min-w-0 flex-col">
      <dt className="text-caption text-ink-muted">{term}</dt>
      <dd className="text-body text-ink">{children}</dd>
    </div>
  )
}

/**
 * "Why this route" (APR-04): the sentences the API builds from the
 * document's values when the user may see the document; otherwise the
 * fields each condition compared and whether each held (never the values).
 * Then the escalation or final time limit of this step.
 */
function WhyThisRoute({ approval }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const route = approval.route ?? []
  const lines = []

  for (const entry of route) {
    const sentences = entry.explanations ?? []
    if (sentences.length) {
      for (const sentence of sentences) lines.push({ key: `${entry.node_id}-${sentence}`, text: sentence })
      if (entry.kind === 'skipped') lines.push({ key: `${entry.node_id}-skipped`, text: t('approvals.route.skipped', { name: entry.node_name ?? entry.node_id }) })
      continue
    }
    if (entry.kind === 'skipped') {
      lines.push({ key: `${entry.node_id}-skipped`, text: t('approvals.route.skipped', { name: entry.node_name ?? entry.node_id }) })
    }
    // A viewer who may not see the document gets neither checks nor sentences: only the step.
    if (entry.kind !== 'skipped' && !entry.checks?.length) {
      lines.push({ key: `${entry.node_id}-step`, text: t('approvals.route.condition', { name: entry.node_name ?? entry.node_id }) })
    }
    for (const [index, check] of (entry.checks ?? []).entries()) {
      lines.push({
        key: `${entry.node_id}-${index}`,
        check: true,
        passed: check.passed,
        text: t(check.passed ? 'approvals.route.checkMet' : 'approvals.route.checkNotMet', { field: check.label ?? check.field, step: entry.node_name ?? entry.node_id }),
      })
    }
  }

  const escalation = approval.escalation?.at
    ? approval.escalation.to
      ? t('approvals.hint.escalatesTo', { to: approval.escalation.to, when: formatCompanyTime(approval.escalation.at, locale, approval.company) })
      : t('approvals.hint.escalates', { when: formatCompanyTime(approval.escalation.at, locale, approval.company) })
    : null
  const final = approval.final?.outcome
    ? t(`approvals.route.final.${approval.final.outcome === 'reject' ? 'reject' : 'approve'}${approval.final.at ? 'At' : 'AfterEscalation'}`, {
        when: approval.final.at ? formatCompanyTime(approval.final.at, locale, approval.company) : '',
      })
    : null

  return (
    <Section title={t('approvals.detail.why')}>
      {lines.length === 0 ? <p className="text-ink-muted">{t('approvals.route.none')}</p> : null}
      {lines.length ? (
        <ul className="flex flex-col gap-1">
          {lines.map((line) =>
            line.check ? (
              <li key={line.key}>
                <StatusBadge tone={line.passed ? 'success' : 'neutral'} className="text-body font-normal">
                  {line.text}
                </StatusBadge>
              </li>
            ) : (
              <li key={line.key} className="text-ink-muted">
                {line.text}
              </li>
            ),
          )}
        </ul>
      ) : null}
      {escalation ? <p className="text-ink-muted">{escalation}</p> : null}
      {final ? <p className="text-ink-muted">{final}</p> : null}
    </Section>
  )
}

const ASSIGNMENT_TONES = { pending: 'warning', approved: 'success', rejected: 'danger', returned: 'warning' }
const ASSIGNMENT_STATES = ['pending', 'approved', 'rejected', 'returned', 'reassigned', 'closed']

function Approvers({ approvers }) {
  const { t } = useTranslation()
  if (!approvers?.length) return null
  return (
    <Section title={t('approvals.detail.approvers')}>
      <ul className="flex flex-col gap-2">
        {approvers.map((entry) => (
          <li key={entry.id} className="flex flex-wrap items-center justify-between gap-2">
            <span className="flex min-w-0 flex-col">
              <span className="text-ink">{entry.user?.name ?? t('approvals.someone')}</span>
              <span className="text-caption text-ink-muted">
                {[
                  t('approvals.detail.stepNumber', { step: entry.step }),
                  entry.on_behalf_of ? t('approvals.detail.decidedOnBehalf', { by: entry.decided_by?.name ?? t('approvals.someone'), name: entry.on_behalf_of.name ?? t('approvals.someone') }) : null,
                  entry.reassigned_from ? t('approvals.detail.reassignedFrom', { name: entry.reassigned_from.name ?? t('approvals.someone') }) : null,
                ]
                  .filter(Boolean)
                  .join(' · ')}
              </span>
            </span>
            <StatusBadge tone={ASSIGNMENT_TONES[entry.status] ?? 'neutral'}>
              {t(`approvals.assignment.${ASSIGNMENT_STATES.includes(entry.status) ? entry.status : 'closed'}`)}
            </StatusBadge>
          </li>
        ))}
      </ul>
    </Section>
  )
}

function History({ history, company }) {
  const { t } = useTranslation()
  const locale = useLocale()
  return (
    <Section title={t('approvals.detail.history')}>
      {history?.length ? (
        <ol className="flex flex-col gap-3">
          {history.map((entry) => (
            <li key={entry.id ?? `${entry.type}-${entry.occurred_at}`} className="flex flex-col gap-1 sm:flex-row sm:gap-3">
              <span className="shrink-0 text-caption text-ink-muted tabular-nums">{formatCompanyTime(entry.occurred_at, locale, company)}</span>
              <span className="flex min-w-0 flex-col">
                <span className="text-ink">
                  {entry.label}
                  {entry.user?.name ? ` · ${entry.user.name}` : ''}
                  {entry.on_behalf_of?.name ? ` ${t('approvals.history.onBehalfOf', { name: entry.on_behalf_of.name })}` : ''}
                </span>
                {entry.comment ? <span className="text-ink-muted">{entry.comment}</span> : null}
              </span>
            </li>
          ))}
        </ol>
      ) : (
        <p className="text-ink-muted">{t('approvals.detail.noHistory')}</p>
      )}
    </Section>
  )
}

/** Files on the request (APR-03): links valid 15 minutes, uploads with progress. */
function Attachments({ approval, onUploaded }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const inputRef = useRef(null)
  const [progress, setProgress] = useState(null)
  const [localError, setLocalError] = useState(null)
  const upload = useMutation({
    mutationFn: (file) => {
      const form = new FormData()
      form.append('file', file)
      setProgress({ name: file.name, fraction: 0 })
      return api.upload(`approvals/${approval.id}/attachments`, form, { onProgress: (fraction) => setProgress({ name: file.name, fraction }) })
    },
    onSuccess: (response, file) => {
      onUploaded(response)
      toast.success(t('approvals.attachments.uploaded', { name: file.name }))
    },
    onSettled: () => setProgress(null),
  })
  const files = approval.attachments ?? []
  const choose = (file) => {
    setLocalError(null)
    upload.reset()
    if (!file) return
    if (file.size > MAX_FILE_BYTES) return setLocalError(t('approvals.attachments.tooLarge', { size: formatBytes(file.size, locale), max: formatBytes(MAX_FILE_BYTES, locale) }))
    upload.mutate(file)
  }
  const failure = localError ?? (upload.error ? errorMessage(upload.error) : null)

  if (!files.length && !approval.can?.attach) return null
  return (
    <Section title={t('approvals.detail.attachments')}>
      {failure ? <Alert tone="danger" title={failure} /> : null}
      {files.length ? (
        <ul className="flex flex-col gap-1">
          {files.map((file) => (
            <li key={file.id} className="flex flex-wrap items-center gap-2">
              <Icon name="attach" className="text-ink-muted" />
              <a href={file.url} target="_blank" rel="noopener noreferrer" className="font-medium text-primary hover:text-primary-hover">
                {file.name}
              </a>
              <span className="text-caption text-ink-muted">
                {[formatBytes(file.size, locale), file.uploaded_by?.name].filter(Boolean).join(' · ')}
              </span>
            </li>
          ))}
        </ul>
      ) : null}
      {progress ? (
        <div className="flex flex-col gap-1" role="status">
          <span className="text-caption text-ink-muted">{t('approvals.attachments.uploading', { name: progress.name, percent: Math.round(progress.fraction * 100) })}</span>
          <progress className="h-2 w-full accent-primary" value={progress.fraction} max={1} aria-label={t('approvals.attachments.progress')} />
        </div>
      ) : null}
      {approval.can?.attach ? (
        <div>
          <input
            ref={inputRef}
            type="file"
            className="sr-only"
            tabIndex={-1}
            aria-label={t('approvals.attachments.choose')}
            onChange={(event) => {
              choose(event.target.files?.[0])
              event.target.value = ''
            }}
          />
          <Button variant="ghost" icon="attach" disabled={upload.isPending} onClick={() => inputRef.current?.click()}>
            {t('approvals.attachments.add')}
          </Button>
        </div>
      ) : null}
    </Section>
  )
}

function CommentBox({ approval }) {
  const { t } = useTranslation()
  const { comment } = useApprovalActions(approval.id)
  const [text, setText] = useState('')
  const [missing, setMissing] = useState(false)
  if (!approval.can?.comment) return null
  const errors = formErrors(comment.error, ['comment'])
  return (
    <form
      noValidate
      aria-label={t('approvals.comment.title')}
      onSubmit={(event) => {
        event.preventDefault()
        if (!text.trim()) return setMissing(true)
        setMissing(false)
        comment.mutate(
          { comment: text.trim() },
          {
            onSuccess: () => {
              setText('')
              toast.success(t('approvals.toast.commented'))
            },
          },
        )
      }}
      className="flex flex-col gap-2"
    >
      {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
      <TextAreaField
        label={t('approvals.comment.title')}
        value={text}
        maxLength={2000}
        onChange={(event) => setText(event.target.value)}
        error={missing ? t('approvals.comment.required') : errors.fields.comment}
        rows={2}
      />
      <div className="flex justify-end">
        <Button type="submit" loading={comment.isPending}>
          {t('approvals.comment.post')}
        </Button>
      </div>
    </form>
  )
}

/**
 * One request in full (APR-03, APR-04): summary, amount, requester and
 * step, why it came this way, its time limits, the approvers, the actions
 * the user may take, attachments, comments and the history.
 */
export function ApprovalDetail({ id, decisive = true, onBack }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const detail = useApprovalDetail(id)
  const { refresh } = useApprovalActions(id)
  const approval = detail.data

  if (detail.isPending) {
    return (
      <p role="status" className="px-4 py-6 text-center text-ink-muted">
        {t('common.loading')}
      </p>
    )
  }
  if (detail.isError || !approval) {
    return (
      <Alert
        tone="danger"
        title={detail.error?.status === 404 ? t('approvals.detail.notFound') : errorMessage(detail.error)}
        action={onBack ? <Button onClick={onBack}>{t('approvals.detail.back')}</Button> : null}
      />
    )
  }

  const status = approvalStatus(approval, t)
  const amount = approval.document?.amount
  const from = approval.my_assignment?.delegated_from

  return (
    <article aria-label={t('approvals.detail.label')} className="flex flex-col gap-5 rounded-lg border border-border bg-surface-200 p-5">
      {onBack ? (
        <div>
          <Button variant="ghost" icon="back" className="px-2" onClick={onBack}>
            {t('approvals.detail.back')}
          </Button>
        </div>
      ) : null}
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex min-w-0 flex-col">
          <span className="text-caption text-ink-muted">
            {approval.document?.type_label}
            {approval.document?.number ? ` · ${approval.document.number}` : ''}
          </span>
          <h2 className="text-h2 text-ink">{approval.document?.title || approval.document?.number}</h2>
        </div>
        {status ? <StatusBadge tone={status.tone}>{status.label}</StatusBadge> : null}
      </header>

      {approval.blocked_label ? <Alert tone="warning" title={approval.blocked_label} /> : null}
      {from && approval.status === 'pending' ? (
        <Alert tone="info" title={t('approvals.detail.delegatedTitle', { name: from.name ?? t('approvals.someone') })}>
          {t('approvals.detail.delegatedText')}
        </Alert>
      ) : null}

      {amount ? (
        <div className="flex flex-wrap items-baseline justify-between gap-2 border-y border-border py-3">
          <span className="text-body-lg text-ink">{approval.document?.amount_label ?? t('approvals.detail.amount')}</span>
          <Money amount={amount.amount_minor} currency={amount.currency} size="lg" />
        </div>
      ) : null}

      <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Fact term={t('approvals.detail.requestedBy')}>{approval.requester?.name ?? t('approvals.someone')}</Fact>
        {approval.company?.name ? <Fact term={t('approvals.detail.company')}>{approval.company.name}</Fact> : null}
        {approval.step ? (
          <Fact term={t('approvals.detail.step')}>
            {t('approvals.detail.stepOf', {
              index: formatInteger(approval.step.index, locale),
              count: formatInteger(approval.step.count, locale),
              name: approval.step.name ?? '',
            })}
          </Fact>
        ) : null}
        {approval.received_at ? <Fact term={t('approvals.detail.received')}>{formatCompanyTime(approval.received_at, locale, approval.company)}</Fact> : null}
        {approval.status === 'pending' && approval.waiting_since ? (
          <Fact term={t('approvals.detail.waitingSince')}>{formatCompanyTime(approval.waiting_since, locale, approval.company)}</Fact>
        ) : null}
        {approval.status === 'pending' && approval.due_at ? (
          <Fact term={t('approvals.detail.due')}>
            <span className={approval.overdue ? 'text-danger' : undefined}>{formatCompanyTime(approval.due_at, locale, approval.company)}</span>
          </Fact>
        ) : null}
        {approval.decided_at ? <Fact term={t('approvals.detail.decided')}>{formatCompanyTime(approval.decided_at, locale, approval.company)}</Fact> : null}
      </dl>

      {approval.document_link?.startsWith('/') && !approval.document_link.startsWith('//') ? (
        <Link to={approval.document_link} className="font-medium text-primary hover:text-primary-hover">
          {t('approvals.detail.openDocument')}
        </Link>
      ) : null}

      <WhyThisRoute approval={approval} />
      <ApprovalActions key={approval.id} approval={approval} decisive={decisive} />
      <Approvers approvers={approval.approvers} />
      <Attachments approval={approval} onUploaded={refresh} />
      <CommentBox approval={approval} />
      <History history={approval.history} company={approval.company} />
    </article>
  )
}
