import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { errorMessage } from '@/api/errorMessage'
import { Alert, Button, Checkbox, ExportMenu, Icon, Money, Select, StatusBadge, TextField } from '@/components/ds'
import { useCompanies } from '@/layouts/companySelection'
import { approvalStatus, useBulkApprove } from '@/lib/approvals'
import { formatInteger } from '@/lib/format'
import { useLocale } from '@/lib/useLocale'
import { useServerList } from '@/lib/useServerList'
import { cn } from '@/lib/utils'
import { dueHint, formatCompanyTime, TABS, useDocumentTypeOptions } from './approvalData'

const SORTS = ['due', '-received', 'received']

/** Export columns (EXP-01), in the API's ApprovalList keys. */
const EXPORT_COLUMNS = ['received_at', 'type', 'number', 'title', 'amount', 'step', 'requester', 'status', 'due_at']

function ApprovalItem({ item, selected, checked, onCheck, onOpen }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const status = approvalStatus(item, t)
  const amount = item.document?.amount
  const hint = dueHint(item, t, locale)
  const label = `${item.document?.type_label} ${item.document?.number ?? ''}`.trim()
  const from = item.my_assignment?.delegated_from

  return (
    <li
      className={cn(
        'flex items-start gap-3 rounded-lg border bg-surface-200 px-4 py-3 transition-colors',
        selected ? 'border-ink' : 'border-border hover:border-border-strong',
      )}
    >
      {item.can?.bulk_approve ? (
        <Checkbox
          checked={checked}
          onChange={(event) => onCheck(event.target.checked)}
          label={<span className="sr-only">{t('approvals.list.select', { name: label })}</span>}
          className="pt-1"
        />
      ) : null}
      <button
        type="button"
        onClick={onOpen}
        aria-current={selected ? 'true' : undefined}
        className="flex min-w-0 flex-1 flex-col gap-2 rounded-md text-left"
      >
        <span className="flex w-full items-start justify-between gap-3">
          <span className="flex min-w-0 flex-col">
            <span className="text-caption text-ink-muted">
              {item.document?.type_label}
              {item.document?.number ? ` · ${item.document.number}` : ''}
            </span>
            <span className="text-h3 text-ink">{item.document?.title || item.document?.number}</span>
          </span>
          {status ? <StatusBadge tone={status.tone}>{status.label}</StatusBadge> : null}
        </span>
        <span className="flex w-full flex-wrap items-end justify-between gap-x-4 gap-y-1">
          <span className="flex min-w-0 flex-col gap-1 text-caption text-ink-muted">
            <span>
              {[item.requester?.name, item.company?.name, item.step?.name].filter(Boolean).join(' · ')}
            </span>
            {from && item.status === 'pending' ? <span>{t('approvals.list.onBehalf', { name: from.name ?? t('approvals.someone') })}</span> : null}
            {item.status === 'pending' && item.waiting_since ? (
              <span>{t('approvals.hint.waitingSince', { when: formatCompanyTime(item.waiting_since, locale, item.company) })}</span>
            ) : null}
            {hint ? (
              <span className={cn('flex items-center gap-1', item.overdue && 'text-danger')}>
                <Icon name="clock" size={14} />
                {hint}
              </span>
            ) : null}
          </span>
          {amount ? (
            <span className="flex flex-col items-end">
              <span className="text-caption text-ink-muted">{item?.document?.amount_label ?? t('approvals.list.amount')}</span>
              <Money amount={amount.amount_minor} currency={amount.currency} />
            </span>
          ) : null}
        </span>
      </button>
    </li>
  )
}

/**
 * The inbox list (APR-04): search, filters, sort and export through the
 * list framework (useServerList), shown as cards as in the design; a
 * checkbox on each item the user may approve in bulk, and "Approve all"
 * for the chosen ones, reporting any that failed.
 */
