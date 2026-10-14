import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { api } from '@/api/client'
import { errorMessage } from '@/api/errorMessage'
import { formErrors } from '@/api/formErrors'
import { Alert, Button, Card, DataTable, DecimalInput, Dialog, Select, TextField } from '@/components/ds'
import { PageHeader } from '@/layouts/PageHeader'
import { formatCompanyTime } from '@/lib/companyTime'
import { useLocale } from '@/lib/useLocale'
import { useCompanyOfRecord } from '@/lib/useTimeZone'
import { subtractDecimal } from '@/pages/inventory/inventoryData'
import { Amount, Back, Detail, DetailState, DocumentStatus, PrintActions, Qty } from './StoresParts'
import { isPositive, RETURN_REASONS, storesKey } from './storesData'

/** STR-06: goods back to the store against the issue's lines, with a reason. Quantities are in each line's unit. */
function ReturnPanel({ issue, onReturned }) {
  const { t } = useTranslation()
  const lines = issue.lines ?? []
  const [quantities, setQuantities] = useState({})
  const [reason, setReason] = useState('')
  const [note, setNote] = useState('')
  const chosen = lines.filter((line) => isPositive(quantities[line.id]))
  const send = useMutation({
    mutationFn: () =>
      api.post(`stores/issues/${issue.id}/returns`, {
        reason,
        ...(note.trim() ? { note: note.trim() } : {}),
        lines: chosen.map((line) => ({ issue_line_id: line.id, qty: quantities[line.id] })),
      }),
    onSuccess: (response) => onReturned(response),
  })
  const errors = formErrors(send.error, ['reason', 'note', 'lines', ...chosen.flatMap((_, index) => [`lines.${index}.qty`, `lines.${index}.issue_line_id`])])

  return (
    <Card title={t('stores.return.title')} subtitle={t('stores.return.help')}>
      <div className="flex flex-col gap-4">
        {errors.form ? <Alert tone="danger" title={errors.form} /> : null}
        {lines.map((line) => (
          <div key={line.id} className="grid gap-3 sm:grid-cols-3 sm:items-end">
            <div className="flex flex-col sm:col-span-2">
              <span className="text-ink">{line.item?.name}</span>
              <span className="text-caption text-ink-muted">{t('stores.return.issued', { qty: line.qty, unit: line.uom?.code ?? '' })}</span>
            </div>
            <DecimalInput
              label={t('stores.return.qty', { unit: line.uom?.code ?? '' })}
              value={quantities[line.id] ?? ''}
              error={errors.fields[`lines.${chosen.indexOf(line)}.qty`]}
              onChange={(value) => setQuantities((current) => ({ ...current, [line.id]: value ?? '' }))}
            />
          </div>
        ))}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label={t('stores.fields.reason')}
            placeholder={t('stores.pickers.reason')}
            options={RETURN_REASONS.map((value) => ({ value, label: t(`stores.returnReasons.${value}`) }))}
            value={reason}
            required
            error={errors.fields.reason}
            onChange={(event) => setReason(event.target.value)}
          />
          <TextField label={t('stores.fields.note')} value={note} maxLength={1000} error={errors.fields.note} onChange={(event) => setNote(event.target.value)} />
        </div>
        <div>
          <Button loading={send.isPending} disabled={!chosen.length || !reason} onClick={() => send.mutate()}>
            {t('stores.return.submit')}
          </Button>
        </div>
      </div>
    </Card>
  )
}

/**
 * STR-04 to STR-06, STR-14: one issue: its requisition, store and
 * department, its lines (issued, returned, value for value holders), its
 * returns, Acknowledge for the department, the return form, and printing
 * of the delivery note and the issue voucher.
 */
