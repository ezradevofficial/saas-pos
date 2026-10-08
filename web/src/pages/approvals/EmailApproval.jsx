import { useMutation, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { useAuth } from '@/auth/AuthProvider'
import { Alert, Button, Money } from '@/components/ds'
import { AuthPage } from '@/pages/auth/AuthPage'
import { TextAreaField } from './TextAreaField'

/** Where a "sign in to decide" answer sends the user: the request itself, after signing in when needed. */
function signInPath(approvalId, signedIn) {
  const target = approvalId ? `/approvals/${approvalId}` : '/approvals'
  return signedIn ? target : `/sign-in?next=${encodeURIComponent(target)}`
}

/**
 * APR-08: the page an approval email links to (signed out or in). Opening
 * it only reads what the link would do; the decision is made when the user
 * confirms. A used, expired or no longer waiting link, or a role that needs
 * two-step sign-in, asks the user to sign in and decide in the app.
 */
export default function EmailApproval() {
  const { t } = useTranslation()
  const { token } = useParams()
  const navigate = useNavigate()
  const { token: sessionToken } = useAuth()
  const [comment, setComment] = useState('')
  const [missing, setMissing] = useState(false)
  const [result, setResult] = useState(null)
  const link = useQuery({
    queryKey: ['approval-email', token],
    queryFn: () => api.get(`approvals/email/${token}`),
    select: (response) => response?.data ?? null,
    enabled: result === null,
    staleTime: Infinity,
  })
  const confirm = useMutation({
    mutationFn: (body) => api.post(`approvals/email/${token}`, body),
    onSuccess: (response) => setResult({ status: 'done', ...(response?.data ?? {}) }),
    onError: (error) => {
      if (error?.code === 'sign_in_required') setResult({ status: 'sign_in_required', message: error.message, approval_id: error.data?.approval_id ?? link.data?.approval_id })
    },
  })

  const data = link.data
  const signedIn = Boolean(sessionToken)
  const goSignIn = (approvalId) => navigate(signInPath(approvalId, signedIn))

  if (result?.status === 'done') {
    const rejected = data?.action === 'reject'
    return (
      <AuthPage title={t(rejected ? 'approvals.email.rejectedTitle' : 'approvals.email.approvedTitle')} intro={t('approvals.email.doneText')}>
        <div className="flex flex-col gap-4">
          <Alert tone="success" title={t(rejected ? 'approvals.email.rejectedTitle' : 'approvals.email.approvedTitle')} />
          <div className="flex justify-end">
            <Button onClick={() => goSignIn(result.approval_id ?? data?.approval_id)}>{t('approvals.email.openApprovals')}</Button>
          </div>
        </div>
      </AuthPage>
    )
  }

  if (link.isPending) {
    return (
      <AuthPage title={t('approvals.email.title')}>
        <p role="status" className="text-ink-muted">
          {t('common.loading')}
        </p>
      </AuthPage>
    )
  }

  if (link.isError) {
    return (
      <AuthPage title={t('approvals.email.title')}>
        <div className="flex flex-col gap-4">
          <Alert tone="danger" title={link.error?.status === 404 ? t('approvals.email.invalid') : errorMessage(link.error)} />
          <div className="flex justify-end">
            <Button variant="primary" onClick={() => goSignIn(null)}>
              {signedIn ? t('approvals.email.openApprovals') : t('approvals.email.signIn')}
            </Button>
          </div>
        </div>
      </AuthPage>
    )
  }

  if (result?.status === 'sign_in_required' || data?.status === 'sign_in_required') {
    const message = result?.message ?? data?.message
    const approvalId = result?.approval_id ?? data?.approval_id
    return (
      <AuthPage title={t('approvals.email.signInTitle')}>
        <div className="flex flex-col gap-4">
          <Alert tone="info" title={message ?? t('approvals.email.signInText')} />
          <div className="flex justify-end">
            <Button variant="primary" onClick={() => goSignIn(approvalId)}>
              {signedIn ? t('approvals.email.openApproval') : t('approvals.email.signInToDecide')}
            </Button>
          </div>
        </div>
      </AuthPage>
    )
  }

  const approval = data?.approval ?? {}
  const rejecting = data?.action === 'reject'
  const needsReason = rejecting || Boolean(approval.require_reason)
  const errors = formErrors(confirm.error?.code === 'sign_in_required' ? null : confirm.error, ['comment'])

  const submit = () => {
    if (needsReason && !comment.trim()) return setMissing(true)
    setMissing(false)
    confirm.mutate(comment.trim() ? { comment: comment.trim() } : {})
  }

  return (
    <AuthPage title={t(rejecting ? 'approvals.email.confirmReject' : 'approvals.email.confirmApprove')} intro={t('approvals.email.intro')}>
      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          submit()
        }}
        className="flex flex-col gap-4"
      >
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        <div className="flex flex-col gap-1">
          <span className="text-caption text-ink-muted">
            {approval.document_type_label}
            {approval.document_number ? ` · ${approval.document_number}` : ''}
          </span>
          <span className="text-h3 text-ink">{approval.document_title || approval.document_number}</span>
          {approval.requester || approval.step ? (
            <span className="text-caption text-ink-muted">
              {[approval.requester ? t('approvals.email.requestedBy', { name: approval.requester }) : null, approval.step].filter(Boolean).join(' · ')}
            </span>
          ) : null}
        </div>
        {approval.amount ? (
          <div className="flex items-baseline justify-between gap-2 border-y border-border py-3">
            <span className="text-ink">{t('approvals.detail.amount')}</span>
            <Money amount={approval.amount.amount_minor} currency={approval.amount.currency} size="lg" />
          </div>
        ) : null}
        <TextAreaField
          label={needsReason ? t('approvals.email.reason') : t('approvals.email.comment')}
          value={comment}
          maxLength={2000}
          onChange={(event) => setComment(event.target.value)}
          error={missing ? t('approvals.email.reasonRequired') : errors.fields.comment}
          required={needsReason}
        />
        <div className="flex justify-end">
          <Button type="submit" variant={rejecting ? 'danger' : 'pay'} loading={confirm.isPending}>
            {t(rejecting ? 'approvals.email.reject' : 'approvals.email.approve')}
          </Button>
        </div>
      </form>
    </AuthPage>
  )
}