export function ApprovalList({ tab, selectedId, onOpen, onSelectionChange, onFirst }) {
  const { t } = useTranslation()
  const locale = useLocale()
  const { companies } = useCompanies()
  const list = useServerList({
    id: 'approvals',
    endpoint: 'approvals',
    queryKey: ['approvals', 'list', tab],
    params: TABS[tab],
    filters: { type: '', company: '', overdue: '' },
    defaultSort: 'due',
    defaultPerPage: 25,
    columns: EXPORT_COLUMNS.map((key) => ({ key, label: key })),
  })
  const rows = list.rows
  const typeOptions = useDocumentTypeOptions(rows)
  const [checked, setChecked] = useState([])
  const [failures, setFailures] = useState([])
  const bulk = useBulkApprove()

  const approvable = rows.filter((row) => row.can?.bulk_approve)
  // Only items still on screen and still approvable stay chosen.
  const chosen = checked.filter((id) => approvable.some((row) => row.id === id))

  useEffect(() => {
    onSelectionChange?.(chosen.length)
  }, [chosen.length, onSelectionChange])
  const firstId = rows[0]?.id ?? null
  useEffect(() => {
    onFirst?.(firstId)
  }, [firstId, onFirst])

  const toggle = (id, on) => setChecked((current) => (on ? [...new Set([...current, id])] : current.filter((entry) => entry !== id)))
  const allChosen = approvable.length > 0 && chosen.length === approvable.length

  const approveAll = () => {
    setFailures([])
    bulk.mutate(
      { ids: chosen },
      {
        onSuccess: (response) => {
          const approved = response?.data?.approved ?? []
          const failed = response?.data?.failed ?? []
          setChecked((current) => current.filter((id) => !approved.includes(id)))
          setFailures(failed)
          if (approved.length) toast.success(t('approvals.bulk.done', { count: approved.length, formatted: formatInteger(approved.length, locale) }))
          if (failed.length) toast.error(t('approvals.bulk.someFailed', { count: failed.length, formatted: formatInteger(failed.length, locale) }))
        },
        onError: (error) => toast.error(errorMessage(error)),
      },
    )
  }

  const nameOf = (id) => {
    const row = rows.find((entry) => entry.id === id)
    return row ? `${row.document?.type_label} ${row.document?.number ?? ''}`.trim() : t('approvals.bulk.unknownItem')
  }

  const total = Number(list.meta.total ?? 0)
  const empty = {
    waiting: t('approvals.empty.waiting'),
    decided: t('approvals.empty.decided'),
    all: t('approvals.empty.all'),
  }

  return (
    <section aria-label={t('approvals.list.title')} className="flex min-w-0 flex-col gap-3">
      <div className="flex flex-wrap items-end gap-3">
        <TextField
          type="search"
          label={t('approvals.list.search')}
          placeholder={t('approvals.list.searchPlaceholder')}
          prefix={<Icon name="search" className="text-ink-muted" />}
          value={list.search}
          onChange={(event) => list.setSearch(event.target.value)}
          autoComplete="off"
          className="w-full"
        />
        <Select
          label={t('approvals.filters.type')}
          options={[{ value: '', label: t('approvals.filters.allTypes') }, ...typeOptions]}
          value={list.filters.type}
          onChange={(event) => list.setFilter('type', event.target.value)}
          className="min-w-0 flex-1"
        />
        {companies.length > 1 ? (
          <Select
            label={t('approvals.filters.company')}
            options={[{ value: '', label: t('approvals.filters.allCompanies') }, ...companies.map((company) => ({ value: company.id, label: company.name }))]}
            value={list.filters.company}
            onChange={(event) => list.setFilter('company', event.target.value)}
            className="min-w-0 flex-1"
          />
        ) : null}
        <Select
          label={t('approvals.filters.due')}
          options={[
            { value: '', label: t('approvals.filters.anyTime') },
            { value: '1', label: t('approvals.filters.overdueOnly') },
          ]}
          value={list.filters.overdue}
          onChange={(event) => list.setFilter('overdue', event.target.value)}
          className="min-w-0 flex-1"
        />
        <Select
          label={t('approvals.filters.sort')}
          options={SORTS.map((value) => ({ value, label: t(`approvals.sort.${value.replace('-', 'desc_')}`) }))}
          value={SORTS.includes(list.sort) ? list.sort : 'due'}
          onChange={(event) => list.setSort(event.target.value)}
          className="min-w-0 flex-1"
        />
        <ExportMenu onExport={list.exportTo} exporting={list.exporting} />
      </div>

      {approvable.length > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-surface-200 px-4 py-2">
          <Checkbox
            checked={allChosen}
            onChange={(event) => setChecked(event.target.checked ? approvable.map((row) => row.id) : [])}
            label={chosen.length ? t('approvals.bulk.selected', { count: chosen.length, formatted: formatInteger(chosen.length, locale) }) : t('approvals.bulk.selectAll')}
          />
          <Button variant={chosen.length ? 'pay' : 'secondary'} disabled={chosen.length === 0} loading={bulk.isPending} onClick={approveAll}>
            {t('approvals.bulk.approveAll')}
          </Button>
        </div>
      ) : null}

      {failures.length ? (
        <Alert tone="danger" title={t('approvals.bulk.failedTitle', { count: failures.length, formatted: formatInteger(failures.length, locale) })}>
          <ul className="flex flex-col gap-1">
            {failures.map((failure) => (
              <li key={failure.id}>
                {nameOf(failure.id)}: {failure.message || t('errors.generic')}
              </li>
            ))}
          </ul>
        </Alert>
      ) : null}

      {list.query.isError ? (
        <Alert tone="danger" title={errorMessage(list.query.error)} action={<Button onClick={() => list.query.refetch()}>{t('common.retry')}</Button>} />
      ) : null}

      {list.query.isPending ? (
        <p role="status" className="px-4 py-6 text-center text-ink-muted">
          {t('common.loading')}
        </p>
      ) : rows.length === 0 && !list.query.isError ? (
        <p className="rounded-lg border border-border bg-surface-200 px-6 py-12 text-center text-body-lg text-ink-muted">
          {list.term || list.filters.type || list.filters.company || list.filters.overdue ? t('approvals.empty.filtered') : empty[tab]}
        </p>
      ) : (
        <ul aria-label={t('approvals.list.items')} className={cn('flex flex-col gap-3', list.query.isPlaceholderData && 'opacity-60')}>
          {rows.map((item) => (
            <ApprovalItem
              key={item.id}
              item={item}
              selected={item.id === selectedId}
              checked={chosen.includes(item.id)}
              onCheck={(on) => toggle(item.id, on)}
              onOpen={() => onOpen(item)}
            />
          ))}
        </ul>
      )}

      {total > 0 ? (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p aria-live="polite" className="text-caption text-ink-muted tabular-nums">
            {t('ds.listView.showing', { from: formatInteger(list.meta.from ?? 1, locale), to: formatInteger(list.meta.to ?? rows.length, locale), total: formatInteger(total, locale) })}
          </p>
          <nav aria-label={t('ds.listView.pages')} className="flex items-center gap-1">
            <Button variant="ghost" icon="chevronLeft" className="px-2" aria-label={t('ds.listView.previous')} disabled={list.page <= 1} onClick={() => list.setPage(list.page - 1)} />
            <span className="px-2 text-caption text-ink-muted tabular-nums">
              {t('ds.listView.pageOf', { page: formatInteger(list.page, locale), last: formatInteger(list.lastPage, locale) })}
            </span>
            <Button
              variant="ghost"
              icon="chevronRight"
              className="px-2"
              aria-label={t('ds.listView.next')}
              disabled={list.page >= list.lastPage}
              onClick={() => list.setPage(list.page + 1)}
            />
          </nav>
        </div>
      ) : null}
    </section>
  )
}