export default function IssueDetail() {
  const { t } = useTranslation()
  const locale = useLocale()
  const navigate = useNavigate()
  const { issueId } = useParams()
  const queryClient = useQueryClient()
  const companyOf = useCompanyOfRecord()
  const [acknowledging, setAcknowledging] = useState(false)
  const [note, setNote] = useState('')
  const [returning, setReturning] = useState(false)
  const key = [...storesKey, 'issue', issueId]
  const query = useQuery({ queryKey: key, queryFn: () => api.get(`stores/issues/${issueId}`) })
  const issue = query.data?.data
  const acknowledge = useMutation({
    mutationFn: () => api.post(`stores/issues/${issueId}/acknowledge`, note.trim() ? { note: note.trim() } : {}),
    onSuccess: async (response) => {
      setAcknowledging(false)
      queryClient.setQueryData(key, response)
      toast.success(t('stores.issueDetail.acknowledged'))
      await queryClient.invalidateQueries({ queryKey: storesKey })
    },
  })
  const back = <Back to="/stores/issues">{t('stores.issues.back')}</Back>

  if (!issue) return <DetailState query={query} back={back} notFound={t('stores.issueDetail.notFound')} />

  const when = (value) => (value ? formatCompanyTime(value, locale, companyOf(issue.company?.id)) : null)
  const lineColumns = [
    { key: 'line_no', label: t('stores.columns.lineNo'), numeric: true, render: (line) => line.line_no },
    {
      key: 'item',
      label: t('stores.columns.item'),
      wrap: true,
      render: (line) => (
        <span className="flex flex-col">
          <span className="text-ink">{line.item?.name}</span>
          <span className="font-mono text-caption text-ink-muted">{line.item?.code}</span>
        </span>
      ),
    },
    { key: 'qty', label: t('stores.columns.issued'), align: 'end', render: (line) => <Qty value={line.qty} unit={line.uom?.code} /> },
    { key: 'qty_returned', label: t('stores.columns.returnedBase'), align: 'end', render: (line) => <Qty value={line.qty_returned_base} /> },
    { key: 'kept', label: t('stores.columns.keptBase'), align: 'end', render: (line) => <Qty value={subtractDecimal(line.qty_base, line.qty_returned_base)} /> },
    { key: 'value', label: t('stores.columns.value'), align: 'end', render: (line) => <Amount value={line.value} /> },
  ]

  return (
    <>
      {back}
      <PageHeader
        eyebrow={t('stores.issueDetail.eyebrow')}
        title={issue.number}
        description={<DocumentStatus status={issue.status} />}
        actions={
          <div className="flex flex-wrap gap-2">
            {issue.can?.return && !returning ? <Button onClick={() => setReturning(true)}>{t('stores.issueDetail.return')}</Button> : null}
            {issue.can?.acknowledge ? (
              <Button variant="pay" onClick={() => setAcknowledging(true)}>
                {t('stores.issueDetail.acknowledge')}
              </Button>
            ) : null}
          </div>
        }
      />
      {issue.ledger === 'inventory' && !issue.valued ? <Alert tone="info" title={t('stores.issueDetail.valuing')} /> : null}
      <div className="flex flex-col gap-5">
        <Card title={t('stores.issueDetail.summary')}>
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Detail label={t('stores.fields.requisition')}>
              <Link to={`/stores/requisitions/${issue.requisition?.id}`} className="text-primary tabular-nums hover:text-primary-hover">
                {issue.requisition?.number}
              </Link>
            </Detail>
            <Detail label={t('stores.fields.store')}>{issue.location?.name}</Detail>
            <Detail label={t('stores.fields.department')}>{issue.department?.name}</Detail>
            {issue.cost_centre ? <Detail label={t('stores.fields.costCentre')}>{issue.cost_centre.name}</Detail> : null}
            <Detail label={t('stores.fields.issuedBy')}>
              <span className="flex flex-col">
                <span>{issue.issued_by?.name}</span>
                <span className="text-caption text-ink-muted tabular-nums">{when(issue.issued_at)}</span>
              </span>
            </Detail>
            {issue.acknowledged_by ? (
              <Detail label={t('stores.fields.acknowledgedBy')}>
                <span className="flex flex-col">
                  <span>{issue.acknowledged_by.name}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{when(issue.acknowledged_at)}</span>
                  {issue.acknowledge_note ? <span className="text-caption text-ink-muted">{issue.acknowledge_note}</span> : null}
                </span>
              </Detail>
            ) : null}
            <Detail label={t('stores.fields.ledger')}>{t(`stores.ledgers.${issue.ledger}`)}</Detail>
            {issue.value ? (
              <Detail label={t('stores.columns.value')}>
                <Amount value={issue.value} />
              </Detail>
            ) : null}
            {issue.note ? <Detail label={t('stores.fields.note')}>{issue.note}</Detail> : null}
          </dl>
        </Card>
        <Card title={t('stores.lines.title')}>
          <DataTable caption={t('stores.lines.title')} columns={lineColumns} rows={issue.lines ?? []} />
        </Card>
        {returning ? (
          <ReturnPanel
            issue={issue}
            onReturned={async (response) => {
              toast.success(t('stores.return.done', { number: response?.data?.number ?? '' }))
              await queryClient.invalidateQueries({ queryKey: storesKey })
              navigate(`/stores/returns/${response.data.id}`)
            }}
          />
        ) : null}
        {issue.returns?.length ? (
          <Card title={t('stores.issueDetail.returns')}>
            <ul className="flex flex-col gap-2">
              {issue.returns.map((entry) => (
                <li key={entry.id} className="flex flex-wrap items-center gap-3">
                  <Link to={`/stores/returns/${entry.id}`} className="font-medium text-primary tabular-nums hover:text-primary-hover">
                    {entry.number}
                  </Link>
                  <span className="text-body text-ink">{t(`stores.returnReasons.${entry.reason}`)}</span>
                  <span className="text-caption text-ink-muted tabular-nums">{when(entry.returned_at)}</span>
                </li>
              ))}
            </ul>
          </Card>
        ) : null}
        <PrintActions issueId={issue.id} />
      </div>
      <Dialog
        open={acknowledging}
        title={t('stores.issueDetail.acknowledgeTitle', { number: issue.number })}
        onClose={() => setAcknowledging(false)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setAcknowledging(false)}>
              {t('stores.actions.keep')}
            </Button>
            <Button variant="pay" loading={acknowledge.isPending} onClick={() => acknowledge.mutate()}>
              {t('stores.issueDetail.acknowledge')}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4 pt-1">
          <p>{t('stores.issueDetail.acknowledgeHelp')}</p>
          {acknowledge.isError ? <Alert tone="danger" title={errorMessage(acknowledge.error)} /> : null}
          <TextField label={t('stores.fields.note')} value={note} maxLength={1000} onChange={(event) => setNote(event.target.value)} />
        </div>
      </Dialog>
    </>
  )
}
