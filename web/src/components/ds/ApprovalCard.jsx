import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { Button } from './Button'
import { Icon } from './Icon'
import { Money } from './Money'
import { StatusBadge } from './StatusBadge'

function Meta({ term, children }) {
  return (
    <div>
      <dt className="text-caption text-ink-muted">{term}</dt>
      <dd className="text-body">{children}</dd>
    </div>
  )
}

/** One item in the approvals inbox. */
export function ApprovalCard({
  docType,
  number,
  title,
  amount,
  currency,
  requester,
  branch,
  onBehalfOf,
  due,
  overdue = false,
  escalatesIn,
  onApprove,
  onReject,
  onReturn,
  className,
}) {
  const { t } = useTranslation()
  return (
    <article
      className={cn('flex flex-col gap-3 rounded-lg border border-border bg-surface-200 px-5 py-4 text-ink', overdue && 'border-danger', className)}
    >
      <header className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <div className="text-caption text-ink-muted">
            {docType} · {number}
          </div>
          <div className="text-h3">{title}</div>
        </div>
        {amount != null && currency ? <Money amount={amount} currency={currency} /> : null}
      </header>
      <dl className="flex flex-wrap gap-x-6 gap-y-1">
        <Meta term={t('ds.approval.requestedBy')}>{requester}</Meta>
        {branch ? <Meta term={t('ds.approval.branch')}>{branch}</Meta> : null}
        {onBehalfOf ? <Meta term={t('ds.approval.delegatedFrom')}>{onBehalfOf}</Meta> : null}
      </dl>
      {overdue || due ? (
        <div className="flex items-center gap-2 text-caption text-ink-muted">
          <Icon name="clock" size={14} />
          {overdue ? (
            <StatusBadge tone="danger">{t('ds.approval.overdue', { when: escalatesIn ?? t('ds.approval.soon') })}</StatusBadge>
          ) : (
            <span>{due}</span>
          )}
        </div>
      ) : null}
      <footer className="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
        <Button variant="ghost" onClick={onReturn}>
          {t('ds.approval.return')}
        </Button>
        <Button variant="danger" onClick={onReject}>
          {t('ds.approval.reject')}
        </Button>
        <Button variant="primary" onClick={onApprove}>
          {t('ds.approval.approve')}
        </Button>
      </footer>
    </article>
  )
}
