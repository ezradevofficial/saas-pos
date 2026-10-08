import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Select } from '@/components/ds'
import { useApprovalActions } from '@/lib/approvals'
import { peopleOptions, useReassignCandidates } from './approvalData'
import { TextAreaField } from './TextAreaField'

/**
 * The decisions on a request (APR-03, APR-06), each offered only when the
 * API's `can` allows it: Approve (the screen's decisive action unless items
 * are chosen for bulk approval), Reject with a reason, Return for changes to
 * a passed stage, Request more information, and Reassign for administrators.
 * Reasons are asked inline, under the buttons.
 */
export function ApprovalActions({ approval, decisive = true }) {
  const { t } = useTranslation()
  const actions = useApprovalActions(approval.id)
  const [mode, setMode] = useState(null)
  const [text, setText] = useState('')
  const [target, setTarget] = useState('')
  const [fromUser, setFromUser] = useState('')
  const [toUser, setToUser] = useState('')
  const [missing, setMissing] = useState(null)
  const can = approval.can ?? {}
  const label = `${approval.document?.type_label} ${approval.document?.number ?? ''}`.trim()

  // Approvers waiting at the current step: whose place a reassignment takes.
  const pending = (approval.approvers ?? []).filter((entry) => entry.status === 'pending' && entry.step === approval.step?.index)
  const users = useReassignCandidates(approval.id, { enabled: mode === 'reassign' })

  const open = (next) => {
    setMode(next)
    setText('')
    setMissing(null)
    setTarget(approval.return_targets?.[0]?.node_id ?? '')
    setFromUser(pending[0]?.user?.id ?? '')
    setToUser('')
    for (const mutation of Object.values(actions)) mutation.reset?.()
  }

  const done = (message) => () => {
    toast.success(message)
    setMode(null)
    setText('')
  }

  const run = (mutation, body, message) => mutation.mutate(body, { onSuccess: done(message) })

  const approve = () => {
    if (approval.require_reason && mode !== 'approve') return open('approve')
    if (approval.require_reason && !text.trim()) return setMissing('text')
    run(actions.approve, text.trim() ? { comment: text.trim() } : {}, t('approvals.toast.approved', { name: label }))
  }

  const submit = () => {
    setMissing(null)
    const reason = text.trim()
    if (mode === 'approve') return approve()
    if (mode === 'reassign') {
      if (!toUser) return setMissing('toUser')
      return run(actions.reassign, { from_user_id: fromUser || null, to_user_id: toUser, ...(reason ? { reason } : {}) }, t('approvals.toast.reassigned', { name: label }))
    }
    if (!reason) return setMissing('text')
    if (mode === 'reject') return run(actions.reject, { comment: reason }, t('approvals.toast.rejected', { name: label }))
    if (mode === 'return') {
      if (!target) return setMissing('target')
      return run(actions.return, { node: target, reason }, t('approvals.toast.returned', { name: label }))
    }
    if (mode === 'requestInfo') return run(actions.requestInfo, { comment: reason }, t('approvals.toast.infoRequested'))
  }

  const current = { approve: actions.approve, reject: actions.reject, return: actions.return, requestInfo: actions.requestInfo, reassign: actions.reassign }[mode]
  const errors = formErrors(current?.error ?? (mode === null ? actions.approve.error : null), ['comment', 'reason', 'node', 'to_user_id', 'from_user_id'])
  const textError = missing === 'text' ? t(`approvals.form.${mode}.required`) : (errors.fields.comment ?? errors.fields.reason)

  const anyAction = can.approve || can.reject || can.return || can.request_info || can.reassign
  if (!anyAction) return null

  const form = {
    approve: { title: t('approvals.form.approve.title', { name: label }), confirm: t('approvals.actions.approve'), variant: decisive ? 'pay' : 'primary' },
    reject: { title: t('approvals.form.reject.title', { name: label }), confirm: t('approvals.actions.reject'), variant: 'danger' },
    return: { title: t('approvals.form.return.title', { name: label }), confirm: t('approvals.actions.return'), variant: 'secondary' },
    requestInfo: { title: t('approvals.form.requestInfo.title'), confirm: t('approvals.form.requestInfo.confirm'), variant: 'secondary' },
    reassign: { title: t('approvals.form.reassign.title', { name: label }), confirm: t('approvals.actions.reassign'), variant: 'secondary' },
  }[mode]

  return (
    <section aria-label={t('approvals.detail.actions')} className="flex flex-col gap-3 border-t border-border pt-4">
      <div className="flex flex-wrap gap-2">
        {can.approve ? (
          <Button variant={decisive && mode !== 'approve' ? 'pay' : 'primary'} loading={actions.approve.isPending} onClick={approve} aria-expanded={approval.require_reason ? mode === 'approve' : undefined}>
            {t('approvals.actions.approve')}
          </Button>
        ) : null}
        {can.reject ? (
          <Button variant="danger" aria-expanded={mode === 'reject'} onClick={() => open('reject')}>
            {t('approvals.actions.reject')}
          </Button>
        ) : null}
        {can.return && approval.return_targets?.length ? (
          <Button aria-expanded={mode === 'return'} onClick={() => open('return')}>
            {t('approvals.actions.return')}
          </Button>
        ) : null}
        {can.request_info ? (
          <Button variant="ghost" aria-expanded={mode === 'requestInfo'} onClick={() => open('requestInfo')}>
            {t('approvals.actions.requestInfo')}
          </Button>
        ) : null}
        {can.reassign ? (
          <Button variant="ghost" aria-expanded={mode === 'reassign'} onClick={() => open('reassign')}>
            {t('approvals.actions.reassign')}
          </Button>
        ) : null}
      </div>

      {mode === null && actions.approve.error ? <Alert tone="danger" title={errors.form ?? errors.fields.comment} /> : null}

      {form ? (
        <form
          noValidate
          aria-label={form.title}
          onSubmit={(event) => {
            event.preventDefault()
            submit()
          }}
          className="flex flex-col gap-3 rounded-md border border-border bg-surface-100 p-4"
        >
          <h3 className="text-h3 text-ink">{form.title}</h3>
          {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
          {mode === 'return' ? (
            <Select
              label={t('approvals.form.return.target')}
              options={(approval.return_targets ?? []).map((entry) => ({ value: entry.node_id, label: entry.name ?? entry.node_id }))}
              value={target}
              onChange={(event) => setTarget(event.target.value)}
              error={missing === 'target' ? t('approvals.form.return.targetRequired') : errors.fields.node}
              required
            />
          ) : null}
          {mode === 'reassign' ? (
            <>
              {pending.length > 1 ? (
                <Select
                  label={t('approvals.form.reassign.from')}
                  options={pending.map((entry) => ({ value: entry.user?.id, label: entry.user?.name ?? t('approvals.someone') }))}
                  value={fromUser}
                  onChange={(event) => setFromUser(event.target.value)}
                  error={errors.fields.from_user_id}
                  required
                />
              ) : pending.length === 1 ? (
                <p className="text-body text-ink-muted">{t('approvals.form.reassign.replaces', { name: pending[0].user?.name ?? t('approvals.someone') })}</p>
              ) : (
                <p className="text-body text-ink-muted">{t('approvals.form.reassign.nobody')}</p>
              )}
              <Select
                label={t('approvals.form.reassign.to')}
                placeholder={users.isPending ? t('common.loading') : t('approvals.form.reassign.choose')}
                options={peopleOptions(users.data ?? [], { exclude: [approval.requester?.id, ...pending.map((entry) => entry.user?.id)].filter(Boolean) })}
                value={toUser}
                onChange={(event) => setToUser(event.target.value)}
                error={missing === 'toUser' ? t('approvals.form.reassign.toRequired') : errors.fields.to_user_id}
                help={users.isError ? t('approvals.form.peopleUnavailable') : undefined}
                required
              />
            </>
          ) : null}
          <TextAreaField
            label={t(`approvals.form.${mode}.text`)}
            help={mode === 'approve' ? t('approvals.form.approve.help') : undefined}
            value={text}
            maxLength={2000}
            onChange={(event) => setText(event.target.value)}
            error={textError}
            required={mode !== 'reassign'}
          />
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="ghost" onClick={() => setMode(null)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" variant={form.variant} loading={current?.isPending}>
              {form.confirm}
            </Button>
          </div>
        </form>
      ) : null}
    </section>
  )
}
