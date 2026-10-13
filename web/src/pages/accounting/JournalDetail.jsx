import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { usePermissions } from '@/auth/usePermissions'
import { Alert, Button, Card, DataTable, Dialog, StatusBadge, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { Amount, Detail } from './AccountingParts'
import { JOURNAL_TONES, sourceLink } from './accountingData'

/**
 * ACC-03 to ACC-05: one journal: its lines in transaction and base
 * currency, where it came from (drill-down to the source), its flow
 * (submit, then approval in the approvals inbox), and reversal once posted.
 */
export default function JournalDetail() {
  const { t } = useTranslation()
  const { journalId } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = usePermissions()
  const [reversing, setReversing] = useState(false)
  const [reverseDate, setReverseDate] = useState('')
  const query = useQuery({ queryKey: ['accounting-journal', journalId], queryFn: () => api.get(`accounting/journals/${journalId}`) })
  const refresh = (result) => {
    queryClient.setQueryData(['accounting-journal', result.data.id], result)
    return queryClient.invalidateQueries({ queryKey: ['accounting-journals'] })
  }
  const submit = useMutation({ mutationFn: () => api.post(`accounting/journals/${journalId}/submit`), onSuccess: refresh })
  const cancel = useMutation({ mutationFn: () => api.post(`accounting/journals/${journalId}/cancel`, { reason: t('accounting.journalDetail.cancelReason') }), onSuccess: refresh })
  const post = useMutation({ mutationFn: () => api.post(`accounting/journals/${journalId}/post`), onSuccess: refresh })
  const reverse = useMutation({
    mutationFn: () => api.post(`accounting/journals/${journalId}/reverse`, reverseDate ? { date: reverseDate } : {}),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: ['accounting-journal', journalId] })
      setReversing(false)
      navigate(`/accounting/journals/${result.data.id}`)
    },
  })
  const journal = query.data?.data
  const error = submit.error ?? cancel.error ?? post.error

  if (!journal) return query.isError ? <Alert tone="danger" title={errorMessage(query.error)} /> : <p className="text-ink-muted">{t('accounting.loading')}</p>

  const link = sourceLink(journal.source)
  const columns = [
    { key: 'account', label: t('accounting.columns.account'), render: (line) => (line.account ? `${line.account.code} · ${line.account.name}` : '') },
    { key: 'description', label: t('accounting.columns.description'), render: (line) => line.description ?? '' },
    { key: 'debit', label: t('accounting.columns.debit'), align: 'end', render: (line) => (line.debit.amount_minor !== '0' ? <Amount value={line.debit} /> : null) },
    { key: 'credit', label: t('accounting.columns.credit'), align: 'end', render: (line) => (line.credit.amount_minor !== '0' ? <Amount value={line.credit} /> : null) },
    { key: 'base', label: t('accounting.journalDetail.base'), align: 'end', render: (line) => <Amount value={line.base_debit.amount_minor !== '0' ? line.base_debit : line.base_credit} /> },
    { key: 'dimensions', label: t('accounting.journalDetail.dimensions'), render: (line) => [line.party?.name, line.cost_centre?.name, line.department?.name, line.project?.name].filter(Boolean).join(' · ') },
  ]

  return (
    <>
      <PageHeader
        eyebrow={t(`accounting.journalKinds.${journal.kind}`)}
        title={journal.number ?? t('accounting.journalDetail.draftTitle')}
        actions={
          <>
            {journal.editable && can('accounting.journal.create') ? (
              <>
                <Button onClick={() => navigate(`/accounting/journals/${journal.id}/edit`)}>{t('accounting.journalDetail.edit')}</Button>
                <Button variant="ghost" loading={cancel.isPending} onClick={() => cancel.mutate()}>{t('accounting.journalDetail.cancel')}</Button>
                <Button variant="primary" loading={submit.isPending} onClick={() => submit.mutate()}>{t('accounting.journalDetail.submit')}</Button>
              </>
            ) : null}
            {journal.status === 'approved' && can('accounting.journal.approve') ? (
              <Button variant="primary" loading={post.isPending} onClick={() => post.mutate()}>{t('accounting.journalDetail.post')}</Button>
            ) : null}
            {journal.reversible && can('accounting.journal.reverse') ? (
              <Button onClick={() => setReversing(true)}>{t('accounting.journalDetail.reverse')}</Button>
            ) : null}
          </>
        }
      />
      {error ? <Alert tone="danger" title={errorMessage(error)} /> : null}
      {journal.post_error ? <Alert tone="warning" title={t('accounting.journalDetail.postError')} /> : null}
      {journal.status === 'pending' ? <Alert tone="info" title={t('accounting.journalDetail.pending')} action={<Link to="/approvals" className="text-label text-primary hover:text-primary-hover">{t('accounting.journalDetail.openApprovals')}</Link>} /> : null}
      <Card>
        <dl className="grid gap-4 sm:grid-cols-4">
          <Detail label={t('accounting.columns.status')}><StatusBadge tone={JOURNAL_TONES[journal.status]}>{t(`accounting.journalStatuses.${journal.status}`)}</StatusBadge></Detail>
          <Detail label={t('accounting.columns.date')}><span className="tabular-nums">{journal.date}</span></Detail>
          <Detail label={t('accounting.columns.total')}><Amount value={journal.total} /></Detail>
          <Detail label={t('accounting.journalDetail.source')}>
            {link ? <Link to={link} className="text-primary hover:text-primary-hover">{t(`accounting.sources.${journal.source.type}`, { defaultValue: journal.source.type })}</Link> : journal.source ? journal.source.type : t('accounting.journalDetail.manual')}
          </Detail>
          <Detail label={t('accounting.columns.memo')}>{journal.memo ?? '—'}</Detail>
          <Detail label={t('accounting.columns.reference')}>{journal.reference ?? '—'}</Detail>
          <Detail label={t('accounting.journalDetail.createdBy')}>{journal.created_by?.name ?? '—'}</Detail>
          <Detail label={t('accounting.journalDetail.reversal')}>
            {journal.reversed_by_journal_id ? <Link to={`/accounting/journals/${journal.reversed_by_journal_id}`} className="text-primary">{t('accounting.journalDetail.openReversal')}</Link>
              : journal.reverses_journal_id ? <Link to={`/accounting/journals/${journal.reverses_journal_id}`} className="text-primary">{t('accounting.journalDetail.openOriginal')}</Link> : '—'}
          </Detail>
        </dl>
      </Card>
      <DataTable caption={t('accounting.journalForm.lines')} columns={columns} rows={journal.lines} />
      <Dialog
        open={reversing}
        title={t('accounting.journalDetail.reverseTitle')}
        onClose={() => setReversing(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setReversing(false)}>{t('accounting.cancel')}</Button>
            <Button variant="primary" loading={reverse.isPending} onClick={() => reverse.mutate()}>{t('accounting.journalDetail.reverse')}</Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {reverse.isError ? <Alert tone="danger" title={errorMessage(reverse.error)} /> : null}
          <p>{t('accounting.journalDetail.reverseText')}</p>
          <TextField type="date" label={t('accounting.journalDetail.reverseDate')} help={t('accounting.journalDetail.reverseDateHelp')} value={reverseDate} onChange={(event) => setReverseDate(event.target.value)} />
        </div>
      </Dialog>
    </>
  )
}
